<?php
/**
 * Transactional outbox for calls to Kantina ERP (and the CRM).
 *
 * Problem: an order is saved in Kantina's database, then the ERP must be
 * told. If the ERP is offline at that moment, the call would be lost.
 *
 * Solution: the ERP call is written to integration_outbox in the SAME
 * database transaction as the order. A delivery step then sends it:
 *   - right away (so stock is reserved while the student waits),
 *   - again later with backoff (30 s, 2 min, 10 min, 30 min …) if it failed,
 *   - in order: messages for one order (create → pay → cancel) never overtake each other.
 * The ERP endpoints are idempotent, so a message delivered twice is harmless.
 *
 * The event name says where a message goes: "erp.sales_order.create" → ERP,
 * "crm.…" → CRM. Each event needs a case in deliver(). Messages with the same
 * $ref are delivered in order, so give CRM messages their own refs.
 */
final class Outbox
{
    private const MAX_ATTEMPTS = 12;
    private const BACKOFF = [1 => 30, 2 => 120, 3 => 600];   // seconds; later attempts wait 30 min

    public static function routes(Router $r): void
    {
        // "Retry now" on the status page: ignore the backoff timer.
        $r->post('/integrations/outbox/retry', fn() => ['results' => self::process(null, 20, 6, true)], [Auth::staff()]);
    }

    public static function enqueue(string $event, string $ref, array $payload = []): void
    {
        Db::insert('integration_outbox', [
            'target' => strstr($event, '.', true) ?: 'erp',
            'event' => $event,
            'aggregate_ref' => $ref,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'status' => 'pending',
            'next_attempt_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Give up on undelivered messages for an order (e.g. it was cancelled before the ERP ever heard of it). */
    public static function drop(string $ref, string $reason): void
    {
        Db::exec("UPDATE integration_outbox SET status = 'dead', last_error = ? WHERE aggregate_ref = ? AND status IN ('pending', 'failed')", [$reason, $ref]);
    }

    /**
     * Deliver due messages. $refs limits it to certain orders (used right after
     * an order changes). Returns one result per message attempted.
     */
    public static function process(?array $refs = null, int $limit = 10, int $timeout = 6, bool $force = false): array
    {
        $due = $force ? '' : ' AND next_attempt_at <= NOW()';
        $sql = "SELECT * FROM integration_outbox WHERE status IN ('pending', 'failed')$due";
        $params = [];
        if ($refs !== null) {
            if (!$refs) {
                return [];
            }
            $sql .= ' AND aggregate_ref IN (' . implode(',', array_fill(0, count($refs), '?')) . ')';
            $params = array_values($refs);
        }
        $rows = Db::all($sql . ' ORDER BY id LIMIT ' . (int) $limit, $params);
        $results = [];
        $blocked = [];
        foreach ($rows as $row) {
            $ref = $row['aggregate_ref'];
            // Keep messages for one order in sequence.
            if (isset($blocked[$ref]) || Db::value(
                "SELECT 1 FROM integration_outbox WHERE aggregate_ref = ? AND id < ? AND status IN ('pending', 'failed') LIMIT 1",
                [$ref, $row['id']]
            )) {
                $blocked[$ref] = true;
                continue;
            }
            // Claim it so two requests never send the same message at once.
            $claimed = Db::exec(
                "UPDATE integration_outbox SET attempts = attempts + 1, next_attempt_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
                 WHERE id = ? AND status IN ('pending', 'failed') AND next_attempt_at = ?",
                [$row['id'], $row['next_attempt_at']]
            );
            if (!$claimed) {
                continue;
            }
            $row['attempts'] = (int) $row['attempts'] + 1;
            $result = self::deliver($row, $timeout);
            if ($result['status'] !== 'sent') {
                $blocked[$ref] = true;
            }
            $results[] = $result;
        }
        return $results;
    }

    /** Deliver due messages for any order — cheap enough to run on busy endpoints. */
    public static function tick(int $limit = 1): void
    {
        if (Db::value("SELECT 1 FROM integration_outbox WHERE status IN ('pending', 'failed') AND next_attempt_at <= NOW() LIMIT 1")) {
            self::process(null, $limit, 3);
        }
    }

    private static function deliver(array $row, int $timeout): array
    {
        $ref = $row['aggregate_ref'];
        $payload = json_decode($row['payload'], true) ?: [];
        $path = '/sales-orders/' . rawurlencode($ref);
        switch ($row['event']) {
            case 'erp.sales_order.create':
                $res = ErpClient::call('POST', '/sales-orders', $payload, $timeout);
                break;
            case 'erp.sales_order.pay':
                $res = ErpClient::call('POST', "$path/pay", null, $timeout);
                break;
            case 'erp.sales_order.cancel':
                $res = ErpClient::call('POST', "$path/cancel", null, $timeout);
                break;
            case 'crm.order.completed':
                $res = CrmClient::call('POST', '/orders', $payload, $timeout);
                break;
            case 'crm.ticket.create':
                $res = CrmClient::call('POST', '/tickets', $payload, $timeout);
                break;
            default:
                self::finish($row['id'], 'dead', 'Unknown event');
                return ['id' => (int) $row['id'], 'ref' => $ref, 'event' => $row['event'], 'status' => 'rejected', 'message' => 'Unknown event'];
        }
        $base = ['id' => (int) $row['id'], 'ref' => $ref, 'event' => $row['event']];

        // Cancelling something the ERP never received is fine.
        if (!$res['ok'] && $row['event'] === 'erp.sales_order.cancel' && $res['status'] === 404) {
            $res['ok'] = true;
            $res['body'] = [];
        }

        if ($res['ok']) {
            self::finish($row['id'], 'sent', null);
            $body = is_array($res['body']) ? $res['body'] : [];
            if (!empty($body['stock'])) {
                Catalog::applyStock($body['stock']);
            }
            if ($row['event'] === 'erp.sales_order.create') {
                Db::exec("UPDATE orders SET erp_status = 'synced', erp_so_no = ? WHERE ref = ?", [$body['sales_order']['so_no'] ?? null, $ref]);
            }
            // The CRM answers with the new points/tier or the ticket number: keep a copy.
            if ($row['event'] === 'crm.order.completed' && !empty($body['customer'])) {
                Crm::applyLoyalty($body['customer']);
            }
            if ($row['event'] === 'crm.ticket.create' && !empty($body['ticket'])) {
                Crm::applyTicket($body['ticket'], false);
            }
            return $base + ['status' => 'sent'];
        }

        // The ERP/CRM refused the data (sold out, cancelled, invalid): retrying won't help.
        if ($res['status'] >= 400 && $res['status'] < 500 && !in_array($res['status'], [401, 408, 429], true)) {
            $err = $res['body']['error'] ?? [];
            $message = $err['message'] ?? $res['error'];
            self::finish($row['id'], 'dead', $message);
            if ($row['event'] === 'erp.sales_order.create') {
                Db::exec("UPDATE orders SET erp_status = 'failed' WHERE ref = ?", [$ref]);
                self::drop($ref, 'Sales order was rejected by the ERP');
                Orders::erpRejected($ref, $message);
            }
            return $base + ['status' => 'rejected', 'code' => $err['code'] ?? 'rejected', 'message' => $message, 'sku' => $err['fields']['sku'] ?? null];
        }

        // ERP/CRM offline or erroring: try again later — or give up after MAX_ATTEMPTS.
        $attempts = (int) $row['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::finish($row['id'], 'dead', 'Gave up after ' . $attempts . ' attempts: ' . $res['error']);
            if ($row['event'] === 'erp.sales_order.create') {
                Db::exec("UPDATE orders SET erp_status = 'failed' WHERE ref = ?", [$ref]);
            }
            return $base + ['status' => 'gave_up', 'message' => $res['error']];
        }
        $delay = self::BACKOFF[$attempts] ?? 1800;
        Db::exec(
            "UPDATE integration_outbox SET status = 'failed', last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?",
            [$res['error'], $delay, $row['id']]
        );
        return $base + ['status' => 'retrying', 'message' => $res['error'], 'retry_in_seconds' => $delay];
    }

    private static function finish(int $id, string $status, ?string $error): void
    {
        Db::exec(
            'UPDATE integration_outbox SET status = ?, last_error = ?, sent_at = ? WHERE id = ?',
            [$status, $error, $status === 'sent' ? date('Y-m-d H:i:s') : null, $id]
        );
    }
}

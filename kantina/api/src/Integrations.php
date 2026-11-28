<?php
/**
 * Integration endpoints: incoming webhooks from the ERP, manual ERP sync,
 * and the status page data.
 *
 * Incoming webhooks are:
 *   1. verified  — HMAC-SHA256 signature with the shared secret (else 401)
 *   2. de-duplicated — each event id is processed once (webhook_log)
 *   3. processed — e.g. the ERP price/stock copy is updated
 *   4. logged    — visible on the integrations status page
 */
final class Integrations
{
    public static function routes(Router $r): void
    {
        $r->post('/integrations/erp/webhook', fn(Request $q) => self::receive($q, 'erp', [self::class, 'handleErp']));
        $r->post('/integrations/erp/sync', fn() => ['synced' => Catalog::fullSync()], [Auth::staff()]);
        $r->get('/integrations/status', [self::class, 'status'], [Auth::staff()]);
    }

    private static function receive(Request $req, string $source, callable $handler): array
    {
        $raw = $req->raw();
        $payload = json_decode($raw, true);
        $secret = (string) Config::get("$source.webhook_secret");
        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';

        if (!Signature::verify($secret, $req->header('X-Kantina-Signature'), $raw)) {
            Db::insert('webhook_log', [
                'source' => $source, 'event' => $event ?: null, 'event_id' => null, 'payload' => substr($raw, 0, 5000),
                'signature_ok' => 0, 'status' => 'rejected', 'error' => 'Invalid or missing signature',
            ]);
            throw new HttpError(401, 'invalid_signature', 'Webhook signature is invalid.');
        }
        if (!is_array($payload) || empty($payload['id']) || $event === '') {
            throw new HttpError(400, 'invalid_payload', 'Webhook body must have "id", "event" and "data".');
        }

        $existing = Db::one('SELECT id, status FROM webhook_log WHERE source = ? AND event_id = ?', [$source, $payload['id']]);
        if ($existing && $existing['status'] === 'processed') {
            return ['received' => true, 'status' => 'duplicate'];
        }
        $logId = $existing ? (int) $existing['id'] : Db::insert('webhook_log', [
            'source' => $source, 'event' => $event, 'event_id' => $payload['id'], 'payload' => $raw,
            'signature_ok' => 1, 'status' => 'processed',
        ]);
        try {
            $note = $handler($event, $payload['data'] ?? []);
            Db::exec("UPDATE webhook_log SET status = 'processed', error = ? WHERE id = ?", [$note, $logId]);
        } catch (Throwable $e) {
            // Mark failed so the sender's retry is processed again (not treated as a duplicate).
            Db::exec("UPDATE webhook_log SET status = 'failed', error = ? WHERE id = ?", [$e->getMessage(), $logId]);
            throw $e;
        }
        return ['received' => true, 'status' => 'processed', 'event' => $event];
    }

    /** ERP events carry the full product, so we can apply it directly. */
    public static function handleErp(string $event, array $data): ?string
    {
        switch ($event) {
            case 'product.updated':
            case 'stock.low':
            case 'stock.depleted':
                if (empty($data['product']['sku'])) {
                    throw new HttpError(400, 'invalid_payload', 'Missing product.');
                }
                Catalog::applyErpProduct($data['product']);
                return null;
            default:
                return "Ignored unknown event $event";
        }
    }

    public static function status(): array
    {
        $outbox = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM integration_outbox GROUP BY status') as $row) {
            $outbox[$row['status']] = (int) $row['n'];
        }
        return [
            'erp' => ErpClient::health(),
            'last_sync' => [
                'erp' => Support::kvGet('last_erp_sync'),
            ],
            'outbox' => [
                'pending' => ($outbox['pending'] ?? 0) + ($outbox['failed'] ?? 0),
                'failed' => $outbox['failed'] ?? 0,
                'dead' => $outbox['dead'] ?? 0,
                'sent' => $outbox['sent'] ?? 0,
                'recent' => array_map(fn($o) => [
                    'id' => (int) $o['id'],
                    'target' => $o['target'],
                    'event' => $o['event'],
                    'ref' => $o['aggregate_ref'],
                    'status' => $o['status'],
                    'attempts' => (int) $o['attempts'],
                    'last_error' => $o['last_error'],
                    'next_attempt_at' => iso($o['next_attempt_at']),
                    'created_at' => iso($o['created_at']),
                    'sent_at' => iso($o['sent_at']),
                ], Db::all('SELECT * FROM integration_outbox ORDER BY id DESC LIMIT 15')),
            ],
            'webhooks' => array_map(fn($w) => [
                'source' => $w['source'],
                'event' => $w['event'],
                'event_id' => $w['event_id'],
                'status' => $w['status'],
                'signature_ok' => (bool) (int) $w['signature_ok'],
                'error' => $w['error'],
                'received_at' => iso($w['received_at']),
            ], Db::all('SELECT * FROM webhook_log ORDER BY id DESC LIMIT 15')),
            'notifications' => array_map(fn($n) => [
                'kind' => $n['kind'],
                'to' => $n['to_email'],
                'subject' => $n['subject'],
                'status' => $n['status'],
                'error' => $n['error'],
                'created_at' => iso($n['created_at']),
            ], Db::all('SELECT * FROM notifications ORDER BY id DESC LIMIT 10')),
        ];
    }
}

<?php
/**
 * Sends signed webhooks (used by the ERP and the CMS to notify Kantina).
 *
 * Every event is first saved in a deliveries table, then POSTed with an
 * HMAC signature. Failed deliveries stay in the table and can be resent
 * from the admin pages (or automatically on the next event).
 *
 * Body:    {"id":"evt_…","event":"stock.low","source":"erp","created_at":"…","data":{…}}
 * Headers: X-Kantina-Event, X-Kantina-Signature (see Signature.php)
 */
final class WebhookSender
{
    /**
     * @param string $table  deliveries table, e.g. erp_webhook_deliveries
     */
    public static function send(string $table, string $url, string $secret, string $source, string $event, array $data): bool
    {
        $eventId = 'evt_' . bin2hex(random_bytes(10));
        $payload = json_encode([
            'id' => $eventId,
            'event' => $event,
            'source' => $source,
            'created_at' => date('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $id = Db::insert($table, ['event_id' => $eventId, 'event' => $event, 'payload' => $payload]);
        return self::deliver($table, $id, $url, $secret);
    }

    /** Try (again) to deliver one saved event. */
    public static function deliver(string $table, int $id, string $url, string $secret): bool
    {
        $row = Db::one("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$row || $row['status'] === 'delivered') {
            return true;
        }
        $res = HttpClient::request('POST', $url, $row['payload'], [
            'X-Kantina-Event' => $row['event'],
            'X-Kantina-Signature' => Signature::sign($secret, $row['payload']),
        ], 4);
        Db::exec(
            "UPDATE $table SET status = ?, attempts = attempts + 1, response_code = ?, last_error = ?, delivered_at = ? WHERE id = ?",
            [$res['ok'] ? 'delivered' : 'failed', $res['status'] ?: null, $res['ok'] ? null : $res['error'], $res['ok'] ? date('Y-m-d H:i:s') : null, $id]
        );
        return $res['ok'];
    }

    /** Resend failed/pending events (oldest first). Returns [delivered, stillFailing]. */
    public static function retryFailed(string $table, string $url, string $secret, int $limit = 20): array
    {
        $ok = 0;
        $bad = 0;
        foreach (Db::all("SELECT id FROM $table WHERE status <> 'delivered' ORDER BY id LIMIT " . (int) $limit) as $row) {
            self::deliver($table, (int) $row['id'], $url, $secret) ? $ok++ : $bad++;
        }
        return [$ok, $bad];
    }
}

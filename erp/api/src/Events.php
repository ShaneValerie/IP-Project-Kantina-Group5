<?php
/**
 * Webhooks from the ERP to Kantina.
 *
 * Events are queued while a database transaction is running and only sent
 * after it commits (Events::flush()), so Kantina never hears about a change
 * that was rolled back.
 *
 *   product.updated   price / stock / availability changed (admin or store console)
 *   stock.low         stock fell to or below the reorder level
 *   stock.depleted    stock reached 0 — item is sold out
 */
final class Events
{
    private static array $queue = [];

    public static function queue(string $event, array $data): void
    {
        self::$queue[] = [$event, $data];
    }

    /** Drop queued events (the transaction that created them was rolled back). */
    public static function discard(): void
    {
        self::$queue = [];
    }

    /** Send every queued event. Returns how many were delivered. */
    public static function flush(): int
    {
        $delivered = 0;
        $queue = self::$queue;
        self::$queue = [];
        foreach ($queue as [$event, $data]) {
            if (self::send($event, $data)) {
                $delivered++;
            }
        }
        return $delivered;
    }

    public static function send(string $event, array $data): bool
    {
        return WebhookSender::send('erp_webhook_deliveries', self::url(), self::secret(), 'erp', $event, $data);
    }

    public static function retryFailed(int $limit = 20): array
    {
        return WebhookSender::retryFailed('erp_webhook_deliveries', self::url(), self::secret(), $limit);
    }

    public static function url(): string
    {
        return Config::url('kantina') . '/api/integrations/erp/webhook';
    }

    private static function secret(): string
    {
        return (string) Config::get('webhook_secret');
    }

    /** Queue stock.low / stock.depleted when a change crosses a threshold. */
    public static function stockThresholds(array $product, int $before): void
    {
        if (!(int) $product['track_stock']) {
            return;
        }
        $after = (int) $product['stock'];
        $reorder = (int) $product['reorder_level'];
        $data = ['product' => Inventory::format($product)];
        if ($after <= 0 && $before > 0) {
            self::queue('stock.depleted', $data);
        } elseif ($after <= $reorder && $before > $reorder) {
            self::queue('stock.low', $data);
        }
    }
}

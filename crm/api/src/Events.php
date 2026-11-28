<?php
/**
 * Webhooks from the CRM to Kantina, signed with HMAC-SHA256
 * (X-Kantina-Signature header, same format as the ERP's webhooks).
 *
 *   Events::send('customer.updated', ['customer' => [...]]);
 *
 * Every webhook is stored in crm_webhook_deliveries first, so a failed one
 * can be resent from the admin "Webhooks to Kantina" page.
 */
final class Events
{
    public static function send(string $event, array $data): bool
    {
        return WebhookSender::send(
            'crm_webhook_deliveries',
            self::url(),
            (string) Config::get('webhook_secret'),
            'crm',
            $event,
            $data
        );
    }

    public static function retryFailed(int $limit = 20): array
    {
        return WebhookSender::retryFailed('crm_webhook_deliveries', self::url(), (string) Config::get('webhook_secret'), $limit);
    }

    /** Kantina's receiver for CRM webhooks. */
    public static function url(): string
    {
        return Config::url('kantina') . '/api/integrations/crm/webhook';
    }
}

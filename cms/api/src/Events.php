<?php
/**
 * Webhooks from the CMS to Kantina.
 *
 *   content.published  {"type": "stall" | "menu_item" | "announcement", "id": "...", "action": "updated" | "deleted"}
 *
 * This is a "thin" event: it only says what changed. Kantina then calls the
 * CMS delivery API to fetch the latest version, so the webhook can never
 * carry stale content.
 */
final class Events
{
    public static function published(string $type, string $id, string $action = 'updated'): bool
    {
        return WebhookSender::send(
            'cms_webhook_deliveries',
            self::url(),
            (string) Config::get('webhook_secret'),
            'cms',
            'content.published',
            ['type' => $type, 'id' => $id, 'action' => $action]
        );
    }

    public static function retryFailed(int $limit = 20): array
    {
        return WebhookSender::retryFailed('cms_webhook_deliveries', self::url(), (string) Config::get('webhook_secret'), $limit);
    }

    public static function url(): string
    {
        return Config::url('kantina') . '/api/integrations/cms/webhook';
    }
}

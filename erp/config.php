<?php
/**
 * Kantina ERP — default settings (good for local XAMPP).
 * Don't put real passwords or secrets here: copy config.sample.php to
 * config.local.php (ignored by Git) and override what you need there.
 */
return [
    'debug' => true,                       // show error details in API responses (set false on the live server)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kantina_erp',
        'user' => 'root',
        'pass' => '',
    ],
    // Public base URLs of the three apps. Empty = the apps are sibling folders on the same host.
    'urls' => ['kantina' => '', 'erp' => '', 'cms' => ''],
    // Clients allowed to call the ERP API, sent in the X-Api-Key header (Kantina uses the 'kantina' key).
    'api_keys' => ['kantina' => 'dev-erp-api-key'],
    // Shared secret for signing webhooks sent to Kantina (must equal erp.webhook_secret in Kantina's config).
    'webhook_secret' => 'dev-erp-webhook-secret',
];

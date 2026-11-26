<?php
/**
 * Copy this file to config.local.php and fill in your values.
 * Only the settings you put here override config.php.
 * Generate matching secrets for all three apps with: php tools/make-config.php
 */
return [
    'debug' => false,
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'kantina_erp',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],
    'urls' => [
        'kantina' => 'https://kantina.example.edu.ph',
        'erp'     => 'https://kantina-erp.example.edu.ph',
        'cms'     => 'https://kantina-cms.example.edu.ph',
    ],
    'api_keys' => ['kantina' => 'paste-the-erp-api-key-here'],
    'webhook_secret' => 'paste-the-erp-webhook-secret-here',
];

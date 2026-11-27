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
        'name' => 'kantina',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],
    'urls' => [
        'kantina' => 'https://kantina.example.edu.ph',
        'erp'     => 'https://kantina-erp.example.edu.ph',
        'crm'     => 'https://kantina-crm.example.edu.ph',
    ],
    'jwt_secret' => 'paste-a-long-random-secret-here',
    'erp' => ['api_key' => 'paste-the-erp-api-key-here', 'webhook_secret' => 'paste-the-erp-webhook-secret-here'],
    'crm' => ['api_key' => 'paste-the-crm-api-key-here', 'webhook_secret' => 'paste-the-crm-webhook-secret-here'],
    'email' => [
        'brevo_api_key' => '',              // from brevo.com → SMTP & API → API keys
        'from_email' => 'your-verified-sender@example.com',
        'from_name' => 'Kantina',
    ],
];

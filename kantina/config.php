<?php
/**
 * Kantina — default settings (good for local XAMPP).
 * Don't put real passwords or secrets here: copy config.sample.php to
 * config.local.php (ignored by Git) and override what you need there.
 */
return [
    'debug' => true,                        // show error details in API responses (set false on the live server)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kantina',
        'user' => 'root',
        'pass' => '',
    ],
    // Public base URLs of the three apps. Empty = the apps are sibling folders on the same host.
    'urls' => ['kantina' => '', 'erp' => '', 'cms' => ''],

    // Login tokens (JWT, HS256)
    'jwt_secret' => 'dev-kantina-jwt-secret',
    'token_ttl_hours' => 168,

    // Integrations — keys must match the ERP / CMS configs
    'erp' => ['api_key' => 'dev-erp-api-key', 'webhook_secret' => 'dev-erp-webhook-secret'],
    'cms' => ['api_key' => 'dev-cms-api-key', 'webhook_secret' => 'dev-cms-webhook-secret'],

    // Ordering
    'service_fee' => 5,
    'pickup' => [
        'slot_minutes' => 15,
        'lead_minutes' => 10,               // earliest pickup = now + 10 minutes
        'capacity_per_stall' => 8,          // orders per stall per 15-minute slot
        'periods' => [
            ['name' => 'Morning',   'from' => '07:00', 'to' => '11:00'],
            ['name' => 'Lunch',     'from' => '11:00', 'to' => '13:00'],
            ['name' => 'Afternoon', 'from' => '13:00', 'to' => '19:00'],
        ],
    ],

    // Table reservations
    'reservations' => [
        'slots' => ['11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30'],
        'duration_minutes' => 45,
        'grace_minutes' => 10,              // no-shows are released 10 minutes after the start time
        'days_ahead' => 3,
    ],
    'term_start' => '2026-08-03',           // "orders this term" on the profile page

    // Email notifications (Brevo transactional email API). Empty key = emails are only logged.
    'email' => [
        'brevo_api_key' => '',
        'from_email' => '',
        'from_name' => 'Kantina',
    ],

    'request_log' => true,
];

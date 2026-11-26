<?php
/**
 * Optional background job. Kantina works without it (the outbox is retried
 * when staff use the console, and no-shows are released on read), but if the
 * server allows cron, run it every minute so ERP retries happen on time:
 *
 *   * * * * * php /path/to/kantina/api/cron.php >/dev/null 2>&1
 *
 * Needs the public URLs in config.local.php (there is no web request to
 * guess them from).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'kantina');
foreach (['Support', 'Auth', 'Clients', 'Catalog', 'Outbox', 'Orders', 'Reservations', 'Notifier'] as $module) {
    require_once __DIR__ . "/src/$module.php";
}

Orders::expireUnpaid();
Reservations::housekeeping();
$results = Outbox::process(null, 50);
$sent = count(array_filter($results, fn($r) => $r['status'] === 'sent'));
echo date('c') . " outbox: $sent sent, " . (count($results) - $sent) . " not delivered\n";

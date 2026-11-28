<?php
/**
 * Kantina CRM — customer relationship management API (front controller).
 * Base URL: <crm>/api/v1      Auth: X-Api-Key header (see config 'api_keys')
 *
 * Customers, loyalty points and feedback tickets. Kantina calls it when an
 * order is completed (POST /orders) and when a student sends feedback
 * (POST /tickets). The CRM tells Kantina about point changes and ticket
 * replies with signed webhooks (src/Events.php). Contract: docs/API.md.
 */
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'crm');
require __DIR__ . '/src/Events.php';
require __DIR__ . '/src/Customers.php';
require __DIR__ . '/src/Tickets.php';
require __DIR__ . '/src/Reports.php';

// Put this on every route Kantina calls: [$apiKey]
$apiKey = function (Request $req): void {
    $key = $req->header('X-Api-Key');
    $client = $key ? array_search($key, (array) Config::get('api_keys', []), true) : false;
    if ($client === false) {
        throw new HttpError(401, 'invalid_api_key', 'Missing or invalid X-Api-Key header.');
    }
    $req->attributes['client'] = (string) $client;
};

$r = new Router();

$r->get('/v1', fn() => ['name' => 'Kantina CRM API', 'version' => 'v1', 'endpoints' => $r->routes()]);
$r->get('/v1/health', fn() => ['ok' => true, 'app' => 'kantina-crm', 'time' => date('c'), 'db' => (bool) Db::value('SELECT 1')]);

// Example of a protected route (try it with: curl -H "X-Api-Key: dev-crm-api-key" <crm>/api/v1/whoami)
$r->get('/v1/whoami', fn(Request $q) => ['client' => $q->attributes['client']], [$apiKey]);

// ---- Orders → loyalty points (idempotent by order_ref) ----
$r->post('/v1/orders', function (Request $q) {
    [$customer, $earned, $duplicate] = Customers::recordOrder($q->body());
    return [
        'customer' => [
            'kantina_user_id' => (int) $customer['kantina_user_id'],
            'points' => (int) $customer['points'],
            'tier' => $customer['tier'],
            'orders_count' => (int) $customer['orders_count'],
            'total_spent' => money($customer['total_spent']),
        ],
        'points_earned' => $earned,
        'duplicate' => $duplicate,
    ];
}, [$apiKey]);

// ---- Customers ----
$r->get('/v1/customers', fn(Request $q) => [
    'customers' => array_map([Customers::class, 'format'], Customers::list($q->query['q'] ?? null)),
], [$apiKey]);
$r->get('/v1/customers/{id}', function (Request $q) {
    $c = Customers::byKantinaId((int) $q->params['id']);
    return [
        'customer' => Customers::format($c),
        'interactions' => array_map([Customers::class, 'formatInteraction'], Customers::interactions((int) $c['id'])),
        'tickets' => array_map(fn($t) => Tickets::format($t, false), Tickets::forCustomer((int) $c['id'])),
    ];
}, [$apiKey]);

// ---- Feedback tickets (idempotent by external_ref) ----
$r->post('/v1/tickets', function (Request $q) {
    [$ticket, $created] = Tickets::create($q->body());
    return [['ticket' => Tickets::format($ticket)], $created ? 201 : 200];
}, [$apiKey]);
$r->get('/v1/tickets', fn(Request $q) => [
    'tickets' => array_map([Tickets::class, 'format'], Tickets::list($q->query['status'] ?? null)),
], [$apiKey]);
$r->get('/v1/tickets/{ref}', fn(Request $q) => ['ticket' => Tickets::format(Tickets::get($q->params['ref']))], [$apiKey]);

// ---- Reports ----
$r->get('/v1/reports/summary', fn() => Reports::summary(), [$apiKey]);

api_run($r);

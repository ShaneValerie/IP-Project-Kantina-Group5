<?php
/**
 * Kantina ERP — REST API (front controller).
 * Base URL: <erp>/api/v1      Auth: X-Api-Key header (see config 'api_keys')
 */
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'erp');
require __DIR__ . '/src/Events.php';
require __DIR__ . '/src/Inventory.php';
require __DIR__ . '/src/Sales.php';
require __DIR__ . '/src/Reports.php';

/** Middleware: only known API clients may call the ERP. */
$apiKey = function (Request $req): void {
    $key = $req->header('X-Api-Key');
    $client = $key ? array_search($key, (array) Config::get('api_keys', []), true) : false;
    if ($client === false) {
        throw new HttpError(401, 'invalid_api_key', 'Missing or invalid X-Api-Key header.');
    }
    $req->attributes['client'] = (string) $client;
};
$actor = fn(Request $req): string => 'api:' . ($req->attributes['client'] ?? 'unknown');

$r = new Router();

$r->get('/v1', fn() => [
    'name' => 'Kantina ERP API',
    'version' => 'v1',
    'endpoints' => $r->routes(),
]);
$r->get('/v1/health', fn() => [
    'ok' => true,
    'app' => 'kantina-erp',
    'time' => date('c'),
    'db' => (bool) Db::value('SELECT 1'),
]);

// ---- Products (item master) ----
$r->get('/v1/products', fn(Request $q) => [
    'products' => array_map([Inventory::class, 'format'], Inventory::list([
        'stall' => $q->query['stall'] ?? null,
        'type' => $q->query['type'] ?? null,
        'low_stock' => !empty($q->query['low_stock']),
    ])),
], [$apiKey]);
$r->get('/v1/products/{sku}', fn(Request $q) => ['product' => Inventory::format(Inventory::get($q->params['sku']))], [$apiKey]);
$r->post('/v1/products', fn(Request $q) => [['product' => Inventory::format(Inventory::create($q->body(), $actor($q)))], 201], [$apiKey]);
$r->patch('/v1/products/{sku}', fn(Request $q) => ['product' => Inventory::format(Inventory::update($q->params['sku'], $q->body(), $actor($q)))], [$apiKey]);
$r->get('/v1/products/{sku}/movements', fn(Request $q) => [
    'movements' => array_map(fn($m) => [
        'qty_change' => (int) $m['qty_change'],
        'balance_after' => (int) $m['balance_after'],
        'reason' => $m['reason'],
        'reference' => $m['reference'],
        'note' => $m['note'],
        'created_by' => $m['created_by'],
        'created_at' => iso($m['created_at']),
    ], Inventory::movements(Inventory::get($q->params['sku'])['sku'], 50)),
], [$apiKey]);

// ---- Stock ----
$r->post('/v1/stock-adjustments', fn(Request $q) => [['product' => Inventory::format(Inventory::adjust($q->body(), $actor($q)))], 201], [$apiKey]);

// ---- Sales orders ----
$stockOf = fn(array $products) => array_map(fn($p) => [
    'sku' => $p['sku'], 'stock' => (int) $p['stock'], 'sold_out' => (int) $p['track_stock'] && (int) $p['stock'] <= 0,
], $products);

$r->post('/v1/sales-orders', function (Request $q) use ($actor, $stockOf) {
    [$so, $created, $changed] = Sales::create($q->body(), $actor($q));
    return [['sales_order' => Sales::format($so), 'stock' => $stockOf($changed)], $created ? 201 : 200];
}, [$apiKey]);
$r->get('/v1/sales-orders', fn(Request $q) => [
    'sales_orders' => array_map(fn($so) => Sales::format($so, false), Sales::list($q->query, min(200, (int) ($q->query['limit'] ?? 50)))),
    'total' => Sales::count($q->query),
], [$apiKey]);
$r->get('/v1/sales-orders/{ref}', fn(Request $q) => ['sales_order' => Sales::format(Sales::get($q->params['ref']))], [$apiKey]);
$r->post('/v1/sales-orders/{ref}/pay', fn(Request $q) => ['sales_order' => Sales::format(Sales::pay($q->params['ref']))], [$apiKey]);
$r->post('/v1/sales-orders/{ref}/cancel', function (Request $q) use ($actor, $stockOf) {
    [$so, $changed] = Sales::cancel($q->params['ref'], $actor($q));
    return ['sales_order' => Sales::format($so), 'stock' => $stockOf($changed)];
}, [$apiKey]);

// ---- Reports ----
$r->get('/v1/reports/sales', function (Request $q) {
    [$from, $to] = Reports::range($q->query['from'] ?? null, $q->query['to'] ?? null);
    return Reports::sales($q->query['stall'] ?? null, $from, $to);
}, [$apiKey]);
$r->get('/v1/reports/top-items', function (Request $q) {
    [$from, $to] = Reports::range($q->query['from'] ?? null, $q->query['to'] ?? null);
    return [
        'from' => $from,
        'to' => $to,
        'items' => Reports::topItems($q->query['stall'] ?? null, $from, $to, (int) ($q->query['limit'] ?? 5), $q->query['type'] ?? 'menu'),
    ];
}, [$apiKey]);

api_run($r);

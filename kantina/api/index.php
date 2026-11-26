<?php
/**
 * Kantina REST API — front controller.
 * Every request to kantina/api/... is routed here (see .htaccess).
 *
 * Request flow:
 *   .htaccess -> index.php -> Router -> middleware (auth, validation) -> module handler
 *   -> JSON response -> request log
 */
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'kantina');

// Each module registers its own routes.
$modules = [
    'Support',
    'Auth',
];
foreach ($modules as $module) {
    require_once __DIR__ . "/src/$module.php";
}

$r = new Router();

$r->get('/', fn() => [
    'name' => 'Kantina API',
    'docs' => Config::url('kantina') . '/api/docs/',
    'endpoints' => $r->routes(),
]);
$r->get('/health', fn() => [
    'ok' => true,
    'app' => 'kantina',
    'time' => date('c'),
    'db' => (bool) Db::value('SELECT 1'),
]);

foreach ($modules as $module) {
    if (method_exists($module, 'routes')) {
        $module::routes($r);
    }
}

// Middleware: log every request (method, path, status, user, time taken).
api_run($r, function (Request $req, int $status, int $ms): void {
    if (!Config::get('request_log') || $req->method === 'OPTIONS') {
        return;
    }
    Db::insert('request_log', [
        'method' => $req->method,
        'path' => substr($req->path, 0, 255),
        'status' => $status,
        'user_id' => $req->user['id'] ?? null,
        'ms' => $ms,
        'ip' => $req->ip(),
    ]);
});

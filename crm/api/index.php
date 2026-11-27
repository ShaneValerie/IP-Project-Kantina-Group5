<?php
/**
 * Kantina CRM — customer relationship management API (front controller).
 * Base URL: <crm>/api/v1      Auth: X-Api-Key header (see config 'api_keys')
 *
 * Starter app: sign-in, API keys and signed webhooks to Kantina are ready.
 * The CRM features (customers, loyalty, feedback tickets) are added on top —
 * see the CRM issues on GitHub and the "CRM" section of docs/ARCHITECTURE.md.
 */
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'crm');
require __DIR__ . '/src/Events.php';

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

api_run($r);

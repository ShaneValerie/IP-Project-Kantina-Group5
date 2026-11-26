<?php
/**
 * Kantina CMS — content delivery & management API (front controller).
 * Base URL: <cms>/api/v1      Auth: X-Api-Key header (see config 'api_keys')
 *
 * Changes made through this API come from Kantina itself (store console),
 * so they don't trigger a webhook back to Kantina. Changes made in the CMS
 * admin pages do.
 */
require __DIR__ . '/lib/bootstrap.php';
Config::load(dirname(__DIR__), 'cms');
require __DIR__ . '/src/Content.php';
require __DIR__ . '/src/Events.php';

$apiKey = function (Request $req): void {
    $key = $req->header('X-Api-Key');
    $client = $key ? array_search($key, (array) Config::get('api_keys', []), true) : false;
    if ($client === false) {
        throw new HttpError(401, 'invalid_api_key', 'Missing or invalid X-Api-Key header.');
    }
    $req->attributes['client'] = (string) $client;
};
$drafts = fn(Request $q): bool => !empty($q->query['include_drafts']);

$r = new Router();

$r->get('/v1', fn() => ['name' => 'Kantina CMS API', 'version' => 'v1', 'endpoints' => $r->routes()]);
$r->get('/v1/health', fn() => ['ok' => true, 'app' => 'kantina-cms', 'time' => date('c'), 'db' => (bool) Db::value('SELECT 1')]);

// Everything Kantina needs for a full catalog sync, in one call.
$r->get('/v1/content', fn(Request $q) => [
    'stalls' => array_map([Content::class, 'formatStall'], Content::stalls($drafts($q))),
    'menu_content' => array_map([Content::class, 'formatEntry'], Content::entries(null, $drafts($q))),
    'announcements' => array_map([Content::class, 'formatAnnouncement'], Content::announcements()),
    'generated_at' => date('c'),
], [$apiKey]);

// ---- Stalls ----
$r->get('/v1/stalls', fn(Request $q) => ['stalls' => array_map([Content::class, 'formatStall'], Content::stalls($drafts($q)))], [$apiKey]);
$r->get('/v1/stalls/{id}', fn(Request $q) => ['stall' => Content::formatStall(Content::stall($q->params['id']))], [$apiKey]);

// ---- Menu content ----
$r->get('/v1/menu-content', fn(Request $q) => [
    'menu_content' => array_map([Content::class, 'formatEntry'], Content::entries($q->query['stall'] ?? null, $drafts($q))),
], [$apiKey]);
$r->get('/v1/menu-content/{sku}', fn(Request $q) => ['entry' => Content::formatEntry(Content::entry($q->params['sku']))], [$apiKey]);
$r->post('/v1/menu-content', fn(Request $q) => [['entry' => Content::formatEntry(Content::saveEntry(null, $q->body(), true))], 201], [$apiKey]);
$r->patch('/v1/menu-content/{sku}', fn(Request $q) => ['entry' => Content::formatEntry(Content::saveEntry($q->params['sku'], $q->body(), false))], [$apiKey]);

// ---- Announcements ----
$r->get('/v1/announcements', fn() => ['announcements' => array_map([Content::class, 'formatAnnouncement'], Content::announcements())], [$apiKey]);

api_run($r);

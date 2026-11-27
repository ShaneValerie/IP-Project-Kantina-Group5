<?php
/**
 * End-to-end smoke test for Kantina + ERP + CMS (runs against live servers).
 *
 *   php tests/smoke.php                                   # dev.sh defaults (ports 8000/8001/8002)
 *   php tests/smoke.php http://localhost/IP-Project-Kantina-Group5/kantina \
 *                       http://localhost/IP-Project-Kantina-Group5/erp \
 *                       http://localhost/IP-Project-Kantina-Group5/cms
 *   php tests/smoke.php https://kantina.<domain> https://kantina-erp.<domain> https://kantina-cms.<domain>
 *
 * API keys and webhook secrets are read from erp/config.local.php and
 * cms/config.local.php when present (otherwise the development defaults).
 * It creates a few test orders and one reservation (cancelled/completed at the end).
 */
if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

$K = rtrim($argv[1] ?? 'http://localhost:8000', '/') . '/api';
$E = rtrim($argv[2] ?? 'http://localhost:8001', '/') . '/api/v1';
$C = rtrim($argv[3] ?? 'http://localhost:8002', '/') . '/api/v1';
$root = dirname(__DIR__);
$cfg = function (string $app) use ($root): array {
    $base = require "$root/$app/config.php";
    $local = "$root/$app/config.local.php";
    return is_file($local) ? array_replace_recursive($base, require $local) : $base;
};
$erpCfg = $cfg('erp');
$cmsCfg = $cfg('cms');
$ERP_KEY = $erpCfg['api_keys']['kantina'];
$CMS_KEY = $cmsCfg['api_keys']['kantina'];
$CMS_SECRET = $cmsCfg['webhook_secret'];

$passed = 0;
$failed = 0;

function http(string $method, string $url, $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, json_decode((string) $raw, true), $err ?: null];
}

function check(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $failed++;
        echo "  \033[31m✗ $name\033[0m" . ($detail ? " — $detail" : '') . "\n";
    }
    return $ok;
}

function section(string $title): void
{
    echo "\n$title\n";
}

function bearer(?string $token): array
{
    return $token ? ['Authorization' => "Bearer $token"] : [];
}

function signed(string $secret, array $payload): array
{
    $body = json_encode($payload);
    $t = time();
    return [$body, ['X-Kantina-Signature' => "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret)]];
}

echo "Kantina smoke test\n  Kantina $K\n  ERP     $E\n  CMS     $C\n";

// ------------------------------------------------------------------ health
section('Health');
foreach (['Kantina' => "$K/health", 'ERP' => "$E/health", 'CMS' => "$C/health"] as $name => $url) {
    [$s, $b, $err] = http('GET', $url);
    check("$name is up", $s === 200 && !empty($b['ok']), $err ?? "HTTP $s");
}
[$s] = http('GET', str_replace('/api', '/api/docs/', $K));
check('API docs page loads', $s === 200, "HTTP $s");

// ------------------------------------------------------------------ auth
section('Authentication & access control');
[$s, $b] = http('POST', "$K/auth/login", ['login' => '21-0942', 'password' => 'kantina123']);
$student = $b['token'] ?? null;
check('student signs in', $s === 200 && $student, "HTTP $s");
[$s, $b] = http('POST', "$K/auth/login", ['login' => '22-1188', 'password' => 'kantina123']);
$student2 = $b['token'] ?? null;
[$s, $b] = http('POST', "$K/auth/staff/login", ['account' => 'stall-01', 'password' => 'kantina123']);
$staff1 = $b['token'] ?? null;
check("staff signs in (Lola's Silog)", $s === 200 && $staff1, "HTTP $s");
[$s, $b] = http('POST', "$K/auth/staff/login", ['account' => 'stall-02', 'password' => 'kantina123']);
$staff2 = $b['token'] ?? null;
[$s] = http('POST', "$K/auth/login", ['login' => '21-0942', 'password' => 'wrong-password']);
check('wrong password is rejected (401)', $s === 401, "HTTP $s");
[$s] = http('GET', "$K/orders");
check('no token → 401', $s === 401, "HTTP $s");
[$s] = http('GET', "$K/seller/orders", null, bearer($student));
check('student on the store console → 403', $s === 403, "HTTP $s");
if (!$student || !$staff1 || !$staff2 || !$student2) {
    exit("\nCannot continue without sign-in. Did you import the demo data (php database/install.php)?\n");
}

// ------------------------------------------------------------------ catalog
section('Catalog (CMS content + ERP prices)');
[$s, $b] = http('GET', "$K/stalls");
check('stalls listed', $s === 200 && count($b['stalls'] ?? []) >= 4, "HTTP $s");
[$s, $menu] = http('GET', "$K/stalls/lolas-silog/menu");
check("Lola's Silog menu has sections", $s === 200 && !empty($menu['sections']), "HTTP $s");
$sku = 'LS-TAPSILOG';
[$s, $erpProduct] = http('GET', "$E/products/$sku", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP product readable with API key', $s === 200, "HTTP $s");
[$s] = http('GET', "$E/products/$sku");
check('ERP rejects calls without API key (401)', $s === 401, "HTTP $s");
$stockBefore = (int) ($erpProduct['product']['stock'] ?? 0);
if ($stockBefore < 3) {
    http('POST', "$E/stock-adjustments", ['sku' => $sku, 'qty_change' => 20, 'note' => 'smoke test restock'], ['X-Api-Key' => $ERP_KEY]);
    $stockBefore += 20;
    sleep(1);
}

// ------------------------------------------------------------------ ordering
section('Ordering → ERP sales orders');
[$s, $slots] = http('GET', "$K/pickup-slots?stalls=lolas-silog");
$slot = null;
foreach ($slots['periods'] ?? [] as $p) {
    foreach ($p['slots'] as $sl) {
        if ($sl['available']) { $slot = $sl['time']; break 2; }
    }
}
check('a pickup slot is available', $slot !== null, 'no free slot found');
$date = $slots['date'] ?? date('Y-m-d');
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $sku, 'qty' => 1]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'gcash'], bearer($student));
$checkout = $b['checkout'] ?? null;
$ref = $checkout['orders'][0]['ref'] ?? null;
$orderId = $checkout['orders'][0]['id'] ?? null;
check('order placed (201)', $s === 201 && $ref, json_encode($b['error'] ?? "HTTP $s"));
[$s, $so] = http('GET', "$E/sales-orders/$ref", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP received the sales order', $s === 200 && ($so['sales_order']['status'] ?? '') === 'open', "HTTP $s");
[$s, $p] = http('GET', "$E/products/$sku", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP stock went down by 1', ($p['product']['stock'] ?? -1) === $stockBefore - 1, ($p['product']['stock'] ?? '?') . " vs expected " . ($stockBefore - 1));
[$s, $b] = http('POST', "$K/payments/{$checkout['ref']}/confirm", ['result' => 'authorized'], bearer($student));
check('demo GCash payment confirmed', $s === 200 && ($b['checkout']['payment_status'] ?? '') === 'paid', "HTTP $s");
[$s, $so] = http('GET', "$E/sales-orders/$ref", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP sales order is paid', ($so['sales_order']['status'] ?? '') === 'paid', $so['sales_order']['status'] ?? "HTTP $s");
[$s] = http('GET', "$K/orders/$ref", null, bearer($student2));
check("another student can't read the order (404)", $s === 404, "HTTP $s");

section('Store console workflow');
[$s] = http('PATCH', "$K/seller/orders/$orderId/status", ['status' => 'preparing'], bearer($staff2));
check("another stall can't touch the order (404)", $s === 404, "HTTP $s");
[$s] = http('PATCH', "$K/seller/orders/$orderId/status", ['status' => 'completed'], bearer($staff1));
check('skipping steps is refused (409)', $s === 409, "HTTP $s");
foreach (['preparing', 'ready', 'completed'] as $st) {
    [$s, $b] = http('PATCH', "$K/seller/orders/$orderId/status", ['status' => $st], bearer($staff1));
    check("order → $st", $s === 200 && ($b['order']['status'] ?? '') === $st, "HTTP $s");
}
[$s, $b] = http('GET', "$K/orders/$ref", null, bearer($student));
check('student tracking shows completed', ($b['order']['status'] ?? '') === 'completed', $b['order']['status'] ?? "HTTP $s");

section('Cancelling returns stock to the ERP');
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $sku, 'qty' => 2]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'counter'], bearer($student));
$ref2 = $b['checkout']['orders'][0]['ref'] ?? null;
check('counter order placed', $s === 201 && $ref2, "HTTP $s");
[$s] = http('POST', "$K/orders/$ref2/cancel", null, bearer($student));
check('student cancels it', $s === 200, "HTTP $s");
[$s, $so] = http('GET', "$E/sales-orders/$ref2", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP sales order cancelled', ($so['sales_order']['status'] ?? '') === 'cancelled', $so['sales_order']['status'] ?? "HTTP $s");
[$s, $p] = http('GET', "$E/products/$sku", null, ['X-Api-Key' => $ERP_KEY]);
check('ERP stock restored', ($p['product']['stock'] ?? -1) === $stockBefore - 1, ($p['product']['stock'] ?? '?') . ' vs ' . ($stockBefore - 1));

section('Stock checks');
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $sku, 'qty' => 20], ['sku' => 'LS-LONGSILOG', 'qty' => 20]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'gcash'], bearer($student));
check('ordering more than the stock is refused (409 sold_out)', $s === 409 && ($b['error']['code'] ?? '') === 'sold_out', "HTTP $s " . ($b['error']['code'] ?? ''));

// ------------------------------------------------------------------ CMS webhook
section('CMS publish → webhook → Kantina pulls the new content');
[$s, $entry] = http('GET', "$C/menu-content/$sku", null, ['X-Api-Key' => $CMS_KEY]);
$original = $entry['entry']['description'] ?? '';
$marker = 'Smoke test ' . date('H:i:s');
http('PATCH', "$C/menu-content/$sku", ['description' => $marker], ['X-Api-Key' => $CMS_KEY]);
[$body, $headers] = signed($CMS_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'content.published', 'source' => 'cms', 'created_at' => date('c'), 'data' => ['type' => 'menu_item', 'id' => $sku, 'action' => 'updated']]);
[$s] = http('POST', "$K/integrations/cms/webhook", $body, $headers);
check('signed CMS webhook accepted', $s === 200, "HTTP $s");
[$s, $b] = http('GET', "$K/menu-items/$sku");
check('Kantina shows the new description', ($b['item']['description'] ?? '') === $marker, $b['item']['description'] ?? "HTTP $s");
[$s] = http('POST', "$K/integrations/cms/webhook", $body, $headers);
check('same event again is ignored as a duplicate', $s === 200, "HTTP $s");
http('PATCH', "$C/menu-content/$sku", ['description' => $original], ['X-Api-Key' => $CMS_KEY]);
[$body, $headers] = signed($CMS_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'content.published', 'source' => 'cms', 'created_at' => date('c'), 'data' => ['type' => 'menu_item', 'id' => $sku, 'action' => 'updated']]);
http('POST', "$K/integrations/cms/webhook", $body, $headers);
[$s] = http('POST', "$K/integrations/erp/webhook", '{"id":"evt_fake","event":"product.updated","data":{}}', ['X-Kantina-Signature' => 't=' . time() . ',v1=' . str_repeat('0', 64)]);
check('webhook with a forged signature is rejected (401)', $s === 401, "HTTP $s");

// ------------------------------------------------------------------ reservations
section('Reservations');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
[$s, $av] = http('GET', "$K/tables/availability?date=$tomorrow&time=14:30&party=2");
$free = null;
foreach ($av['tables'] ?? [] as $t) {
    if ($t['status'] === 'free' && $t['fits']) { $free = $t['code']; break; }
}
check('availability lists a free table', $free !== null, "HTTP $s");
[$s, $b] = http('POST', "$K/reservations", ['table' => $free, 'date' => $tomorrow, 'time' => '14:30', 'party_size' => 2], bearer($student));
$rsv = $b['reservation']['ref'] ?? null;
check("table $free reserved", $s === 201 && $rsv, json_encode($b['error'] ?? "HTTP $s"));
[$s] = http('POST', "$K/reservations", ['table' => $free, 'date' => $tomorrow, 'time' => '14:30', 'party_size' => 2], bearer($student2));
check('same table and time refused for someone else (409)', $s === 409, "HTTP $s");
[$s, $b] = http('GET', "$K/seller/tables?date=$tomorrow", null, bearer($staff1));
$onMap = false;
foreach ($b['tables'] ?? [] as $t) {
    foreach ($t['code'] === $free ? $t['slots'] : [] as $cell) {
        $onMap = $onMap || $cell['reservation_ref'] === $rsv;
    }
}
check('reservation shows on the store console map', $onMap);
[$s] = http('DELETE', "$K/reservations/$rsv", null, bearer($student));
check('reservation cancelled', $s === 200, "HTTP $s");

// ------------------------------------------------------------------ analytics + status
section('Analytics & integration status');
[$s, $b] = http('GET', "$K/seller/analytics?range=7", null, bearer($staff1));
check('analytics come from the ERP', $s === 200 && ($b['source'] ?? '') === 'erp' && ($b['orders'] ?? 0) > 0, "HTTP $s");
[$s, $b] = http('GET', "$K/integrations/status", null, bearer($staff1));
check('status page sees the ERP online', !empty($b['erp']['online']), $b['erp']['error'] ?? "HTTP $s");
check('status page sees the CMS online', !empty($b['cms']['online']), $b['cms']['error'] ?? "HTTP $s");

echo "\n" . ($failed ? "\033[31m$failed failed\033[0m, " : '') . "\033[32m$passed passed\033[0m\n";
exit($failed ? 1 : 0);

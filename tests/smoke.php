<?php
/**
 * End-to-end smoke test for Kantina + ERP + CRM (runs against live servers).
 *
 *   php tests/smoke.php                                   # dev.sh defaults (ports 8000/8001/8002)
 *   php tests/smoke.php http://localhost/IP-Project-Kantina-Group5/kantina \
 *                       http://localhost/IP-Project-Kantina-Group5/erp \
 *                       http://localhost/IP-Project-Kantina-Group5/crm
 *   php tests/smoke.php https://kantina.<domain> https://kantina-erp.<domain> https://kantina-crm.<domain>
 *
 * API keys and webhook secrets are read from erp/config.local.php and
 * crm/config.local.php when present (otherwise the development defaults).
 * It creates a few test orders, one reservation and one feedback message; the orders end
 * up completed or cancelled (the completed one earns CRM loyalty points),
 * and the stock it changes in the ERP is put back.
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
$crmCfg = $cfg('crm');
$ERP_KEY = $erpCfg['api_keys']['kantina'];
$ERP_SECRET = $erpCfg['webhook_secret'];
$CRM_KEY = $crmCfg['api_keys']['kantina'];
$CRM_SECRET = $crmCfg['webhook_secret'];

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

echo "Kantina smoke test\n  Kantina $K\n  ERP     $E\n  CRM     $C\n";

// ------------------------------------------------------------------ health
section('Health');
foreach (['Kantina' => "$K/health", 'ERP' => "$E/health", 'CRM' => "$C/health"] as $name => $url) {
    [$s, $b, $err] = http('GET', $url);
    check("$name is up", $s === 200 && !empty($b['ok']), $err ?? "HTTP $s");
}
[$s] = http('GET', substr($K, 0, -strlen('/api')) . '/api/docs/');
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
section('Catalog (Kantina content + ERP prices)');
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

[$s, $me] = http('GET', "$K/me", null, bearer($student));
$pointsBefore = (int) ($me['loyalty']['points'] ?? -1);
$expectedPoints = (int) floor((float) ($checkout['orders'][0]['subtotal'] ?? 0) / 10);

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

section('CRM: loyalty points for completed orders');
[$s, $b] = http('GET', "$C/customers/" . ($me['user']['id'] ?? 0), null, ['X-Api-Key' => $CRM_KEY]);
$inCrm = false;
foreach ($b['interactions'] ?? [] as $i) {
    $inCrm = $inCrm || ($i['type'] === 'order_completed' && $i['ref'] === $ref);
}
check('the CRM recorded the completed order', $s === 200 && $inCrm, "HTTP $s");
[$s, $b] = http('GET', "$K/me", null, bearer($student));
check("the student's points went up by $expectedPoints", ($b['loyalty']['points'] ?? null) === $pointsBefore + $expectedPoints,
    json_encode($b['loyalty'] ?? null) . " vs $pointsBefore + $expectedPoints");

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

section('Stock checks (ERP stock change → webhook → Kantina)');
$lowSku = 'LS-LONGSILOG';
[$s, $p] = http('GET', "$E/products/$lowSku", null, ['X-Api-Key' => $ERP_KEY]);
$originalStock = (int) ($p['product']['stock'] ?? 0);
[$s] = http('PATCH', "$E/products/$lowSku", ['stock' => 1], ['X-Api-Key' => $ERP_KEY]);
check('ERP stock set to 1', $s === 200, "HTTP $s");
[$s, $b] = http('GET', "$K/menu-items/$lowSku");
check('Kantina received the new stock by webhook', ($b['item']['stock'] ?? null) === 1, 'stock ' . json_encode($b['item']['stock'] ?? null));
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $lowSku, 'qty' => 2]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'gcash'], bearer($student));
check('ordering more than the stock is refused (409 sold_out)', $s === 409 && ($b['error']['code'] ?? '') === 'sold_out', "HTTP $s " . ($b['error']['code'] ?? ''));
if ($s === 201) {
    http('POST', "$K/payments/{$b['checkout']['ref']}/confirm", ['result' => 'cancelled'], bearer($student));
}
http('PATCH', "$E/products/$lowSku", ['stock' => $originalStock], ['X-Api-Key' => $ERP_KEY]);

section('Payments after cancellations');
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $sku, 'qty' => 1], ['sku' => 'KK-ICED-COFFEE', 'qty' => 1]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'gcash'], bearer($student));
$multi = $b['checkout'] ?? null;
check('two-stall GCash checkout placed', $s === 201 && count($multi['orders'] ?? []) === 2, "HTTP $s");
$coffee = null;
$keep = null;
foreach ($multi['orders'] ?? [] as $o) {
    if ($o['stall']['id'] === 'kape-kanto') { $coffee = $o; } else { $keep = $o; }
}
[$s] = http('POST', "$K/orders/{$coffee['ref']}/cancel", null, bearer($student));
check('one order cancelled before paying', $s === 200, "HTTP $s");
[$s, $b] = http('GET', "$K/checkouts/{$multi['ref']}", null, bearer($student));
$expected = $keep['subtotal'] + $multi['service_fee'];
check('amount to pay drops to the remaining order', ($b['checkout']['total'] ?? null) == $expected, json_encode($b['checkout']['total'] ?? null) . " vs $expected");
[$s, $b] = http('POST', "$K/payments/{$multi['ref']}/confirm", ['result' => 'authorized'], bearer($student));
check('payment charges only the remaining order', $s === 200 && ($b['checkout']['total'] ?? null) == $expected && ($b['checkout']['payment_status'] ?? '') === 'paid', "HTTP $s");
[$s, $b] = http('POST', "$K/orders/{$keep['ref']}/cancel", null, bearer($student));
check('cancelling a paid order flags a refund', $s === 200 && !empty($b['order']['refund_due']), "HTTP $s");
[$s, $b] = http('POST', "$K/orders", ['items' => [['sku' => $sku, 'qty' => 1]], 'pickup_date' => $date, 'pickup_time' => $slot, 'payment_method' => 'gcash'], bearer($student));
$solo = $b['checkout']['ref'] ?? null;
http('POST', "$K/orders/{$b['checkout']['orders'][0]['ref']}/cancel", null, bearer($student));
[$s, $b] = http('POST', "$K/payments/$solo/confirm", ['result' => 'authorized'], bearer($student));
check('paying a fully cancelled checkout is refused (409)', $s === 409, "HTTP $s");

// ------------------------------------------------------------------ menu editing
section('Store console menu editing (content → Kantina, price → ERP)');
[$s, $b] = http('GET', "$K/menu-items/$sku");
$original = $b['item'] ?? [];
$marker = 'Smoke test ' . date('H:i:s');
[$s, $b] = http('PATCH', "$K/seller/menu/$sku", ['description' => $marker, 'price' => $original['price'] + 1], bearer($staff1));
check('staff can edit their own menu item', $s === 200, "HTTP $s");
[$s, $b] = http('GET', "$K/menu-items/$sku");
check('students see the new description', ($b['item']['description'] ?? '') === $marker, $b['item']['description'] ?? "HTTP $s");
[$s, $p] = http('GET', "$E/products/$sku", null, ['X-Api-Key' => $ERP_KEY]);
check('the new price is saved in the ERP', ($p['product']['price'] ?? null) == $original['price'] + 1, json_encode($p['product']['price'] ?? null));
[$s] = http('PATCH', "$K/seller/menu/$sku", ['description' => 'not mine'], bearer($staff2));
check("another stall can't edit the item (404)", $s === 404, "HTTP $s");
http('PATCH', "$K/seller/menu/$sku", ['description' => $original['description'], 'price' => $original['price']], bearer($staff1));

section('ERP webhooks: signatures and duplicates');
[$s, $p] = http('GET', "$E/products/$sku", null, ['X-Api-Key' => $ERP_KEY]);
[$body, $headers] = signed($ERP_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'product.updated', 'source' => 'erp', 'created_at' => date('c'), 'data' => ['product' => $p['product'] ?? []]]);
[$s] = http('POST', "$K/integrations/erp/webhook", $body, $headers);
check('signed ERP webhook accepted', $s === 200, "HTTP $s");
[$s, $b] = http('POST', "$K/integrations/erp/webhook", $body, $headers);
check('same event again is ignored as a duplicate', $s === 200 && ($b['status'] ?? '') === 'duplicate', "HTTP $s " . ($b['status'] ?? ''));
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

// ------------------------------------------------------------------ CRM feedback
section('CRM: feedback → tickets, replies by webhook');
[$s, $b] = http('POST', "$K/feedback", ['category' => 'order_issue', 'rating' => 3, 'message' => 'Smoke test: the order was a bit late.', 'order_ref' => $ref], bearer($student));
$fb = $b['feedback'] ?? [];
check('feedback saved (201)', $s === 201 && !empty($fb['id']), "HTTP $s " . json_encode($b['error'] ?? null));
check('feedback became a CRM ticket', !empty($fb['ticket_no']), json_encode($fb['status_label'] ?? null));
[$s, $t] = http('GET', "$C/tickets/" . ($fb['ref'] ?? 'none'), null, ['X-Api-Key' => $CRM_KEY]);
check('the CRM has the ticket for this order', $s === 200 && ($t['ticket']['order_ref'] ?? '') === $ref, "HTTP $s");
[$s] = http('POST', "$K/feedback", ['category' => 'complaint', 'message' => 'Not my order', 'order_ref' => 'KTN-00000'], bearer($student));
check("feedback about someone else's order is refused (422)", $s === 422, "HTTP $s");
$reply = 'Smoke test reply ' . bin2hex(random_bytes(3));
[$body, $headers] = signed($CRM_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'ticket.updated', 'source' => 'crm', 'created_at' => date('c'),
    'data' => ['ticket' => ['external_ref' => $fb['ref'] ?? '', 'ticket_no' => $fb['ticket_no'] ?? '', 'status' => 'resolved', 'reply' => $reply]]]);
[$s] = http('POST', "$K/integrations/crm/webhook", $body, $headers);
check('signed CRM ticket.updated webhook accepted', $s === 200, "HTTP $s");
[$s, $b] = http('GET', "$K/feedback", null, bearer($student));
$mine = array_values(array_filter($b['feedback'] ?? [], fn($f) => $f['id'] === ($fb['id'] ?? null)))[0] ?? [];
check('the student sees the reply and "resolved"', ($mine['reply'] ?? '') === $reply && ($mine['status'] ?? '') === 'resolved', json_encode([$mine['status'] ?? null, $mine['reply'] ?? null]));
[$s, $me] = http('GET', "$K/me", null, bearer($student));
$points = (int) ($me['loyalty']['points'] ?? 0);
[$body, $headers] = signed($CRM_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'customer.updated', 'source' => 'crm', 'created_at' => date('c'),
    'data' => ['customer' => ['kantina_user_id' => $me['user']['id'] ?? 0, 'points' => $points + 5, 'tier' => $me['loyalty']['tier'] ?? 'Bronze']]]);
[$s] = http('POST', "$K/integrations/crm/webhook", $body, $headers);
[$s2, $b] = http('GET', "$K/me", null, bearer($student));
check('a customer.updated webhook changes the profile points', $s === 200 && ($b['loyalty']['points'] ?? null) === $points + 5, "HTTP $s " . json_encode($b['loyalty'] ?? null));
[$body, $headers] = signed($CRM_SECRET, ['id' => 'evt_smoke_' . bin2hex(random_bytes(6)), 'event' => 'customer.updated', 'source' => 'crm', 'created_at' => date('c'),
    'data' => ['customer' => ['kantina_user_id' => $me['user']['id'] ?? 0, 'points' => $points, 'tier' => $me['loyalty']['tier'] ?? 'Bronze']]]);
http('POST', "$K/integrations/crm/webhook", $body, $headers);   // put the points back
[$s] = http('POST', "$K/integrations/crm/webhook", '{"id":"evt_fake","event":"customer.updated","data":{}}', ['X-Kantina-Signature' => 't=' . time() . ',v1=' . str_repeat('0', 64)]);
check('CRM webhook with a forged signature is rejected (401)', $s === 401, "HTTP $s");

// ------------------------------------------------------------------ analytics + status
section('Analytics & integration status');
[$s, $b] = http('GET', "$K/seller/analytics?range=7", null, bearer($staff1));
check('analytics come from the ERP', $s === 200 && ($b['source'] ?? '') === 'erp' && ($b['orders'] ?? 0) > 0, "HTTP $s");
[$s, $b] = http('GET', "$K/integrations/status", null, bearer($staff1));
check('status page sees the ERP online', !empty($b['erp']['online']), $b['erp']['error'] ?? "HTTP $s");
check('status page sees the CRM online', !empty($b['crm']['online']), $b['crm']['error'] ?? "HTTP $s");
[$s] = http('GET', "$C/whoami");
check('CRM rejects calls without API key (401)', $s === 401, "HTTP $s");
[$s, $b] = http('GET', "$C/whoami", null, ['X-Api-Key' => $CRM_KEY]);
check('CRM accepts Kantina\'s API key', $s === 200 && ($b['client'] ?? '') === 'kantina', "HTTP $s");

echo "\n" . ($failed ? "\033[31m$failed failed\033[0m, " : '') . "\033[32m$passed passed\033[0m\n";
exit($failed ? 1 : 0);

<?php
/**
 * Generates ~5 weeks of past sales for the ERP seed so the analytics
 * pages have real numbers to show. Dates are written relative to the
 * import day (DATE_SUB(CURDATE(), ...)), so the data never goes stale.
 *
 * Usage:  php database/tools/build_history.php
 * Rewrites the block between the GENERATED HISTORY markers in
 * database/kantina_erp.sql. Deterministic (fixed random seed).
 */

mt_srand(2026);

const DAYS_BACK = 35;

$prices = [
    'LS-TAPSILOG' => ['Tapsilog', 85], 'LS-LONGSILOG' => ['Longsilog', 75], 'LS-BANGSILOG' => ['Bangsilog', 90],
    'LS-TOCILOG' => ['Tocilog', 80], 'LS-ADOBO' => ['Chicken adobo with rice', 70], 'LS-SINIGANG' => ['Pork sinigang with rice', 85],
    'LS-ADD-RICE' => ['Extra rice', 15], 'LS-ADD-EGG' => ['Fried egg', 12],
    'KK-ICED-COFFEE' => ['Iced coffee', 60], 'KK-HOT-BREWED' => ['Hot brewed coffee', 45], 'KK-SPANISH' => ['Spanish latte', 85],
    'KK-ENSAYMADA' => ['Ensaymada', 35], 'KK-BANANA' => ['Banana bread', 40], 'KK-ADD-SHOT' => ['Extra espresso shot', 20],
    'KK-ADD-OAT' => ['Oat milk', 25],
    'NB-CANTON' => ['Pancit canton', 55], 'NB-MAMI' => ['Beef mami', 75], 'NB-LOMI' => ['Lomi', 70],
    'NB-ADD-EGG' => ['Boiled egg', 12], 'NB-ADD-SIOMAI' => ['Siomai (3 pcs)', 30],
    'GS-PORK-BBQ' => ['Pork barbecue', 25], 'GS-ISAW' => ['Chicken isaw', 15], 'GS-LIEMPO' => ['Liempo with rice', 95],
    'GS-INASAL' => ['Chicken inasal with rice', 99], 'GS-ADD-RICE' => ['Extra rice', 15],
];

// stall => [orders per day min, max, open hour, close hour, [sku => weight], [addon skus], [sku => max qty]]
$stalls = [
    'lolas-silog'   => [22, 34, 7, 18, ['LS-TAPSILOG' => 30, 'LS-LONGSILOG' => 20, 'LS-BANGSILOG' => 10, 'LS-TOCILOG' => 14, 'LS-ADOBO' => 14, 'LS-SINIGANG' => 10], ['LS-ADD-RICE', 'LS-ADD-EGG'], 1],
    'kape-kanto'    => [18, 30, 7, 19, ['KK-ICED-COFFEE' => 34, 'KK-HOT-BREWED' => 18, 'KK-SPANISH' => 20, 'KK-ENSAYMADA' => 16, 'KK-BANANA' => 12], ['KK-ADD-SHOT', 'KK-ADD-OAT'], 2],
    'noodle-bar'    => [8, 16, 14, 19, ['NB-CANTON' => 30, 'NB-MAMI' => 40, 'NB-LOMI' => 30], ['NB-ADD-EGG', 'NB-ADD-SIOMAI'], 1],
    'grill-station' => [8, 16, 13, 19, ['GS-PORK-BBQ' => 34, 'GS-ISAW' => 22, 'GS-LIEMPO' => 22, 'GS-INASAL' => 22], ['GS-ADD-RICE'], 4],
];

// Canteen traffic by hour of day
$hourWeights = [7 => 4, 8 => 6, 9 => 5, 10 => 6, 11 => 14, 12 => 16, 13 => 8, 14 => 5, 15 => 7, 16 => 6, 17 => 5, 18 => 3];

function pick(array $weights)
{
    $r = mt_rand(1, array_sum($weights));
    foreach ($weights as $key => $w) {
        if (($r -= $w) <= 0) return $key;
    }
    return array_key_first($weights);
}

function ts(int $daysAgo, string $time): string
{
    return "TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL {$daysAgo} DAY), '{$time}')";
}

$orders = [];   // [id, channel, ref, stall, total, daysAgo, time]
$lines = [];    // [orderId, sku, name, qty, price]
$id = 0;

for ($d = DAYS_BACK; $d >= 1; $d--) {
    foreach ($stalls as $code => [$min, $max, $open, $close, $menu, $addons, $maxQty]) {
        $n = mt_rand($min, $max);
        $hours = array_filter($hourWeights, fn($h) => $h >= $open && $h < $close, ARRAY_FILTER_USE_KEY);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $time = sprintf('%02d:%02d:%02d', pick($hours), mt_rand(0, 59), mt_rand(0, 59));
            $rows[] = $time;
        }
        sort($rows);
        foreach ($rows as $time) {
            $id++;
            $total = 0;
            $count = mt_rand(1, 100) <= 75 ? 1 : 2;
            $chosen = [];
            for ($k = 0; $k < $count; $k++) {
                $sku = pick($menu);
                if (isset($chosen[$sku])) continue;
                $qty = mt_rand(1, 100) <= 80 ? 1 : mt_rand(2, max(2, $maxQty));
                $chosen[$sku] = $qty;
            }
            foreach ($chosen as $sku => $qty) {
                [$name, $price] = $prices[$sku];
                $lines[] = [$id, $sku, $name, $qty, $price];
                $total += $qty * $price;
            }
            if (mt_rand(1, 100) <= 30) {
                $sku = $addons[array_rand($addons)];
                [$name, $price] = $prices[$sku];
                $lines[] = [$id, $sku, $name, 1, $price];
                $total += $price;
            }
            $orders[] = [$id, 'walk_in', null, $code, $total, $d, $time];
        }
    }
}

// Two earlier online orders that also exist in the Kantina database (Ana's order history)
$id++;
$orders[] = [$id, 'kantina', 'KTN-90155', 'kape-kanto', 60, 5, '09:20:00'];
$lines[] = [$id, 'KK-ICED-COFFEE', 'Iced coffee', 1, 60];
$id++;
$orders[] = [$id, 'kantina', 'KTN-90180', 'lolas-silog', 150, 2, '12:10:00'];
$lines[] = [$id, 'LS-LONGSILOG', 'Longsilog', 2, 75];

$sql = "-- BEGIN GENERATED HISTORY (database/tools/build_history.php)\n";
$sql .= "-- " . count($orders) . " past sales orders, " . count($lines) . " lines. Do not edit by hand.\n";
foreach (array_chunk($orders, 250) as $chunk) {
    $sql .= "INSERT INTO erp_sales_orders (id, so_no, channel, external_ref, stall_code, status, total, created_at, paid_at) VALUES\n";
    $vals = [];
    foreach ($chunk as [$oid, $channel, $ref, $stall, $total, $d, $time]) {
        $so = sprintf("'SO-%06d'", $oid);
        $refSql = $ref === null ? 'NULL' : "'{$ref}'";
        $vals[] = "({$oid},{$so},'{$channel}',{$refSql},'{$stall}','paid',{$total}," . ts($d, $time) . ',' . ts($d, $time) . ')';
    }
    $sql .= implode(",\n", $vals) . ";\n";
}
foreach (array_chunk($lines, 400) as $chunk) {
    $sql .= "INSERT INTO erp_sales_order_lines (sales_order_id, sku, name, qty, unit_price, line_total) VALUES\n";
    $vals = [];
    foreach ($chunk as [$oid, $sku, $name, $qty, $price]) {
        $vals[] = "({$oid},'{$sku}','" . str_replace("'", "''", $name) . "',{$qty},{$price}," . ($qty * $price) . ')';
    }
    $sql .= implode(",\n", $vals) . ";\n";
}
$sql .= "-- END GENERATED HISTORY\n";

$file = __DIR__ . '/../kantina_erp.sql';
$content = file_get_contents($file);
$content = preg_replace(
    '/-- BEGIN GENERATED HISTORY.*?-- END GENERATED HISTORY\n?/s',
    str_replace(['\\', '$'], ['\\\\', '\\$'], $sql),
    $content,
    1,
    $count
);
if ($count !== 1) {
    fwrite(STDERR, "Markers not found in kantina_erp.sql\n");
    exit(1);
}
file_put_contents($file, $content);
echo 'Wrote ' . count($orders) . ' orders / ' . count($lines) . " lines into kantina_erp.sql\n";

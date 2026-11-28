<?php
/**
 * Store console API for stall staff. Every endpoint is limited to the
 * signed-in staff member's own stall.
 *
 *   Orders board   pending → paid → preparing → ready → completed
 *   Menu           price / stock / availability → Kantina ERP
 *                  name / description / section → saved in Kantina
 *   Tables         floor map, block slots for cleaning or events
 *   Analytics      revenue, orders and top items from the ERP sales ledger
 */
final class Seller
{
    private const TRANSITIONS = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['preparing', 'cancelled'],
        'preparing' => ['ready'],
        'ready' => ['completed'],
    ];

    public static function routes(Router $r): void
    {
        $staff = [Auth::staff()];
        $r->get('/seller/orders', [self::class, 'orders'], $staff);
        $r->patch('/seller/orders/{id}/status', [self::class, 'updateStatus'], $staff);
        $r->get('/seller/menu', [self::class, 'menu'], $staff);
        $r->post('/seller/menu', [self::class, 'createItem'], $staff);
        $r->patch('/seller/menu/{sku}', [self::class, 'updateItem'], $staff);
        $r->get('/seller/tables', [self::class, 'tables'], $staff);
        $r->post('/seller/tables/{code}/block', [self::class, 'block'], $staff);
        $r->delete('/seller/tables/{code}/block', [self::class, 'unblock'], $staff);
        $r->get('/seller/analytics', [self::class, 'analytics'], $staff);
    }

    private static function stallInfo(string $stallId): array
    {
        $s = Db::one('SELECT id, name, counter_label FROM stalls WHERE id = ?', [$stallId]);
        return ['id' => $s['id'], 'name' => $s['name'], 'counter' => $s['counter_label']];
    }

    // ===================================================================== orders
    public static function orders(Request $req): array
    {
        $stall = Auth::stallOf($req);
        Orders::expireUnpaid();
        Outbox::tick(1);   // deliver one waiting ERP message, if any (the board polls often)
        $rows = Db::all(
            Orders::orderSql() . " WHERE o.stall_id = ?
              AND (o.status IN ('pending', 'paid', 'preparing', 'ready') OR (o.status = 'completed' AND o.completed_at >= CURDATE()))
              ORDER BY FIELD(o.status, 'pending', 'paid', 'preparing', 'ready', 'completed'), o.pickup_date, o.pickup_time, o.id",
            [$stall]
        );
        $items = Orders::itemsFor(array_map(fn($o) => (int) $o['id'], $rows));
        $orders = array_map(fn($o) => self::formatOrder($o, $items[$o['id']] ?? []), $rows);
        return [
            'stall' => self::stallInfo($stall),
            'active_count' => count(array_filter($orders, fn($o) => $o['status'] !== 'completed')),
            'completed_today' => count(array_filter($orders, fn($o) => $o['status'] === 'completed')),
            'orders' => $orders,
        ];
    }

    public static function formatOrder(array $o, array $items): array
    {
        $changed = strtotime($o['status_changed_at']);
        $pickup = strtotime($o['pickup_date'] . ' ' . $o['pickup_time']);
        return [
            'id' => (int) $o['id'],
            'ref' => $o['ref'],
            'status' => $o['status'],
            'customer' => Support::shortName($o['customer_name']),
            'items_text' => Orders::itemsText($items),
            'items' => array_map(fn($i) => [
                'name' => $i['name'],
                'qty' => (int) $i['qty'],
                'addons' => array_column(json_decode((string) $i['addons'], true) ?: [], 'name'),
                'note' => $i['note'],
            ], $items),
            'total' => money($o['subtotal']),
            'payment_method' => $o['payment_method'],
            'paid' => in_array($o['status'], ['paid', 'preparing', 'ready', 'completed'], true),
            'can_accept' => $o['status'] === 'pending' && $o['payment_method'] === 'counter',
            'pickup_date' => $o['pickup_date'],
            'pickup_day' => Support::dayLabel($o['pickup_date']),
            'pickup_time' => hm($o['pickup_time']),
            'table' => $o['table_code'],
            'placed_at' => iso($o['placed_at']),
            'status_changed_at' => iso($o['status_changed_at']),
            'minutes_in_status' => max(0, (int) floor((time() - $changed) / 60)),
            'minutes_until_pickup' => (int) floor(($pickup - time()) / 60),
        ];
    }

    public static function updateStatus(Request $req): array
    {
        $stall = Auth::stallOf($req);
        $order = Db::one(Orders::orderSql() . ' WHERE o.id = ? AND o.stall_id = ?', [(int) $req->params['id'], $stall]);
        if (!$order) {
            throw new HttpError(404, 'order_not_found', 'Order not found for this stall.');
        }
        $d = Validator::check($req->body(), ['status' => 'required|in:paid,preparing,ready,completed,cancelled']);
        $to = $d['status'];
        $from = $order['status'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new HttpError(409, 'invalid_transition', "Order #{$order['id']} is $from — it can't be marked $to.");
        }
        if ($from === 'pending' && $to === 'paid' && $order['payment_method'] !== 'counter') {
            throw new HttpError(409, 'awaiting_online_payment', "Order #{$order['id']} is waiting for the student's GCash payment.");
        }

        if ($to === 'cancelled') {
            if (!Orders::cancelOrder($order, 'Cancelled by ' . $order['stall_name'], ['pending', 'paid'])) {
                throw new HttpError(409, 'invalid_transition', "Order #{$order['id']} changed in the meantime — refresh the board.");
            }
        } else {
            $column = ['paid' => 'paid_at', 'preparing' => 'preparing_at', 'ready' => 'ready_at', 'completed' => 'completed_at'][$to];
            Db::tx(function () use ($order, $from, $to, $column) {
                // Lock checkout, then order (same order as payments/cancellations), and re-check the status.
                Db::one('SELECT id FROM checkouts WHERE id = ? FOR UPDATE', [$order['checkout_id']]);
                $current = Db::value('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
                if ($current !== $from) {
                    throw new HttpError(409, 'invalid_transition', "Order #{$order['id']} changed in the meantime — refresh the board.");
                }
                Db::exec("UPDATE orders SET status = ?, $column = NOW(), status_changed_at = NOW() WHERE id = ?", [$to, $order['id']]);
                if ($to === 'paid') {
                    // Cash received at the counter: record it in the ERP ledger.
                    Outbox::enqueue('erp.sales_order.pay', $order['ref']);
                    if (!Db::value("SELECT 1 FROM orders WHERE checkout_id = ? AND status = 'pending' LIMIT 1", [$order['checkout_id']])) {
                        Db::exec("UPDATE checkouts SET payment_status = 'paid', paid_at = NOW() WHERE id = ? AND payment_status = 'unpaid'", [$order['checkout_id']]);
                    }
                }
                if ($to === 'completed') {
                    // Picked up: the student earns loyalty points in Kantina CRM (same transaction, so it can't be lost).
                    Crm::enqueueOrderCompleted($order);
                }
            });
            if ($to === 'paid') {
                Outbox::process([$order['ref']]);
            }
            if ($to === 'completed') {
                // Try right away; if the CRM is offline the outbox retries later.
                Outbox::process([Crm::orderOutboxRef($order['ref'])], 1, 3);
            }
            if ($to === 'ready' && class_exists('Notifier')) {
                Notifier::orderReady((int) $order['id']);
            }
        }
        $fresh = Db::one(Orders::orderSql() . ' WHERE o.id = ?', [$order['id']]);
        return ['order' => self::formatOrder($fresh, Orders::itemsFor([(int) $fresh['id']])[$fresh['id']] ?? [])];
    }

    // ===================================================================== menu
    public static function menu(Request $req): array
    {
        $stall = Auth::stallOf($req);
        $rows = Db::all("SELECT * FROM menu_items WHERE stall_id = ? ORDER BY type = 'addon', sort_order, name", [$stall]);
        return ['stall' => self::stallInfo($stall), 'items' => array_map([self::class, 'formatMenuItem'], $rows)];
    }

    public static function formatMenuItem(array $row): array
    {
        return [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'description' => $row['description'],
            'section' => $row['type'] === 'addon' ? 'Add-ons' : $row['section'],
            'type' => $row['type'],
            'price' => money($row['price']),
            'stock' => (int) $row['track_stock'] ? (int) $row['stock'] : null,
            'track_stock' => (bool) (int) $row['track_stock'],
            'available' => (bool) (int) $row['is_active'],
            'sold_out' => Catalog::isSoldOut($row),
            'published' => (bool) (int) $row['is_published'],
            'photo_url' => Catalog::mediaUrl($row['photo_path']),
        ];
    }

    private static function ownItem(string $stall, string $sku): array
    {
        $row = Db::one('SELECT * FROM menu_items WHERE sku = ? AND stall_id = ?', [$sku, $stall]);
        if (!$row) {
            throw new HttpError(404, 'item_not_found', 'That item is not on your menu.');
        }
        return $row;
    }

    /** New item: created in the ERP (gets its SKU, price, stock), then described in Kantina (section, description). */
    public static function createItem(Request $req): array
    {
        $stall = Auth::stallOf($req);
        $d = Validator::check($req->body(), [
            'name' => 'required|string|min:2|max:80',
            'price' => 'required|number|min:0|max:10000',
            'stock' => 'int|min:0|max:10000',
            'available' => 'bool',
            'description' => 'string|max:2000',
            'section' => 'string|max:60',
            'type' => 'in:menu,addon',
        ]);
        $type = $d['type'] ?? 'menu';
        $erp = ErpClient::must('POST', '/products', [
            'stall_code' => $stall,
            'name' => $d['name'],
            'type' => $type,
            'price' => $d['price'],
            'stock' => $d['stock'] ?? 0,
            'track_stock' => $type === 'menu',
            'is_active' => $d['available'] ?? true,
        ]);
        $product = $erp['product'];
        Catalog::applyErpProduct($product);
        Catalog::saveContent($product['sku'], [
            'name' => $d['name'],
            'section' => $d['section'] ?? ($type === 'addon' ? 'Add-ons' : 'Menu'),
            'description' => $d['description'] ?? null,
        ]);
        return [['item' => self::formatMenuItem(self::ownItem($stall, $product['sku']))], 201];
    }

    public static function updateItem(Request $req): array
    {
        $stall = Auth::stallOf($req);
        $row = self::ownItem($stall, $req->params['sku']);
        $d = Validator::check($req->body(), [
            'price' => 'number|min:0|max:10000',
            'stock' => 'int|min:0|max:10000',
            'available' => 'bool',
            'name' => 'string|min:2|max:80',
            'description' => 'string|max:2000',
            'section' => 'string|max:60',
        ]);
        $erpFields = [];
        foreach (['price' => 'price', 'stock' => 'stock', 'available' => 'is_active', 'name' => 'name'] as $in => $out) {
            if (array_key_exists($in, $d)) {
                $erpFields[$out] = $d[$in];
            }
        }
        if (isset($erpFields['stock']) && !(int) $row['track_stock']) {
            unset($erpFields['stock']);
        }
        if ($erpFields) {
            $erp = ErpClient::must('PATCH', '/products/' . rawurlencode($row['sku']), $erpFields);
            Catalog::applyErpProduct($erp['product']);
        }
        Catalog::saveContent($row['sku'], $d);
        return ['item' => self::formatMenuItem(self::ownItem($stall, $row['sku']))];
    }

    // ===================================================================== tables
    public static function tables(Request $req): array
    {
        Auth::stallOf($req);
        Reservations::housekeeping();
        $days = Reservations::days();
        $date = $req->query['date'] ?? date('Y-m-d');
        $slots = Reservations::slots();
        $reservations = Db::all(
            "SELECT r.*, u.name AS customer FROM reservations r JOIN users u ON u.id = r.user_id
             WHERE r.res_date = ? AND r.status IN ('booked', 'checked_in', 'completed', 'released') ORDER BY r.start_time",
            [$date]
        );
        $blocks = [];
        foreach (Db::all('SELECT * FROM table_blocks WHERE block_date = ?', [$date]) as $b) {
            $blocks[$b['table_code']][hm($b['slot_time'])] = $b['reason'];
        }
        $grace = (int) Config::get('reservations.grace_minutes', 10);
        $summary = ['free' => 0, 'booked' => 0, 'held' => 0, 'seated' => 0, 'blocked' => 0];
        $tables = [];
        foreach (Db::all('SELECT * FROM dining_tables ORDER BY row_no, col_no') as $t) {
            $cells = [];
            foreach ($slots as $slot) {
                $slotEnd = Support::addMinutes($slot, 30);
                $cell = ['time' => $slot, 'status' => 'free', 'label' => null, 'reservation_ref' => null, 'reason' => null];
                if (isset($blocks[$t['code']][$slot])) {
                    $cell['status'] = 'blocked';
                    $cell['reason'] = $blocks[$t['code']][$slot];
                } else {
                    foreach ($reservations as $r) {
                        if ($r['table_code'] !== $t['code'] || $r['status'] === 'released'
                            || !(hm($r['start_time']) < $slotEnd && hm($r['end_time']) > $slot)) {
                            continue;
                        }
                        $start = strtotime("{$r['res_date']} {$r['start_time']}");
                        if ($r['status'] === 'booked') {
                            $cell['status'] = (time() >= $start && time() <= $start + $grace * 60) ? 'held' : 'booked';
                        } else {
                            $cell['status'] = 'seated';
                        }
                        $cell['label'] = Support::shortName($r['customer']);
                        $cell['reservation_ref'] = $r['ref'];
                        break;
                    }
                }
                $summary[$cell['status']]++;
                $cells[] = $cell;
            }
            $tables[] = [
                'code' => $t['code'],
                'seats' => (int) $t['seats'],
                'row' => (int) $t['row_no'],
                'col' => (int) $t['col_no'],
                'slots' => $cells,
            ];
        }
        $noShows = array_values(array_map(fn($r) => [
            'ref' => $r['ref'],
            'table' => $r['table_code'],
            'time' => hm($r['start_time']),
            'name' => Support::shortName($r['customer']),
        ], array_filter($reservations, fn($r) => $r['status'] === 'released')));
        return [
            'date' => $date,
            'date_label' => Support::dayLabel($date),
            'days' => $days,
            'slots' => $slots,
            'tables' => $tables,
            'no_shows' => $noShows,
            'summary' => $summary,
        ];
    }

    public static function block(Request $req): array
    {
        Auth::stallOf($req);
        $code = strtoupper($req->params['code']);
        if (!Db::value('SELECT 1 FROM dining_tables WHERE code = ?', [$code])) {
            throw new HttpError(404, 'table_not_found', 'That table does not exist.');
        }
        $d = Validator::check($req->body(), ['date' => 'required|date', 'time' => 'required|time', 'reason' => 'string|max:80']);
        if (!in_array($d['time'], Reservations::slots(), true)) {
            throw new HttpError(422, 'validation_error', 'Choose one of the time slots.', ['time' => 'Not a slot.']);
        }
        $end = Support::addMinutes($d['time'], 30);
        $holder = Db::one(
            "SELECT r.ref, u.name FROM reservations r JOIN users u ON u.id = r.user_id
             WHERE r.table_code = ? AND r.res_date = ? AND r.status IN ('booked', 'checked_in') AND r.start_time < ? AND r.end_time > ?",
            [$code, $d['date'], "$end:00", "{$d['time']}:00"]
        );
        if ($holder) {
            throw new HttpError(409, 'slot_reserved', "$code is reserved by " . Support::shortName($holder['name']) . " at {$d['time']} ({$holder['ref']}).");
        }
        Db::exec(
            'INSERT INTO table_blocks (table_code, block_date, slot_time, reason, created_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [$code, $d['date'], $d['time'] . ':00', $d['reason'] ?? 'Blocked', $req->user['id']]
        );
        return [['block' => ['table' => $code, 'date' => $d['date'], 'time' => $d['time'], 'reason' => $d['reason'] ?? 'Blocked']], 201];
    }

    public static function unblock(Request $req): array
    {
        Auth::stallOf($req);
        $d = Validator::check($req->query, ['date' => 'required|date', 'time' => 'required|time']);
        $deleted = Db::exec(
            'DELETE FROM table_blocks WHERE table_code = ? AND block_date = ? AND slot_time = ?',
            [strtoupper($req->params['code']), $d['date'], $d['time'] . ':00']
        );
        return ['unblocked' => $deleted > 0];
    }

    // ===================================================================== analytics
    public static function analytics(Request $req): array
    {
        $stall = Auth::stallOf($req);
        $range = (int) ($req->query['range'] ?? 7) === 30 ? 30 : 7;
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));
        $qs = http_build_query(['stall' => $stall, 'from' => $from, 'to' => $to]);
        $sales = ErpClient::must('GET', "/reports/sales?$qs");
        $top = ErpClient::must('GET', "/reports/top-items?$qs&limit=5");

        $totalOrders = max(1, (int) $sales['totals']['orders']);
        $hours = $sales['by_hour'];
        usort($hours, fn($a, $b) => $b['orders'] <=> $a['orders']);
        $peak = array_map(fn($h) => [
            'label' => sprintf('%02d:00–%02d:00', $h['hour'], $h['hour'] + 1),
            'hour' => $h['hour'],
            'orders' => $h['orders'],
            'share' => (int) round($h['orders'] / $totalOrders * 100),
        ], array_slice($hours, 0, 3));

        return [
            'stall' => self::stallInfo($stall),
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'source' => 'erp',
            'revenue' => $sales['totals']['revenue'],
            'orders' => $sales['totals']['orders'],
            'avg_ticket' => round($sales['totals']['avg_ticket']),
            'by_day' => array_map(fn($d) => ['date' => $d['date'], 'label' => $d['label'], 'revenue' => $d['revenue'], 'orders' => $d['orders']], $sales['by_day']),
            'by_channel' => $sales['by_channel'],
            'top_items' => array_map(fn($i) => ['name' => $i['name'], 'qty' => $i['qty'], 'revenue' => $i['revenue']], $top['items']),
            'peak_hours' => $peak,
        ];
    }
}

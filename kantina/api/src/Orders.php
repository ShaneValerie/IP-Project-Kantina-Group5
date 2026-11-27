<?php
/**
 * Ordering: pickup slots, checkout, orders, demo payment, cancellation.
 *
 * One checkout (one payment) contains one order per stall, because each
 * stall cooks and hands out its own food. Order status workflow:
 *
 *   pending ──pay──▶ paid ──▶ preparing ──▶ ready ──▶ completed
 *      └──────────────┴──▶ cancelled
 *
 * Every order is mirrored in Kantina ERP as a sales order via the Outbox.
 */
final class Orders
{
    public const STEPS = ['pending' => 1, 'paid' => 2, 'preparing' => 3, 'ready' => 4, 'completed' => 5];
    public const HEADLINES = [
        'pending' => 'We got your order',
        'paid' => 'Payment received',
        'preparing' => 'Your food is cooking',
        'ready' => 'Your food is ready!',
        'completed' => 'Enjoy your meal!',
        'cancelled' => 'Order cancelled',
    ];
    private const UNPAID_EXPIRY_MINUTES = 15;

    public static function routes(Router $r): void
    {
        $r->get('/pickup-slots', [self::class, 'pickupSlots']);
        $r->post('/orders', [self::class, 'create'], [Auth::student()]);
        $r->get('/orders', [self::class, 'index'], [Auth::student()]);
        $r->get('/orders/{ref}', fn(Request $q) => ['order' => self::formatDetail(self::own($q, $q->params['ref']))], [Auth::student()]);
        $r->post('/orders/{ref}/cancel', [self::class, 'cancelByStudent'], [Auth::student()]);
        $r->get('/checkouts/{ref}', fn(Request $q) => ['checkout' => self::formatCheckout(self::ownCheckout($q, $q->params['ref']))], [Auth::student()]);
        $r->post('/payments/{ref}/confirm', [self::class, 'confirmPayment'], [Auth::student()]);
    }

    // ===================================================================== slots
    public static function pickupSlots(Request $req): array
    {
        $stalls = self::stallRows(array_filter(explode(',', (string) ($req->query['stalls'] ?? ''))));
        $days = [];
        for ($i = 0; $i < 3; $i++) {
            $d = date('Y-m-d', strtotime("+$i day"));
            $days[] = ['date' => $d, 'label' => Support::dayLabel($d)];
        }
        $date = $req->query['date'] ?? null;
        if ($date !== null && !in_array($date, array_column($days, 'date'), true)) {
            throw new HttpError(422, 'validation_error', 'Pickup can be today or the next 2 days.', ['date' => 'Out of range.']);
        }
        if ($date === null) {
            // Today if anything is left, otherwise tomorrow.
            $date = $days[0]['date'];
            if (!self::anyAvailable(self::slotsFor($date, $stalls))) {
                $date = $days[1]['date'];
            }
        }
        return [
            'date' => $date,
            'date_label' => Support::dayLabel($date),
            'days' => $days,
            'periods' => self::slotsFor($date, $stalls),
        ];
    }

    private static function anyAvailable(array $periods): bool
    {
        foreach ($periods as $p) {
            foreach ($p['slots'] as $s) {
                if ($s['available']) return true;
            }
        }
        return false;
    }

    /** @param array $stalls stall rows (from the stalls table) the order includes */
    private static function slotsFor(string $date, array $stalls): array
    {
        $step = (int) Config::get('pickup.slot_minutes', 15);
        $capacity = (int) Config::get('pickup.capacity_per_stall', 8);
        $earliest = time() + (int) Config::get('pickup.lead_minutes', 10) * 60;
        $counts = [];
        if ($stalls) {
            $ids = array_keys($stalls);
            $rows = Db::all(
                "SELECT stall_id, pickup_time, COUNT(*) AS n FROM orders WHERE pickup_date = ? AND status <> 'cancelled'
                 AND stall_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY stall_id, pickup_time',
                array_merge([$date], $ids)
            );
            foreach ($rows as $row) {
                $counts[$row['stall_id']][hm($row['pickup_time'])] = (int) $row['n'];
            }
        }
        $periods = [];
        foreach (Config::get('pickup.periods') as $period) {
            $slots = [];
            for ($t = strtotime("$date {$period['from']}"); $t < strtotime("$date {$period['to']}"); $t += $step * 60) {
                $time = date('H:i', $t);
                $available = $t >= $earliest;
                foreach ($stalls as $s) {
                    if ((int) $s['temporarily_closed'] || "$time:00" < $s['opens_at'] || "$time:00" >= $s['closes_at']
                        || ($counts[$s['id']][$time] ?? 0) >= $capacity) {
                        $available = false;
                    }
                }
                $slots[] = ['time' => $time, 'label' => Support::clock($time), 'available' => $available];
            }
            $periods[] = ['name' => $period['name'], 'slots' => $slots];
        }
        return $periods;
    }

    private static function assertSlot(string $date, string $time, array $stalls): void
    {
        foreach (self::slotsFor($date, $stalls) as $period) {
            foreach ($period['slots'] as $slot) {
                if ($slot['time'] === $time) {
                    if ($slot['available']) {
                        return;
                    }
                    if (strtotime("$date $time") < time() + (int) Config::get('pickup.lead_minutes', 10) * 60) {
                        throw new HttpError(409, 'slot_unavailable', 'That pickup time has already passed — please choose a later one.');
                    }
                    foreach ($stalls as $s) {
                        if ("$time:00" < $s['opens_at'] || "$time:00" >= $s['closes_at']) {
                            throw new HttpError(409, 'slot_unavailable', "{$s['name']} is closed at " . Support::clockAmPm($time) . '. Please choose another pickup time.');
                        }
                    }
                    throw new HttpError(409, 'slot_full', 'That pickup time is fully booked — please choose another one.');
                }
            }
        }
        throw new HttpError(422, 'validation_error', 'Choose one of the pickup times shown.', ['pickup_time' => 'Not a pickup slot.']);
    }

    /** @return array<string, array> stall rows keyed by id */
    private static function stallRows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::all('SELECT * FROM stalls WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return array_column($rows, null, 'id');
    }

    // ===================================================================== checkout
    public static function create(Request $req): array
    {
        self::expireUnpaid();
        $user = $req->user;
        $d = Validator::check($req->body(), [
            'items' => 'required|array',
            'pickup_date' => 'date',
            'pickup_time' => 'required|time',
            'payment_method' => 'required|in:gcash,counter',
            'reservation_ref' => 'string|max:20',
        ]);
        $items = array_values($d['items']);
        if (count($items) < 1 || count($items) > 30) {
            throw new HttpError(422, 'validation_error', 'Your tray needs between 1 and 30 items.', ['items' => 'Invalid size.']);
        }
        $date = $d['pickup_date'] ?? date('Y-m-d');
        if ($date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+2 days'))) {
            throw new HttpError(422, 'validation_error', 'Pickup can be today or the next 2 days.', ['pickup_date' => 'Out of range.']);
        }

        // ---- validate every line against the catalog (never trust prices from the browser)
        $byStall = [];
        $stallRows = [];
        $qtyBySku = [];
        foreach ($items as $i => $raw) {
            $it = Validator::check(is_array($raw) ? $raw : [], [
                'sku' => 'required|string|max:40',
                'qty' => 'required|int|min:1|max:20',
                'addons' => 'array',
                'note' => 'string|max:200',
            ]);
            $row = Db::one('SELECT * FROM menu_items WHERE sku = ? AND is_published = 1', [$it['sku']]);
            if (!$row) {
                throw new HttpError(422, 'item_not_found', 'An item in your tray is no longer on the menu.', ['sku' => $it['sku']]);
            }
            $stallRows[$row['stall_id']] = $stallRows[$row['stall_id']] ?? Catalog::stall($row['stall_id']);
            $stall = Catalog::formatStall($stallRows[$row['stall_id']]);
            if (!$stall['accepts_orders']) {
                throw new HttpError(409, 'stall_closed', "{$stall['name']} is not taking orders right now.", ['sku' => $row['sku']]);
            }
            if (!(int) $row['is_active'] || $row['erp_synced_at'] === null) {
                throw new HttpError(409, 'not_available', "{$row['name']} is not available right now.", ['sku' => $row['sku']]);
            }
            $qtyBySku[$row['sku']] = ($qtyBySku[$row['sku']] ?? 0) + $it['qty'];
            if ((int) $row['track_stock'] && (int) $row['stock'] < $qtyBySku[$row['sku']]) {
                throw new HttpError(409, 'sold_out', (int) $row['stock'] <= 0
                    ? "{$row['name']} is sold out today."
                    : "Only {$row['stock']} {$row['name']} left — please lower the quantity.", ['sku' => $row['sku']]);
            }
            $allowed = array_filter(array_map('trim', explode(',', (string) $row['addon_skus'])));
            $addons = [];
            foreach (array_unique($it['addons'] ?? []) as $addonSku) {
                $addon = in_array($addonSku, $allowed, true) ? Db::one('SELECT * FROM menu_items WHERE sku = ? AND is_published = 1', [$addonSku]) : null;
                if (!$addon || !Catalog::isAvailable($addon, $stall)) {
                    throw new HttpError(422, 'invalid_addon', "An add-on for {$row['name']} is not available.", ['sku' => $addonSku]);
                }
                $qtyBySku[$addon['sku']] = ($qtyBySku[$addon['sku']] ?? 0) + $it['qty'];
                $addons[] = ['sku' => $addon['sku'], 'name' => $addon['name'], 'price' => money($addon['price'])];
            }
            $unit = money($row['price']);
            $addonsTotal = array_sum(array_column($addons, 'price'));
            $byStall[$row['stall_id']][] = [
                'sku' => $row['sku'], 'name' => $row['name'], 'qty' => $it['qty'], 'unit_price' => $unit,
                'addons' => $addons, 'addons_total' => $addonsTotal, 'note' => $it['note'] ?? null,
                'line_total' => round(($unit + $addonsTotal) * $it['qty'], 2),
            ];
        }
        self::assertSlot($date, $d['pickup_time'], $stallRows);

        // ---- optional table reservation for dine-in
        $reservation = null;
        if (!empty($d['reservation_ref'])) {
            $reservation = Db::one('SELECT * FROM reservations WHERE ref = ? AND user_id = ?', [$d['reservation_ref'], $user['id']]);
            if (!$reservation) {
                throw new HttpError(404, 'reservation_not_found', 'That table reservation was not found.');
            }
            if (!in_array($reservation['status'], ['booked', 'checked_in'], true) || $reservation['res_date'] !== $date) {
                throw new HttpError(409, 'reservation_mismatch', 'Your table reservation is not for this pickup day.');
            }
        }

        // ---- save checkout + one order per stall + ERP messages, all in one transaction
        $subtotal = 0;
        foreach ($byStall as $lines) {
            $subtotal += array_sum(array_column($lines, 'line_total'));
        }
        $fee = money(Config::get('service_fee', 5));
        $refs = [];
        $checkoutId = Db::tx(function () use ($user, $d, $date, $byStall, $subtotal, $fee, $reservation, &$refs) {
            $checkoutId = Db::insert('checkouts', [
                'user_id' => $user['id'],
                'payment_method' => $d['payment_method'],
                'subtotal' => $subtotal,
                'service_fee' => $fee,
                'total' => $subtotal + $fee,
                'pickup_date' => $date,
                'pickup_time' => $d['pickup_time'] . ':00',
                'reservation_id' => $reservation['id'] ?? null,
            ]);
            Db::exec('UPDATE checkouts SET ref = ? WHERE id = ?', ['CHK-' . $checkoutId, $checkoutId]);
            foreach ($byStall as $stallId => $lines) {
                $orderId = Db::insert('orders', [
                    'checkout_id' => $checkoutId,
                    'user_id' => $user['id'],
                    'stall_id' => $stallId,
                    'status' => 'pending',
                    'subtotal' => array_sum(array_column($lines, 'line_total')),
                    'pickup_date' => $date,
                    'pickup_time' => $d['pickup_time'] . ':00',
                ]);
                $ref = 'KTN-' . $orderId;
                Db::exec('UPDATE orders SET ref = ? WHERE id = ?', [$ref, $orderId]);
                $erpLines = [];
                foreach ($lines as $line) {
                    Db::insert('order_items', [
                        'order_id' => $orderId, 'sku' => $line['sku'], 'name' => $line['name'], 'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'], 'addons' => json_encode($line['addons'], JSON_UNESCAPED_UNICODE),
                        'addons_total' => $line['addons_total'], 'note' => $line['note'], 'line_total' => $line['line_total'],
                    ]);
                    self::addErpLine($erpLines, $line['sku'], $line['qty'], $line['unit_price']);
                    foreach ($line['addons'] as $addon) {
                        self::addErpLine($erpLines, $addon['sku'], $line['qty'], $addon['price']);
                    }
                }
                Outbox::enqueue('erp.sales_order.create', $ref, [
                    'external_ref' => $ref,
                    'stall_code' => $stallId,
                    'channel' => 'kantina',
                    'lines' => array_values($erpLines),
                ]);
                $refs[] = $ref;
            }
            return $checkoutId;
        });

        // ---- tell the ERP now (reserves the stock). If it is offline, the outbox retries later.
        foreach (Outbox::process($refs) as $result) {
            if ($result['status'] === 'rejected') {
                self::cancelCheckout($checkoutId, 'Not enough stock: ' . $result['message']);
                if (!empty($result['sku'])) {
                    $p = ErpClient::call('GET', '/products/' . rawurlencode($result['sku']), null, 3);
                    if ($p['ok']) {
                        Catalog::applyErpProduct($p['body']['product']);
                    }
                }
                throw new HttpError(409, 'sold_out', str_replace('Kantina ERP: ', '', $result['message']) . ' Your tray was not charged.', ['sku' => $result['sku']]);
            }
        }
        if ($d['payment_method'] === 'counter' && class_exists('Notifier')) {
            Notifier::orderReceived($checkoutId);
        }
        return [['checkout' => self::formatCheckout(self::checkoutRow($checkoutId))], 201];
    }

    private static function addErpLine(array &$lines, string $sku, int $qty, float $price): void
    {
        $key = $sku . '@' . $price;
        if (isset($lines[$key])) {
            $lines[$key]['qty'] += $qty;
        } else {
            $lines[$key] = ['sku' => $sku, 'qty' => $qty, 'unit_price' => $price];
        }
    }

    public static function confirmPayment(Request $req): array
    {
        $checkout = self::ownCheckout($req, $req->params['ref']);
        $d = Validator::check($req->body(), ['result' => 'required|in:authorized,cancelled']);
        if ($checkout['payment_method'] !== 'gcash') {
            throw new HttpError(409, 'not_online_payment', 'This order is paid at the counter.');
        }
        $id = (int) $checkout['id'];
        if ($d['result'] === 'cancelled') {
            if ($checkout['payment_status'] === 'paid') {
                throw new HttpError(409, 'already_paid', 'This order is already paid.');
            }
            self::cancelCheckout($id, 'Payment was cancelled', ['pending']);
            return ['checkout' => self::formatCheckout(self::checkoutRow($id))];
        }

        $refs = [];
        // Lock the checkout so a payment and an expiry/cancel can't both win.
        $outcome = Db::tx(function () use ($id, &$refs) {
            $c = Db::one('SELECT * FROM checkouts WHERE id = ? FOR UPDATE', [$id]);
            if ($c['payment_status'] === 'paid') {
                return 'already_paid';
            }
            if ($c['payment_status'] === 'cancelled') {
                return 'cancelled';
            }
            if (strtotime($c['created_at']) < time() - self::UNPAID_EXPIRY_MINUTES * 60) {
                return 'expired';
            }
            $pending = Db::all("SELECT * FROM orders WHERE checkout_id = ? AND status = 'pending' FOR UPDATE", [$id]);
            if (!$pending) {
                return 'nothing_to_pay';
            }
            // Charge only for the orders that are still going ahead.
            $subtotal = 0;
            foreach ($pending as $o) {
                Db::exec("UPDATE orders SET status = 'paid', paid_at = NOW(), status_changed_at = NOW() WHERE id = ?", [$o['id']]);
                Outbox::enqueue('erp.sales_order.pay', $o['ref']);
                $refs[] = $o['ref'];
                $subtotal += (float) $o['subtotal'];
            }
            Db::exec(
                "UPDATE checkouts SET payment_status = 'paid', paid_at = NOW(), subtotal = ?, total = ? + service_fee
                 WHERE id = ? AND payment_status = 'unpaid'",
                [$subtotal, $subtotal, $id]
            );
            return 'paid';
        });

        switch ($outcome) {
            case 'expired':
                self::cancelCheckout($id, 'Payment was not completed within ' . self::UNPAID_EXPIRY_MINUTES . ' minutes', ['pending']);
                throw new HttpError(409, 'payment_expired', 'The payment window closed after ' . self::UNPAID_EXPIRY_MINUTES . ' minutes, so this order was cancelled and nothing was charged. Please order again.');
            case 'cancelled':
            case 'nothing_to_pay':
                self::cancelCheckout($id, 'Payment was cancelled', ['pending']);
                throw new HttpError(409, 'checkout_cancelled', 'This order was cancelled, so nothing was charged. Please order again.');
            case 'paid':
                Outbox::process($refs);
                if (class_exists('Notifier')) {
                    Notifier::orderReceived($id);
                }
        }
        return ['checkout' => self::formatCheckout(self::checkoutRow($id))];
    }

    // ===================================================================== cancelling
    public static function cancelByStudent(Request $req): array
    {
        $order = self::own($req, $req->params['ref']);
        if (!in_array($order['status'], ['pending', 'paid'], true)) {
            throw new HttpError(409, 'cannot_cancel', $order['status'] === 'cancelled'
                ? 'This order is already cancelled.'
                : 'The stall has already started your order, so it can no longer be cancelled.');
        }
        if (!self::cancelOrder($order, 'Cancelled by you', ['pending', 'paid'])) {
            throw new HttpError(409, 'cannot_cancel', 'The stall has already started your order, so it can no longer be cancelled.');
        }
        return ['order' => self::formatDetail(self::own($req, $order['ref']))];
    }

    /**
     * Cancel one order and give its stock back in the ERP.
     * The order is re-read under a lock: it is only cancelled if its status is
     * still one of $onlyFrom (default: anything not finished). Returns true if cancelled.
     */
    public static function cancelOrder(array $order, string $reason, ?array $onlyFrom = null): bool
    {
        $onlyFrom = $onlyFrom ?? ['pending', 'paid', 'preparing', 'ready'];
        $done = Db::tx(function () use ($order, $reason, $onlyFrom) {
            // Lock the checkout first, then the order (same order as payment confirmation — no deadlocks).
            Db::one('SELECT id FROM checkouts WHERE id = ? FOR UPDATE', [$order['checkout_id']]);
            $fresh = Db::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if (!$fresh || !in_array($fresh['status'], $onlyFrom, true)) {
                return false;
            }
            if ($fresh['paid_at'] !== null) {
                $reason .= ' · ₱' . number_format((float) $fresh['subtotal'], fmod((float) $fresh['subtotal'], 1.0) ? 2 : 0) . ' to be refunded';
            }
            Db::exec(
                "UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), status_changed_at = NOW() WHERE id = ?",
                [$reason, $fresh['id']]
            );
            // Always tell the ERP. If its "create" is still waiting, the outbox sends create first and
            // cancel after it; if the ERP never saved the order, cancelling it is answered with 404 = fine.
            Outbox::enqueue('erp.sales_order.cancel', $fresh['ref']);
            self::refreshCheckout((int) $fresh['checkout_id']);
            return true;
        });
        if ($done) {
            Outbox::process([$order['ref']]);
        }
        return $done;
    }

    public static function cancelCheckout(int $checkoutId, string $reason, ?array $onlyFrom = null): void
    {
        foreach (Db::all('SELECT * FROM orders WHERE checkout_id = ?', [$checkoutId]) as $order) {
            self::cancelOrder($order, $reason, $onlyFrom);
        }
    }

    /**
     * Keep an unpaid checkout in line with its orders: the amount to pay covers only
     * orders still going ahead, and with none left the checkout is cancelled.
     * (A paid checkout keeps the amount that was charged; cancelled paid orders show a refund.)
     */
    private static function refreshCheckout(int $checkoutId): void
    {
        $c = Db::one('SELECT * FROM checkouts WHERE id = ? FOR UPDATE', [$checkoutId]);
        if (!$c || $c['payment_status'] !== 'unpaid') {
            return;
        }
        $live = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(subtotal), 0) AS subtotal FROM orders WHERE checkout_id = ? AND status <> 'cancelled'", [$checkoutId]);
        if ((int) $live['n'] === 0) {
            Db::exec("UPDATE checkouts SET payment_status = 'cancelled' WHERE id = ?", [$checkoutId]);
        } else {
            Db::exec('UPDATE checkouts SET subtotal = ?, total = ? + service_fee WHERE id = ?', [$live['subtotal'], $live['subtotal'], $checkoutId]);
        }
    }

    /** Called by the Outbox when the ERP refuses a sales order (e.g. sold out while it was offline). */
    public static function erpRejected(string $ref, string $message): void
    {
        Db::tx(function () use ($ref, $message) {
            $checkoutId = Db::value('SELECT checkout_id FROM orders WHERE ref = ?', [$ref]);
            Db::one('SELECT id FROM checkouts WHERE id = ? FOR UPDATE', [$checkoutId]);
            $order = Db::one('SELECT * FROM orders WHERE ref = ? FOR UPDATE', [$ref]);
            if (!$order || in_array($order['status'], ['completed', 'cancelled'], true)) {
                return;
            }
            $reason = 'Not enough stock: ' . str_replace('Kantina ERP: ', '', $message);
            if ($order['paid_at'] !== null) {
                $reason .= ' · ₱' . number_format((float) $order['subtotal'], fmod((float) $order['subtotal'], 1.0) ? 2 : 0) . ' to be refunded';
            }
            Db::exec(
                "UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), status_changed_at = NOW() WHERE id = ?",
                [$reason, $order['id']]
            );
            self::refreshCheckout((int) $order['checkout_id']);
        });
    }

    /** GCash checkouts left unpaid for 15 minutes are cancelled so their stock and slots free up. */
    public static function expireUnpaid(): void
    {
        $stale = Db::all(
            "SELECT id FROM checkouts WHERE payment_method = 'gcash' AND payment_status = 'unpaid' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) LIMIT 20",
            [self::UNPAID_EXPIRY_MINUTES]
        );
        foreach ($stale as $c) {
            // Only orders that are still unpaid — never one that was paid a moment ago.
            self::cancelCheckout((int) $c['id'], 'Payment was not completed within ' . self::UNPAID_EXPIRY_MINUTES . ' minutes', ['pending']);
        }
    }

    // ===================================================================== reading
    public static function index(Request $req): array
    {
        self::expireUnpaid();
        $status = $req->query['status'] ?? 'all';
        $where = 'o.user_id = ?';
        $order = 'o.placed_at DESC';
        if ($status === 'active') {
            $where .= " AND o.status IN ('pending', 'paid', 'preparing', 'ready')";
            $order = 'o.pickup_date, o.pickup_time, o.id';
        } elseif ($status === 'history') {
            $where .= " AND o.status IN ('completed', 'cancelled')";
        }
        $rows = Db::all(self::orderSql() . " WHERE $where ORDER BY $order LIMIT 50", [$req->user['id']]);
        return ['orders' => self::formatSummaries($rows)];
    }

    public static function own(Request $req, string $ref): array
    {
        $row = Db::one(self::orderSql() . ' WHERE o.ref = ? AND o.user_id = ?', [$ref, $req->user['id']]);
        if (!$row) {
            throw new HttpError(404, 'order_not_found', 'Order not found.');
        }
        return $row;
    }

    public static function orderSql(): string
    {
        return 'SELECT o.*, s.name AS stall_name, s.counter_label, s.prep_minutes, c.ref AS checkout_ref,
                       c.payment_method, c.payment_status, r.table_code, r.start_time AS res_start, r.end_time AS res_end,
                       u.name AS customer_name, u.email AS customer_email
                FROM orders o
                JOIN checkouts c ON c.id = o.checkout_id
                JOIN users u ON u.id = o.user_id
                LEFT JOIN stalls s ON s.id = o.stall_id
                LEFT JOIN reservations r ON r.id = c.reservation_id';
    }

    private static function ownCheckout(Request $req, string $ref): array
    {
        $c = Db::one('SELECT * FROM checkouts WHERE ref = ? AND user_id = ?', [$ref, $req->user['id']]);
        if (!$c) {
            throw new HttpError(404, 'checkout_not_found', 'Order not found.');
        }
        return $c;
    }

    private static function checkoutRow(int $id): array
    {
        return Db::one('SELECT * FROM checkouts WHERE id = ?', [$id]);
    }

    /** Items for many orders at once: [order_id => rows] */
    public static function itemsFor(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }
        $rows = Db::all('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ') ORDER BY id', $orderIds);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['order_id']][] = $row;
        }
        return $out;
    }

    public static function itemsText(array $items): string
    {
        return implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], $items));
    }

    public static function statusLabel(array $o): string
    {
        switch ($o['status']) {
            case 'pending': return $o['payment_method'] === 'counter' ? 'Pay at counter' : 'Awaiting payment';
            case 'paid': return 'Paid';
            case 'preparing': return 'Preparing';
            case 'ready': return 'Ready';
            case 'completed': return 'Done';
            default: return 'Cancelled';
        }
    }

    // ===================================================================== formatting
    public static function formatSummaries(array $rows): array
    {
        $items = self::itemsFor(array_map(fn($r) => (int) $r['id'], $rows));
        return array_map(fn($r) => self::formatSummary($r, $items[$r['id']] ?? []), $rows);
    }

    public static function formatSummary(array $o, array $items): array
    {
        return [
            'ref' => $o['ref'],
            'id' => (int) $o['id'],
            'checkout_ref' => $o['checkout_ref'],
            'stall' => ['id' => $o['stall_id'], 'name' => $o['stall_name']],
            'status' => $o['status'],
            'status_label' => self::statusLabel($o),
            'progress' => ['step' => self::STEPS[$o['status']] ?? 0, 'total' => 5],
            'pickup_date' => $o['pickup_date'],
            'pickup_day' => Support::dayLabel($o['pickup_date']),
            'pickup_time' => hm($o['pickup_time']),
            'table' => $o['table_code'],
            'total' => money($o['subtotal']),
            'items_count' => array_sum(array_map(fn($i) => (int) $i['qty'], $items)),
            'items_text' => self::itemsText($items),
            'payment_method' => $o['payment_method'],
            'placed_at' => iso($o['placed_at']),
            'refund_due' => $o['status'] === 'cancelled' && $o['paid_at'] !== null,
        ];
    }

    public static function formatItems(array $items): array
    {
        return array_map(fn($i) => [
            'sku' => $i['sku'],
            'name' => $i['name'],
            'qty' => (int) $i['qty'],
            'unit_price' => money($i['unit_price']),
            'addons' => array_map(fn($a) => ['name' => $a['name'], 'price' => money($a['price'])], json_decode((string) $i['addons'], true) ?: []),
            'note' => $i['note'],
            'line_total' => money($i['line_total']),
        ], $items);
    }

    public static function formatDetail(array $o): array
    {
        $items = self::itemsFor([(int) $o['id']])[$o['id']] ?? [];
        $out = self::formatSummary($o, $items);
        $time = fn(?string $dt) => $dt ? date('g:i A', strtotime($dt)) : null;
        $payLabel = $o['payment_method'] === 'gcash' ? 'GCash' : 'Cash at counter';
        $steps = [
            ['key' => 'placed', 'title' => 'Order placed', 'at' => $time($o['placed_at']), 'extra' => null],
            ['key' => 'paid', 'title' => 'Payment confirmed', 'at' => $time($o['paid_at']), 'extra' => $payLabel],
            ['key' => 'preparing', 'title' => 'Preparing', 'at' => $time($o['preparing_at']), 'extra' => $o['stall_name']],
            ['key' => 'ready', 'title' => 'Ready for pickup', 'at' => $time($o['ready_at']), 'extra' => $o['counter_label']],
            ['key' => 'completed', 'title' => 'Completed', 'at' => $time($o['completed_at']), 'extra' => null],
        ];
        if ($o['status'] === 'cancelled') {
            $steps = array_values(array_filter($steps, fn($s) => $s['at'] !== null));
            $steps[] = ['key' => 'cancelled', 'title' => 'Cancelled', 'at' => $time($o['cancelled_at']), 'extra' => $o['cancel_reason']];
        }
        $lastDone = -1;
        foreach ($steps as $i => $s) {
            if ($s['at'] !== null) $lastDone = $i;
        }
        $timeline = [];
        foreach ($steps as $i => $s) {
            $timeline[] = [
                'key' => $s['key'],
                'title' => $s['title'],
                'at' => $s['at'],
                'sub' => $s['at'] ? trim($s['at'] . ($s['extra'] ? ' · ' . $s['extra'] : '')) : null,
                'done' => $s['at'] !== null,
                'current' => $i === $lastDone,
            ];
        }
        return $out + [
            'headline' => self::HEADLINES[$o['status']] ?? '',
            'eta_text' => self::etaText($o),
            'counter' => $o['counter_label'],
            'payment_status' => $o['payment_status'],
            'cancel_reason' => $o['cancel_reason'],
            'can_cancel' => in_array($o['status'], ['pending', 'paid'], true),
            'items' => self::formatItems($items),
            'timeline' => $timeline,
        ];
    }

    private static function etaText(array $o): string
    {
        $pickup = Support::dayLabel($o['pickup_date']) . ' ' . Support::clockAmPm(hm($o['pickup_time']));
        switch ($o['status']) {
            case 'pending':
                return $o['payment_method'] === 'counter'
                    ? "Pay at {$o['counter_label']} to confirm · pickup $pickup"
                    : 'Waiting for your GCash payment';
            case 'paid':
                return "The kitchen will start soon · pickup $pickup";
            case 'preparing':
                $elapsed = (int) floor((time() - strtotime($o['preparing_at'])) / 60);
                $left = max(1, (int) $o['prep_minutes'] - $elapsed);
                return "Ready in about $left minute" . ($left === 1 ? '' : 's');
            case 'ready':
                return "Pick it up at {$o['counter_label']}";
            case 'completed':
                return 'Picked up at ' . date('g:i A', strtotime($o['completed_at']));
            default:
                return $o['cancel_reason'] ?: 'This order was cancelled.';
        }
    }

    public static function formatCheckout(array $c): array
    {
        $orders = Db::all(self::orderSql() . ' WHERE o.checkout_id = ? ORDER BY o.id', [$c['id']]);
        $items = self::itemsFor(array_map(fn($o) => (int) $o['id'], $orders));
        $flatItems = [];
        foreach ($orders as $o) {
            foreach (self::formatItems($items[$o['id']] ?? []) as $item) {
                $flatItems[] = $item + ['stall_name' => $o['stall_name']];
            }
        }
        $table = null;
        if ($c['reservation_id']) {
            $r = Db::one('SELECT * FROM reservations WHERE id = ?', [$c['reservation_id']]);
            if ($r) {
                $table = ['code' => $r['table_code'], 'ref' => $r['ref'], 'window' => hm($r['start_time']) . ' – ' . hm($r['end_time'])];
            }
        }
        return [
            'ref' => $c['ref'],
            'payment_method' => $c['payment_method'],
            'payment_status' => $c['payment_status'],
            'subtotal' => money($c['subtotal']),
            'service_fee' => money($c['service_fee']),
            'total' => money($c['total']),
            'pickup_date' => $c['pickup_date'],
            'pickup_time' => hm($c['pickup_time']),
            'pickup_label' => Support::dayLabel($c['pickup_date']) . ' · ' . Support::clock(hm($c['pickup_time'])),
            'table' => $table,
            'created_at' => iso($c['created_at']),
            'paid_at' => iso($c['paid_at']),
            'orders' => array_map(fn($o) => [
                'ref' => $o['ref'],
                'id' => (int) $o['id'],
                'stall' => ['id' => $o['stall_id'], 'name' => $o['stall_name']],
                'counter' => $o['counter_label'],
                'status' => $o['status'],
                'status_label' => self::statusLabel($o),
                'subtotal' => money($o['subtotal']),
                'cancel_reason' => $o['cancel_reason'],
                'refund_due' => $o['status'] === 'cancelled' && $o['paid_at'] !== null,
            ], $orders),
            'items' => $flatItems,
        ];
    }
}

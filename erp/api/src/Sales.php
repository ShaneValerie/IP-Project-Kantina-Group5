<?php
/**
 * Sales orders (the sales ledger).
 *
 * Kantina sends one sales order per stall order:
 *   create  -> status "open", stock is deducted right away (so nothing is oversold)
 *   pay     -> status "paid" (counts as revenue in the reports)
 *   cancel  -> status "cancelled", stock is put back
 * Calls are idempotent: sending the same external_ref twice returns the
 * existing sales order, so Kantina can safely retry after a timeout.
 */
final class Sales
{
    public static function format(array $so, bool $withLines = true): array
    {
        $out = [
            'so_no' => $so['so_no'],
            'channel' => $so['channel'],
            'external_ref' => $so['external_ref'],
            'stall_code' => $so['stall_code'],
            'status' => $so['status'],
            'total' => money($so['total']),
            'created_at' => iso($so['created_at']),
            'paid_at' => iso($so['paid_at']),
            'cancelled_at' => iso($so['cancelled_at']),
        ];
        if ($withLines) {
            $out['lines'] = array_map(fn($l) => [
                'sku' => $l['sku'],
                'name' => $l['name'],
                'qty' => (int) $l['qty'],
                'unit_price' => money($l['unit_price']),
                'line_total' => money($l['line_total']),
            ], Db::all('SELECT * FROM erp_sales_order_lines WHERE sales_order_id = ? ORDER BY id', [$so['id']]));
        }
        return $out;
    }

    /** Find by SO number (SO-000123) or Kantina reference (KTN-90214). */
    public static function find(string $ref, bool $lock = false): ?array
    {
        return Db::one('SELECT * FROM erp_sales_orders WHERE so_no = ? OR external_ref = ?' . ($lock ? ' FOR UPDATE' : ''), [$ref, $ref]);
    }

    public static function get(string $ref, bool $lock = false): array
    {
        $so = self::find($ref, $lock);
        if (!$so) {
            throw new HttpError(404, 'sales_order_not_found', "No sales order $ref.");
        }
        return $so;
    }

    /**
     * @return array [salesOrderRow, created(bool), changedProducts(array)]
     */
    public static function create(array $in, string $actor): array
    {
        $data = Validator::check($in, [
            'external_ref' => 'string|max:40',
            'stall_code' => 'required|string',
            'channel' => 'in:kantina,walk_in',
            'status' => 'in:open,paid',
            'lines' => 'required|array',
        ]);
        if (!empty($data['external_ref']) && ($existing = self::find($data['external_ref']))) {
            return [$existing, false, []];
        }
        if (!Db::value('SELECT 1 FROM erp_stalls WHERE code = ?', [$data['stall_code']])) {
            throw new HttpError(422, 'validation_error', 'Unknown stall.', ['stall_code' => 'Unknown stall.']);
        }
        if (!$data['lines']) {
            throw new HttpError(422, 'validation_error', 'A sales order needs at least one line.', ['lines' => 'Empty.']);
        }
        $status = $data['status'] ?? 'open';
        $changed = [];
        try {
            $id = Db::tx(function () use ($data, $status, $actor, &$changed) {
                $id = Db::insert('erp_sales_orders', [
                    'channel' => $data['channel'] ?? 'kantina',
                    'external_ref' => $data['external_ref'] ?? null,
                    'stall_code' => $data['stall_code'],
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : null,
                ]);
                $soNo = sprintf('SO-%06d', $id);
                Db::exec('UPDATE erp_sales_orders SET so_no = ? WHERE id = ?', [$soNo, $id]);
                $total = 0;
                foreach ($data['lines'] as $line) {
                    $line = Validator::check(is_array($line) ? $line : [], [
                        'sku' => 'required|string',
                        'qty' => 'required|int|min:1',
                        'unit_price' => 'number|min:0',
                    ]);
                    $p = Inventory::get($line['sku'], true);
                    if ($p['stall_code'] !== $data['stall_code']) {
                        throw new HttpError(422, 'wrong_stall', "{$p['name']} is not sold by this stall.", ['sku' => $line['sku']]);
                    }
                    if (!(int) $p['is_active']) {
                        throw new HttpError(409, 'not_available', "{$p['name']} is not available right now.", ['sku' => $line['sku']]);
                    }
                    $p = Inventory::changeStock($line['sku'], -$line['qty'], 'sale', $soNo, null, $actor);
                    $changed[$p['sku']] = $p;
                    $price = $line['unit_price'] ?? (float) $p['price'];
                    $lineTotal = round($price * $line['qty'], 2);
                    $total += $lineTotal;
                    Db::insert('erp_sales_order_lines', [
                        'sales_order_id' => $id, 'sku' => $p['sku'], 'name' => $p['name'],
                        'qty' => $line['qty'], 'unit_price' => $price, 'line_total' => $lineTotal,
                    ]);
                }
                Db::exec('UPDATE erp_sales_orders SET total = ? WHERE id = ?', [$total, $id]);
                return $id;
            });
        } catch (Throwable $e) {
            Events::discard();
            // Two retries arrived at the same time: the unique external_ref wins, return that one.
            if ($e instanceof PDOException && $e->getCode() === '23000' && !empty($data['external_ref']) && ($existing = self::find($data['external_ref']))) {
                return [$existing, false, []];
            }
            throw $e;
        }
        Events::flush();
        return [Db::one('SELECT * FROM erp_sales_orders WHERE id = ?', [$id]), true, array_values($changed)];
    }

    public static function pay(string $ref): array
    {
        $so = self::get($ref);
        if ($so['status'] === 'cancelled') {
            throw new HttpError(409, 'cancelled', "Sales order {$so['so_no']} was cancelled.");
        }
        if ($so['status'] !== 'paid') {
            Db::exec("UPDATE erp_sales_orders SET status = 'paid', paid_at = NOW() WHERE id = ?", [$so['id']]);
        }
        return self::get($ref);
    }

    /** @return array [salesOrderRow, changedProducts] */
    public static function cancel(string $ref, string $actor): array
    {
        $changed = [];
        Db::tx(function () use ($ref, $actor, &$changed) {
            $so = self::get($ref, true);
            if ($so['status'] === 'cancelled') {
                return;
            }
            foreach (Db::all('SELECT sku, qty FROM erp_sales_order_lines WHERE sales_order_id = ?', [$so['id']]) as $line) {
                $p = Inventory::changeStock($line['sku'], (int) $line['qty'], 'sale_cancel', $so['so_no'], 'Order cancelled', $actor, false);
                $changed[$p['sku']] = $p;
            }
            Db::exec("UPDATE erp_sales_orders SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?", [$so['id']]);
        });
        foreach ($changed as $p) {
            Events::queue('product.updated', ['product' => Inventory::format($p)]);
        }
        Events::flush();
        return [self::get($ref), array_values($changed)];
    }

    public static function list(array $f, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::filters($f);
        return Db::all(
            'SELECT * FROM erp_sales_orders' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
    }

    public static function count(array $f): int
    {
        [$where, $params] = self::filters($f);
        return (int) Db::value('SELECT COUNT(*) FROM erp_sales_orders' . $where, $params);
    }

    private static function filters(array $f): array
    {
        $where = [];
        $params = [];
        foreach (['stall' => 'stall_code', 'status' => 'status', 'channel' => 'channel'] as $key => $col) {
            if (!empty($f[$key])) {
                $where[] = "$col = ?";
                $params[] = $f[$key];
            }
        }
        if (!empty($f['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['q'])) {
            $where[] = '(so_no LIKE ? OR external_ref LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }
}

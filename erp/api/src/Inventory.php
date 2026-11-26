<?php
/**
 * Item master and stock. Used by the REST API and the admin pages.
 */
final class Inventory
{
    /** Product row -> API JSON shape. */
    public static function format(array $p): array
    {
        $track = (bool) (int) $p['track_stock'];
        $stock = (int) $p['stock'];
        return [
            'sku' => $p['sku'],
            'stall_code' => $p['stall_code'],
            'name' => $p['name'],
            'type' => $p['type'],
            'price' => money($p['price']),
            'stock' => $stock,
            'track_stock' => $track,
            'reorder_level' => (int) $p['reorder_level'],
            'is_active' => (bool) (int) $p['is_active'],
            'low_stock' => $track && $stock <= (int) $p['reorder_level'],
            'sold_out' => $track && $stock <= 0,
            'updated_at' => iso($p['updated_at']),
        ];
    }

    public static function find(string $sku, bool $lock = false): ?array
    {
        return Db::one('SELECT * FROM erp_products WHERE sku = ?' . ($lock ? ' FOR UPDATE' : ''), [$sku]);
    }

    public static function get(string $sku, bool $lock = false): array
    {
        $p = self::find($sku, $lock);
        if (!$p) {
            throw new HttpError(404, 'product_not_found', "No product with SKU $sku.");
        }
        return $p;
    }

    public static function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['stall'])) {
            $where[] = 'stall_code = ?';
            $params[] = $filters['stall'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['low_stock'])) {
            $where[] = 'track_stock = 1 AND stock <= reorder_level';
        }
        $sql = 'SELECT * FROM erp_products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY stall_code, type, sku';
        return Db::all($sql, $params);
    }

    /** SKU from the stall and item name: lolas-silog + "Garlic Rice" -> LS-GARLIC-RICE */
    public static function makeSku(string $stallCode, string $name): string
    {
        $prefix = implode('', array_map(fn($part) => strtoupper($part[0] ?? ''), explode('-', $stallCode)));
        $slug = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $base = substr($prefix . '-' . $slug, 0, 36);
        $sku = $base;
        $n = 2;
        while (self::find($sku)) {
            $sku = $base . '-' . $n++;
        }
        return $sku;
    }

    public static function create(array $in, string $actor): array
    {
        $data = Validator::check($in, [
            'sku' => 'string|max:40|regex:/^[A-Z0-9-]+$/',
            'stall_code' => 'required|string',
            'name' => 'required|string|max:80',
            'type' => 'in:menu,addon',
            'price' => 'required|number|min:0',
            'stock' => 'int|min:0',
            'track_stock' => 'bool',
            'reorder_level' => 'int|min:0',
            'is_active' => 'bool',
        ]);
        if (!Db::value('SELECT 1 FROM erp_stalls WHERE code = ?', [$data['stall_code']])) {
            throw new HttpError(422, 'validation_error', 'Unknown stall.', ['stall_code' => 'Unknown stall.']);
        }
        $sku = $data['sku'] ?? self::makeSku($data['stall_code'], $data['name']);
        if (self::find($sku)) {
            throw new HttpError(409, 'sku_exists', "SKU $sku already exists.", ['sku' => 'Already exists.']);
        }
        $track = $data['track_stock'] ?? true;
        $stock = $track ? ($data['stock'] ?? 0) : 0;
        Db::tx(function () use ($sku, $data, $track, $stock, $actor) {
            Db::insert('erp_products', [
                'sku' => $sku,
                'stall_code' => $data['stall_code'],
                'name' => $data['name'],
                'type' => $data['type'] ?? 'menu',
                'price' => $data['price'],
                'stock' => $stock,
                'track_stock' => $track ? 1 : 0,
                'reorder_level' => $data['reorder_level'] ?? 5,
                'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            ]);
            if ($track) {
                Db::insert('erp_stock_movements', [
                    'sku' => $sku, 'qty_change' => $stock, 'balance_after' => $stock, 'reason' => 'opening',
                    'reference' => 'NEW', 'note' => 'New product', 'created_by' => $actor,
                ]);
            }
        });
        $product = self::get($sku);
        Events::queue('product.updated', ['product' => self::format($product)]);
        Events::flush();
        return $product;
    }

    /** Update name / price / availability / reorder level, or set the stock to an exact count. */
    public static function update(string $sku, array $in, string $actor): array
    {
        $data = Validator::check($in, [
            'name' => 'string|max:80',
            'price' => 'number|min:0',
            'stock' => 'int|min:0',
            'reorder_level' => 'int|min:0',
            'is_active' => 'bool',
            'track_stock' => 'bool',
        ]);
        Db::tx(function () use ($sku, $data, $actor) {
            $p = self::get($sku, true);
            $before = (int) $p['stock'];
            $sets = [];
            $params = [];
            foreach (['name', 'price', 'reorder_level'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sets[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            foreach (['is_active', 'track_stock'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sets[] = "$field = ?";
                    $params[] = $data[$field] ? 1 : 0;
                }
            }
            if ($sets) {
                $params[] = $sku;
                Db::exec('UPDATE erp_products SET ' . implode(', ', $sets) . ' WHERE sku = ?', $params);
            }
            if (array_key_exists('stock', $data) && (int) $data['stock'] !== $before) {
                self::changeStock($sku, (int) $data['stock'] - $before, 'adjustment', 'COUNT', 'Stock count set to ' . $data['stock'], $actor, false);
            }
            $after = self::get($sku);
            Events::stockThresholds($after, $before);
        });
        $product = self::get($sku);
        Events::queue('product.updated', ['product' => self::format($product)]);
        Events::flush();
        return $product;
    }

    /**
     * Add or remove stock and record the movement. Must run inside Db::tx().
     * Returns the updated product row.
     */
    public static function changeStock(string $sku, int $delta, string $reason, ?string $reference, ?string $note, string $actor, bool $checkThresholds = true): array
    {
        $p = self::get($sku, true);
        if (!(int) $p['track_stock']) {
            return $p;
        }
        $before = (int) $p['stock'];
        $after = $before + $delta;
        if ($after < 0) {
            throw new HttpError(409, 'insufficient_stock', $before <= 0
                ? "{$p['name']} is sold out."
                : "Only $before left of {$p['name']}.", ['sku' => $sku]);
        }
        Db::exec('UPDATE erp_products SET stock = ? WHERE sku = ?', [$after, $sku]);
        Db::insert('erp_stock_movements', [
            'sku' => $sku, 'qty_change' => $delta, 'balance_after' => $after, 'reason' => $reason,
            'reference' => $reference, 'note' => $note, 'created_by' => $actor,
        ]);
        $p['stock'] = $after;
        if ($checkThresholds) {
            Events::stockThresholds($p, $before);
        }
        return $p;
    }

    /** Restock / manual adjustment from the API or admin page. */
    public static function adjust(array $in, string $actor): array
    {
        $data = Validator::check($in, [
            'sku' => 'required|string',
            'qty_change' => 'required|int',
            'reason' => 'in:restock,adjustment',
            'note' => 'string|max:255',
        ]);
        if ((int) $data['qty_change'] === 0) {
            throw new HttpError(422, 'validation_error', 'Quantity change cannot be 0.', ['qty_change' => 'Cannot be 0.']);
        }
        $reason = $data['reason'] ?? ($data['qty_change'] > 0 ? 'restock' : 'adjustment');
        $product = Db::tx(function () use ($data, $reason, $actor) {
            $p = self::get($data['sku'], true);
            if (!(int) $p['track_stock']) {
                throw new HttpError(422, 'not_tracked', "{$p['name']} is made to order — stock is not tracked.");
            }
            return self::changeStock($data['sku'], (int) $data['qty_change'], $reason, strtoupper($reason), $data['note'] ?? null, $actor);
        });
        Events::queue('product.updated', ['product' => self::format(self::get($data['sku']))]);
        Events::flush();
        return self::get($data['sku']);
    }

    public static function movements(string $sku, int $limit = 20): array
    {
        return Db::all('SELECT * FROM erp_stock_movements WHERE sku = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$sku]);
    }
}

<?php
/**
 * Catalog: stalls, menus and announcements.
 *
 * Kantina owns the menu content (stalls, names, descriptions, photos,
 * sections, add-ons, announcements) and staff edit it in the store console.
 * Price, stock and availability come from the Kantina ERP item master,
 * joined by SKU. The ERP copy is refreshed by ERP webhooks (instant) and by
 * a full sync (POST /integrations/erp/sync), so pages never wait on the ERP
 * and keep working if it is briefly offline.
 */
final class Catalog
{
    public static function routes(Router $r): void
    {
        $r->get('/stalls', [self::class, 'listStalls']);
        $r->get('/stalls/{id}', fn(Request $q) => ['stall' => self::formatStall(self::stall($q->params['id']))]);
        $r->get('/stalls/{id}/menu', [self::class, 'stallMenu']);
        $r->get('/menu-items/{sku}', [self::class, 'showItem']);
        $r->get('/announcements', [self::class, 'announcements']);
    }

    // ================================================================ reading
    public static function listStalls(Request $req): array
    {
        $rows = Db::all('SELECT * FROM stalls WHERE is_published = 1 ORDER BY sort_order, name');
        $categories = array_values(array_unique(array_column($rows, 'category')));
        $category = trim((string) ($req->query['category'] ?? ''));
        if ($category !== '' && strcasecmp($category, 'All') !== 0) {
            $rows = array_values(array_filter($rows, fn($s) => strcasecmp($s['category'], $category) === 0));
        }
        $stalls = array_map([self::class, 'formatStall'], $rows);
        return [
            'stalls' => $stalls,
            'open_count' => count(array_filter($stalls, fn($s) => $s['is_open'])),
            'categories' => $categories,
        ];
    }

    public static function stallMenu(Request $req): array
    {
        $stall = self::stall($req->params['id']);
        $formatted = self::formatStall($stall);
        $sections = [];
        $rows = Db::all('SELECT * FROM menu_items WHERE stall_id = ? AND is_published = 1 ORDER BY type = \'addon\', sort_order, name', [$stall['id']]);
        foreach ($rows as $row) {
            $name = $row['type'] === 'addon' ? 'Add-ons' : $row['section'];
            $sections[$name][] = self::formatItem($row, $formatted);
        }
        return [
            'stall' => $formatted,
            'sections' => array_map(fn($name, $items) => ['name' => $name, 'items' => $items], array_keys($sections), array_values($sections)),
        ];
    }

    public static function showItem(Request $req): array
    {
        $row = self::item($req->params['sku']);
        $stall = self::formatStall(self::stall($row['stall_id']));
        $item = self::formatItem($row, $stall);
        $item['stall'] = ['id' => $stall['id'], 'name' => $stall['name'], 'accepts_orders' => $stall['accepts_orders'], 'is_open' => $stall['is_open']];
        return ['item' => $item, 'addons' => self::addonsFor($row, $stall)];
    }

    public static function announcements(): array
    {
        $rows = Db::all('SELECT * FROM announcements WHERE (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order, id');
        return ['announcements' => array_map(fn($a) => [
            'id' => (int) $a['id'],
            'title' => $a['title'],
            'body' => $a['body'],
            'image_url' => self::mediaUrl($a['image_path']),
            'link_url' => $a['link_url'],
        ], $rows)];
    }

    // ================================================================ lookups
    public static function stall(string $id): array
    {
        $s = Db::one('SELECT * FROM stalls WHERE id = ? AND is_published = 1', [$id]);
        if (!$s) {
            throw new HttpError(404, 'stall_not_found', 'That stall does not exist.');
        }
        return $s;
    }

    public static function item(string $sku): array
    {
        $row = Db::one('SELECT * FROM menu_items WHERE sku = ? AND is_published = 1', [$sku]);
        if (!$row) {
            throw new HttpError(404, 'item_not_found', 'That menu item does not exist.');
        }
        return $row;
    }

    /** Add-ons offered with a menu item (published, sold by the same stall). */
    public static function addonsFor(array $item, array $stall): array
    {
        $skus = array_filter(array_map('trim', explode(',', (string) $item['addon_skus'])));
        if (!$skus) {
            return [];
        }
        $in = implode(',', array_fill(0, count($skus), '?'));
        $rows = Db::all("SELECT * FROM menu_items WHERE sku IN ($in) AND stall_id = ? AND is_published = 1 ORDER BY sort_order", array_merge($skus, [$item['stall_id']]));
        return array_map(fn($a) => [
            'sku' => $a['sku'],
            'name' => $a['name'],
            'price' => money($a['price']),
            'available' => self::isAvailable($a, $stall),
        ], $rows);
    }

    // ================================================================ formatting
    public static function mediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        return preg_match('#^https?://#', $path) ? $path : Config::url('kantina') . '/assets/img/' . ltrim($path, '/');
    }

    /** Open now? Accepting (pre-)orders? What should the badge say? */
    public static function formatStall(array $s): array
    {
        $now = date('H:i:s');
        $closed = (bool) (int) $s['temporarily_closed'];
        $inHours = $now >= $s['opens_at'] && $now < $s['closes_at'];
        $isOpen = !$closed && $inHours;
        if ($closed) {
            $status = $s['closed_note'] ?: 'Closed today';
        } elseif ($isOpen) {
            $status = 'Open';
        } elseif ($now < $s['opens_at']) {
            $status = 'Closed until ' . Support::clockAmPm($s['opens_at']);
        } else {
            $status = 'Opens tomorrow ' . Support::clockAmPm($s['opens_at']);
        }
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'category' => $s['category'],
            'description' => $s['description'],
            'photo_url' => self::mediaUrl($s['photo_path']),
            'counter' => $s['counter_label'],
            'prep_minutes' => (int) $s['prep_minutes'],
            'opens_at' => hm($s['opens_at']),
            'closes_at' => hm($s['closes_at']),
            'is_open' => $isOpen,
            'accepts_orders' => !$closed,     // pre-orders are allowed outside opening hours
            'temporarily_closed' => $closed,
            'status_text' => $status,
        ];
    }

    public static function isSoldOut(array $row): bool
    {
        return (int) $row['track_stock'] === 1 && (int) $row['stock'] <= 0;
    }

    public static function isAvailable(array $row, array $formattedStall): bool
    {
        return (int) $row['is_active'] === 1 && $row['erp_synced_at'] !== null && !self::isSoldOut($row) && $formattedStall['accepts_orders'];
    }

    public static function formatItem(array $row, array $formattedStall): array
    {
        $track = (int) $row['track_stock'] === 1;
        return [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'description' => $row['description'],
            'type' => $row['type'],
            'section' => $row['type'] === 'addon' ? 'Add-ons' : $row['section'],
            'price' => money($row['price']),
            'photo_url' => self::mediaUrl($row['photo_path']),
            'available' => self::isAvailable($row, $formattedStall),
            'sold_out' => self::isSoldOut($row),
            'stock' => $track ? (int) $row['stock'] : null,
            'low_stock' => $track && (int) $row['stock'] > 0 && (int) $row['stock'] <= 5,
        ];
    }

    // ================================================================ syncing
    /** Pull every product from the ERP (price, stock, availability). Returns counts for the status page. */
    public static function fullSync(): array
    {
        $erp = ErpClient::must('GET', '/products', null, 10);
        Db::tx(function () use ($erp) {
            foreach ($erp['products'] as $p) {
                self::applyErpProduct($p);
            }
        });
        Support::kvSet('last_erp_sync', date('c'));
        return [
            'erp_products' => count($erp['products']),
            'synced_at' => date('c'),
        ];
    }

    /**
     * Price/stock/availability fields only — the content (name shown to
     * students, description, photo, section) stays as Kantina has it.
     * A product added in the ERP that Kantina has never seen is listed under
     * its ERP name, so staff can then describe it in the store console.
     */
    public static function applyErpProduct(array $p): void
    {
        Db::exec(
            'INSERT INTO menu_items (sku, stall_id, type, section, name, price, stock, track_stock, is_active, is_published, erp_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE price = VALUES(price), stock = VALUES(stock), track_stock = VALUES(track_stock),
               is_active = VALUES(is_active), erp_synced_at = NOW()',
            [
                $p['sku'], $p['stall_code'], $p['type'], $p['type'] === 'addon' ? 'Add-ons' : 'Menu', $p['name'], $p['price'], (int) $p['stock'],
                !empty($p['track_stock']) ? 1 : 0, !empty($p['is_active']) ? 1 : 0,
            ]
        );
        Support::kvSet('last_erp_sync', date('c'));
    }

    /** Stock levels returned by the ERP after a sale or cancellation. */
    public static function applyStock(array $stock): void
    {
        foreach ($stock as $s) {
            Db::exec('UPDATE menu_items SET stock = ?, erp_synced_at = NOW() WHERE sku = ?', [(int) $s['stock'], $s['sku']]);
        }
    }

    /** Save content edits made in the store console (name, description, section). */
    public static function saveContent(string $sku, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(['name', 'description', 'section']));
        if (!$fields) {
            return;
        }
        $sets = implode(', ', array_map(fn($col) => "$col = ?", array_keys($fields)));
        Db::exec("UPDATE menu_items SET $sets, content_updated_at = NOW() WHERE sku = ?", array_merge(array_values($fields), [$sku]));
    }
}

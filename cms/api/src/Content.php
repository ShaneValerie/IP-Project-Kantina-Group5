<?php
/**
 * Content types managed by the CMS: stalls, menu entries, announcements.
 * Used by both the delivery API and the admin pages.
 */
final class Content
{
    public static function mediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        return Config::url('cms') . '/media/' . ltrim($path, '/');
    }

    // ------------------------------------------------------------------ stalls
    public static function formatStall(array $s): array
    {
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'category' => $s['category'],
            'description' => $s['description'],
            'photo' => $s['photo'],
            'photo_url' => self::mediaUrl($s['photo']),
            'counter' => $s['counter_label'],
            'prep_minutes' => (int) $s['prep_minutes'],
            'opens_at' => hm($s['opens_at']),
            'closes_at' => hm($s['closes_at']),
            'temporarily_closed' => (bool) (int) $s['temporarily_closed'],
            'closed_note' => $s['closed_note'],
            'sort_order' => (int) $s['sort_order'],
            'is_published' => (bool) (int) $s['is_published'],
            'updated_at' => iso($s['updated_at']),
        ];
    }

    public static function stalls(bool $includeDrafts = false): array
    {
        return Db::all('SELECT * FROM cms_stalls' . ($includeDrafts ? '' : ' WHERE is_published = 1') . ' ORDER BY sort_order, name');
    }

    public static function stall(string $id): array
    {
        $s = Db::one('SELECT * FROM cms_stalls WHERE id = ?', [$id]);
        if (!$s) {
            throw new HttpError(404, 'stall_not_found', "No stall '$id'.");
        }
        return $s;
    }

    public static function saveStall(string $id, array $in): array
    {
        self::stall($id);
        $d = Validator::check($in, [
            'name' => 'required|string|max:80',
            'category' => 'required|string|max:40',
            'description' => 'string|max:2000',
            'photo' => 'string|max:255',
            'counter_label' => 'required|string|max:40',
            'prep_minutes' => 'required|int|min:1|max:120',
            'opens_at' => 'required|time',
            'closes_at' => 'required|time',
            'temporarily_closed' => 'bool',
            'closed_note' => 'string|max:80',
            'sort_order' => 'int',
            'is_published' => 'bool',
        ]);
        if ($d['opens_at'] >= $d['closes_at']) {
            throw new HttpError(422, 'validation_error', 'Closing time must be after opening time.', ['closes_at' => 'Must be after opening time.']);
        }
        Db::exec(
            'UPDATE cms_stalls SET name = ?, category = ?, description = ?, photo = ?, counter_label = ?, prep_minutes = ?,
             opens_at = ?, closes_at = ?, temporarily_closed = ?, closed_note = ?, sort_order = ?, is_published = ? WHERE id = ?',
            [
                $d['name'], $d['category'], $d['description'] ?? null, $d['photo'] ?? null, $d['counter_label'], $d['prep_minutes'],
                $d['opens_at'] . ':00', $d['closes_at'] . ':00', !empty($d['temporarily_closed']) ? 1 : 0, $d['closed_note'] ?? null,
                $d['sort_order'] ?? 0, !empty($d['is_published']) ? 1 : 0, $id,
            ]
        );
        return self::stall($id);
    }

    // ------------------------------------------------------------ menu entries
    public static function formatEntry(array $e): array
    {
        return [
            'sku' => $e['sku'],
            'stall_id' => $e['stall_id'],
            'type' => $e['type'],
            'section' => $e['section'],
            'name' => $e['name'],
            'description' => $e['description'],
            'photo' => $e['photo'],
            'photo_url' => self::mediaUrl($e['photo']),
            'addon_skus' => $e['addon_skus'] ? array_values(array_filter(array_map('trim', explode(',', $e['addon_skus'])))) : [],
            'sort_order' => (int) $e['sort_order'],
            'is_published' => (bool) (int) $e['is_published'],
            'updated_at' => iso($e['updated_at']),
        ];
    }

    public static function entries(?string $stall = null, bool $includeDrafts = false): array
    {
        $where = [];
        $params = [];
        if ($stall) {
            $where[] = 'stall_id = ?';
            $params[] = $stall;
        }
        if (!$includeDrafts) {
            $where[] = 'is_published = 1';
        }
        return Db::all('SELECT * FROM cms_menu_entries' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY stall_id, sort_order, name', $params);
    }

    public static function entry(string $sku): array
    {
        $e = Db::one('SELECT * FROM cms_menu_entries WHERE sku = ?', [$sku]);
        if (!$e) {
            throw new HttpError(404, 'entry_not_found', "No menu content for SKU $sku.");
        }
        return $e;
    }

    /** Create ($isNew) or update a menu entry. */
    public static function saveEntry(?string $sku, array $in, bool $isNew): array
    {
        $rules = [
            'stall_id' => 'string',
            'type' => 'in:menu,addon',
            'section' => 'string|max:60',
            'name' => 'string|max:80',
            'description' => 'string|max:2000',
            'photo' => 'string|max:255',
            'addon_skus' => 'string|max:255',
            'sort_order' => 'int',
            'is_published' => 'bool',
        ];
        if ($isNew) {
            $rules['sku'] = 'required|string|max:40|regex:/^[A-Z0-9-]+$/';
            $rules['stall_id'] = 'required|string';
            $rules['name'] = 'required|string|max:80';
        }
        if (isset($in['addon_skus']) && is_array($in['addon_skus'])) {
            $in['addon_skus'] = implode(',', $in['addon_skus']);
        }
        $d = Validator::check($in, $rules);
        if (isset($d['stall_id'])) {
            self::stall($d['stall_id']);
        }
        // Keep "description" and "photo" clearable from the admin form.
        foreach (['description', 'photo', 'addon_skus'] as $nullable) {
            if (array_key_exists($nullable, $in) && ($in[$nullable] === '' || $in[$nullable] === null)) {
                $d[$nullable] = null;
            }
        }
        if ($isNew) {
            if (Db::value('SELECT 1 FROM cms_menu_entries WHERE sku = ?', [$d['sku']])) {
                throw new HttpError(409, 'sku_exists', "Menu content for {$d['sku']} already exists.", ['sku' => 'Already exists.']);
            }
            Db::insert('cms_menu_entries', [
                'sku' => $d['sku'],
                'stall_id' => $d['stall_id'],
                'type' => $d['type'] ?? 'menu',
                'section' => $d['section'] ?? 'Menu',
                'name' => $d['name'],
                'description' => $d['description'] ?? null,
                'photo' => $d['photo'] ?? null,
                'addon_skus' => $d['addon_skus'] ?? null,
                'sort_order' => $d['sort_order'] ?? (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cms_menu_entries WHERE stall_id = ?', [$d['stall_id']]),
                'is_published' => array_key_exists('is_published', $d) ? ($d['is_published'] ? 1 : 0) : 1,
            ]);
            return self::entry($d['sku']);
        }
        self::entry($sku);
        unset($d['sku']);
        $sets = [];
        $params = [];
        foreach ($d as $col => $value) {
            $sets[] = "$col = ?";
            $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }
        if ($sets) {
            $params[] = $sku;
            Db::exec('UPDATE cms_menu_entries SET ' . implode(', ', $sets) . ' WHERE sku = ?', $params);
        }
        return self::entry($sku);
    }

    // ------------------------------------------------------------ announcements
    public static function formatAnnouncement(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => $a['title'],
            'body' => $a['body'],
            'image' => $a['image'],
            'image_url' => self::mediaUrl($a['image']),
            'link_url' => $a['link_url'],
            'starts_at' => iso($a['starts_at']),
            'ends_at' => iso($a['ends_at']),
            'sort_order' => (int) $a['sort_order'],
            'is_published' => (bool) (int) $a['is_published'],
            'updated_at' => iso($a['updated_at']),
        ];
    }

    /** Published announcements that are currently running (or all, for the admin). */
    public static function announcements(bool $all = false): array
    {
        $sql = 'SELECT * FROM cms_announcements';
        if (!$all) {
            $sql .= ' WHERE is_published = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())';
        }
        return Db::all($sql . ' ORDER BY sort_order, id DESC');
    }

    public static function saveAnnouncement(?int $id, array $in): array
    {
        $d = Validator::check($in, [
            'title' => 'required|string|max:120',
            'body' => 'string|max:2000',
            'image' => 'string|max:255',
            'link_url' => 'string|max:255',
            'starts_at' => 'string',
            'ends_at' => 'string',
            'sort_order' => 'int',
            'is_published' => 'bool',
        ]);
        $row = [
            'title' => $d['title'],
            'body' => $d['body'] ?? null,
            'image' => $d['image'] ?? null,
            'link_url' => $d['link_url'] ?? null,
            'starts_at' => !empty($d['starts_at']) ? date('Y-m-d H:i:s', strtotime($d['starts_at'])) : null,
            'ends_at' => !empty($d['ends_at']) ? date('Y-m-d H:i:s', strtotime($d['ends_at'])) : null,
            'sort_order' => $d['sort_order'] ?? 0,
            'is_published' => !empty($d['is_published']) ? 1 : 0,
        ];
        if ($id) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
            Db::exec("UPDATE cms_announcements SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
        } else {
            $id = Db::insert('cms_announcements', $row);
        }
        return Db::one('SELECT * FROM cms_announcements WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------ media
    /** Image files available in cms/media (relative paths). */
    public static function mediaFiles(): array
    {
        $root = Config::appDir() . '/media';
        $files = [];
        foreach (['stalls', 'food', 'uploads'] as $dir) {
            foreach (glob("$root/$dir/*") ?: [] as $file) {
                if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) {
                    $files[] = "$dir/" . basename($file);
                }
            }
        }
        return $files;
    }

    /** Save an uploaded image into media/uploads and return its media path. */
    public static function upload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError(422, 'upload_failed', 'Choose an image to upload.');
        }
        if ($file['size'] > (int) Config::get('max_upload_bytes', 2097152)) {
            throw new HttpError(422, 'upload_too_large', 'The image is too large (max 2 MB).');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (!$info || !isset($types[$info[2]])) {
            throw new HttpError(422, 'not_an_image', 'Only JPG, PNG, WEBP or GIF images are allowed.');
        }
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME)), '-')) ?: 'image';
        $name = substr($base, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
        $dir = Config::appDir() . '/media/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new HttpError(500, 'upload_failed', 'Could not save the image on the server.');
        }
        return "uploads/$name";
    }
}

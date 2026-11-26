<?php
/**
 * The signed-in student's profile, preferences and favourite stalls.
 */
final class Profile
{
    public static function routes(Router $r): void
    {
        $r->get('/me', [self::class, 'show'], [Auth::student()]);
        $r->patch('/me', [self::class, 'update'], [Auth::student()]);
        $r->post('/me/favourites/{stallId}', [self::class, 'addFavourite'], [Auth::student()]);
        $r->delete('/me/favourites/{stallId}', [self::class, 'removeFavourite'], [Auth::student()]);
    }

    public static function show(Request $req): array
    {
        $uid = $req->user['id'];
        if (class_exists('Reservations')) {
            Reservations::housekeeping();
        }
        return [
            'user' => Auth::format($req->user),
            'stats' => [
                'orders_this_term' => (int) Db::value(
                    "SELECT COUNT(*) FROM orders WHERE user_id = ? AND status <> 'cancelled' AND placed_at >= ?",
                    [$uid, Config::get('term_start', date('Y-01-01'))]
                ),
                'active_orders' => (int) Db::value("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('pending', 'paid', 'preparing', 'ready')", [$uid]),
                'upcoming_reservations' => (int) Db::value(
                    "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status IN ('booked', 'checked_in') AND TIMESTAMP(res_date, end_time) >= NOW()",
                    [$uid]
                ),
            ],
            'favourites' => self::favourites($uid),
        ];
    }

    public static function update(Request $req): array
    {
        $d = Validator::check($req->body(), [
            'name' => 'string|min:2|max:120',
            'email' => 'email|max:120',
            'notify_ready' => 'bool',
        ]);
        $sets = [];
        $params = [];
        if (isset($d['name'])) {
            $sets[] = 'name = ?';
            $params[] = $d['name'];
        }
        if (isset($d['email'])) {
            $email = strtolower($d['email']);
            if (Db::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $req->user['id']])) {
                throw new HttpError(409, 'email_taken', 'Another account already uses this email.', ['email' => 'Already used.']);
            }
            $sets[] = 'email = ?';
            $params[] = $email;
        }
        if (array_key_exists('notify_ready', $d)) {
            $sets[] = 'notify_ready = ?';
            $params[] = $d['notify_ready'] ? 1 : 0;
        }
        if ($sets) {
            $params[] = $req->user['id'];
            Db::exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }
        return ['user' => Auth::format(Db::one('SELECT * FROM users WHERE id = ?', [$req->user['id']]))];
    }

    public static function addFavourite(Request $req): array
    {
        $stall = Catalog::stall($req->params['stallId']);
        Db::exec('INSERT IGNORE INTO favourites (user_id, stall_id) VALUES (?, ?)', [$req->user['id'], $stall['id']]);
        return ['favourites' => self::favourites($req->user['id'])];
    }

    public static function removeFavourite(Request $req): array
    {
        Db::exec('DELETE FROM favourites WHERE user_id = ? AND stall_id = ?', [$req->user['id'], $req->params['stallId']]);
        return ['favourites' => self::favourites($req->user['id'])];
    }

    private static function favourites(int $uid): array
    {
        return array_map(fn($s) => [
            'id' => $s['id'],
            'name' => $s['name'],
            'photo_url' => Catalog::mediaUrl($s['photo_path']),
        ], Db::all(
            'SELECT s.id, s.name, s.photo_path FROM favourites f JOIN stalls s ON s.id = f.stall_id WHERE f.user_id = ? ORDER BY f.created_at',
            [$uid]
        ));
    }
}

<?php
/**
 * Customers and loyalty.
 *
 *   - A customer is a Kantina student. Kantina sends a `customer` object with
 *     every call; the CRM creates the customer the first time and refreshes
 *     the name/email after that (upsert).
 *   - Completed orders earn points: 1 point per ₱10, rounded down per order.
 *   - The tier follows the points: Bronze < 100, Silver 100–299, Gold 300+.
 *   - Every order and point change is stored in crm_interactions (the
 *     customer timeline). UNIQUE (type, ref) makes recordOrder idempotent.
 */
final class Customers
{
    public const PESOS_PER_POINT = 10;
    public const TIERS = ['Gold' => 300, 'Silver' => 100, 'Bronze' => 0];   // highest first

    public static function tierFor(int $points): string
    {
        foreach (self::TIERS as $tier => $min) {
            if ($points >= $min) {
                return $tier;
            }
        }
        return 'Bronze';
    }

    public static function pointsFor(float $amount): int
    {
        return (int) floor($amount / self::PESOS_PER_POINT);
    }

    // ------------------------------------------------------------ reading

    public static function format(array $c): array
    {
        return [
            'kantina_user_id' => (int) $c['kantina_user_id'],
            'student_id' => $c['student_id'],
            'name' => $c['name'],
            'email' => $c['email'],
            'orders_count' => (int) $c['orders_count'],
            'total_spent' => money($c['total_spent']),
            'points' => (int) $c['points'],
            'tier' => $c['tier'],
            'first_order_at' => iso($c['first_order_at']),
            'last_order_at' => iso($c['last_order_at']),
            'created_at' => iso($c['created_at']),
        ];
    }

    public static function formatInteraction(array $i): array
    {
        return [
            'type' => $i['type'],
            'ref' => $i['ref'],
            'amount' => $i['amount'] === null ? null : money($i['amount']),
            'points' => (int) $i['points'],
            'note' => $i['note'],
            'created_at' => iso($i['created_at']),
        ];
    }

    /** Search by name, student ID or email. */
    public static function list(?string $q = null, int $limit = 200): array
    {
        $sql = 'SELECT * FROM crm_customers';
        $args = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' WHERE name LIKE ? OR student_id LIKE ? OR email LIKE ?';
            $like = '%' . trim($q) . '%';
            $args = [$like, $like, $like];
        }
        return Db::all($sql . ' ORDER BY points DESC, name LIMIT ' . max(1, $limit), $args);
    }

    public static function byKantinaId(int $kantinaUserId): array
    {
        $c = Db::one('SELECT * FROM crm_customers WHERE kantina_user_id = ?', [$kantinaUserId]);
        if (!$c) {
            throw new HttpError(404, 'customer_not_found', 'No CRM customer for that Kantina user.');
        }
        return $c;
    }

    public static function byId(int $id, bool $lock = false): array
    {
        $c = Db::one('SELECT * FROM crm_customers WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$c) {
            throw new HttpError(404, 'customer_not_found', 'Customer not found.');
        }
        return $c;
    }

    public static function interactions(int $customerId, int $limit = 100): array
    {
        return Db::all('SELECT * FROM crm_interactions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit), [$customerId]);
    }

    // ------------------------------------------------------------ writing

    /**
     * Create the customer or refresh their name/email. Returns the row (locked
     * when called inside a transaction, so points can be updated safely).
     */
    public static function upsert($in): array
    {
        $c = Validator::check(is_array($in) ? $in : [], [
            'kantina_user_id' => 'required|int|min:1',
            'student_id' => 'string|max:20',
            'name' => 'required|string|min:1|max:120',
            'email' => 'email|max:120',
        ]);
        // INSERT … ON DUPLICATE KEY UPDATE: one statement, safe if two calls arrive at once.
        Db::exec(
            'INSERT INTO crm_customers (kantina_user_id, student_id, name, email) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE student_id = COALESCE(VALUES(student_id), student_id),
                                     name = VALUES(name), email = COALESCE(VALUES(email), email)',
            [$c['kantina_user_id'], $c['student_id'] ?? null, $c['name'], isset($c['email']) ? strtolower($c['email']) : null]
        );
        return Db::one('SELECT * FROM crm_customers WHERE kantina_user_id = ? FOR UPDATE', [$c['kantina_user_id']]);
    }

    /**
     * POST /orders: a Kantina order was completed. Adds it to the timeline and
     * gives points. Idempotent by order_ref: a repeat returns the same result
     * and adds nothing.
     *
     * @return array [customerRow, pointsEarned, duplicate(bool)]
     */
    public static function recordOrder(array $in): array
    {
        $d = Validator::check($in, [
            'customer' => 'required|array',
            'order_ref' => 'required|string|max:40',
            'stall_id' => 'string|max:40',
            'stall_name' => 'string|max:80',
            'amount' => 'required|number|min:0|max:100000',
            'completed_at' => 'string|max:40',
        ]);
        $at = self::parseTime($d['completed_at'] ?? null);

        try {
            return Db::tx(function () use ($d, $in, $at) {
                $customer = self::upsert($in['customer']);

                $existing = Db::one("SELECT * FROM crm_interactions WHERE type = 'order_completed' AND ref = ?", [$d['order_ref']]);
                if ($existing) {
                    return [$customer, (int) $existing['points'], true];
                }

                $points = self::pointsFor((float) $d['amount']);
                Db::insert('crm_interactions', [
                    'customer_id' => $customer['id'],
                    'type' => 'order_completed',
                    'ref' => $d['order_ref'],
                    'amount' => $d['amount'],
                    'points' => $points,
                    'note' => $d['stall_name'] ?? $d['stall_id'] ?? null,
                    'created_at' => $at,
                ]);
                $newPoints = (int) $customer['points'] + $points;
                Db::exec(
                    'UPDATE crm_customers
                        SET orders_count = orders_count + 1, total_spent = total_spent + ?, points = ?, tier = ?,
                            first_order_at = COALESCE(first_order_at, ?),
                            last_order_at = GREATEST(COALESCE(last_order_at, ?), ?)
                      WHERE id = ?',
                    [$d['amount'], $newPoints, self::tierFor($newPoints), $at, $at, $at, $customer['id']]
                );
                return [self::byId((int) $customer['id']), $points, false];
            });
        } catch (PDOException $e) {
            // The same order arrived twice at the same moment: the UNIQUE key let one through.
            if ($e->getCode() === '23000') {
                $row = Db::one("SELECT * FROM crm_interactions WHERE type = 'order_completed' AND ref = ?", [$d['order_ref']]);
                if ($row) {
                    return [self::byId((int) $row['customer_id']), (int) $row['points'], true];
                }
            }
            throw $e;
        }
    }

    /**
     * Admin: add (positive) or remove (negative) points with a reason.
     * Points never go below 0. Sends customer.updated to Kantina.
     *
     * @return array [customerRow, notified(bool)]
     */
    public static function adjustPoints(int $customerId, $delta, string $reason, string $actor): array
    {
        $d = Validator::check(['delta' => $delta, 'reason' => $reason], [
            'delta' => 'required|int|min:-100000|max:100000',
            'reason' => 'required|string|min:3|max:200',
        ]);
        if ($d['delta'] === 0) {
            throw new HttpError(422, 'validation_error', 'Enter a number of points other than 0.', ['delta' => 'Must not be 0.']);
        }
        $customer = Db::tx(function () use ($customerId, $d, $actor) {
            $c = self::byId($customerId, true);
            $applied = max(-(int) $c['points'], $d['delta']);    // can't go below 0
            $newPoints = (int) $c['points'] + $applied;
            Db::insert('crm_interactions', [
                'customer_id' => $c['id'],
                'type' => 'points_adjusted',
                'ref' => 'ADJ-' . bin2hex(random_bytes(6)),
                'points' => $applied,
                'note' => mb_substr($d['reason'] . " (by $actor)", 0, 255),
            ]);
            Db::exec('UPDATE crm_customers SET points = ?, tier = ? WHERE id = ?', [$newPoints, self::tierFor($newPoints), $c['id']]);
            return self::byId((int) $c['id']);
        });
        // Send after the transaction: Kantina must only hear about saved changes.
        $notified = Events::send('customer.updated', ['customer' => [
            'kantina_user_id' => (int) $customer['kantina_user_id'],
            'points' => (int) $customer['points'],
            'tier' => $customer['tier'],
        ]]);
        return [$customer, $notified];
    }

    /** ISO 8601 or "Y-m-d H:i:s" → MySQL DATETIME in the app's time zone (default: now). */
    public static function parseTime(?string $value): string
    {
        if ($value) {
            $t = strtotime($value);
            if ($t !== false) {
                return date('Y-m-d H:i:s', $t);
            }
        }
        return date('Y-m-d H:i:s');
    }
}

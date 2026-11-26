<?php
/**
 * Table reservations in the Lawrence Bunzel Canteen.
 *
 * - A reservation holds one table for 45 minutes from a 30-minute start slot.
 * - A table can't be double-booked (the table row is locked while booking).
 * - Staff can block slots (cleaning, events) — see Seller.
 * - No-shows: a booking not checked in 10 minutes after its start time is
 *   released automatically. This is checked whenever reservations are read,
 *   so no scheduled job is needed.
 */
final class Reservations
{
    public static function routes(Router $r): void
    {
        $r->get('/tables/availability', [self::class, 'availability']);
        $r->post('/reservations', [self::class, 'create'], [Auth::student()]);
        $r->get('/reservations', [self::class, 'index'], [Auth::student()]);
        $r->get('/reservations/{ref}', fn(Request $q) => ['reservation' => self::format(self::own($q))], [Auth::student()]);
        $r->post('/reservations/{ref}/check-in', [self::class, 'checkIn'], [Auth::student()]);
        $r->delete('/reservations/{ref}', [self::class, 'cancel'], [Auth::student()]);
    }

    private static function duration(): int { return (int) Config::get('reservations.duration_minutes', 45); }
    private static function grace(): int { return (int) Config::get('reservations.grace_minutes', 10); }
    public static function slots(): array { return Config::get('reservations.slots'); }

    public static function days(): array
    {
        $days = [];
        for ($i = 0; $i <= (int) Config::get('reservations.days_ahead', 3); $i++) {
            $d = date('Y-m-d', strtotime("+$i day"));
            $days[] = ['date' => $d, 'label' => Support::dayLabel($d)];
        }
        return $days;
    }

    /** Release no-shows and close finished visits. Cheap; runs before every read. */
    public static function housekeeping(): void
    {
        Db::exec(
            "UPDATE reservations SET status = 'released', released_at = NOW()
             WHERE status = 'booked' AND TIMESTAMP(res_date, start_time) < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [self::grace()]
        );
        Db::exec("UPDATE reservations SET status = 'completed' WHERE status = 'checked_in' AND TIMESTAMP(res_date, end_time) < NOW()");
    }

    // ================================================================ availability
    public static function availability(Request $req): array
    {
        self::housekeeping();
        $days = self::days();
        $date = $req->query['date'] ?? $days[0]['date'];
        if (!in_array($date, array_column($days, 'date'), true)) {
            throw new HttpError(422, 'validation_error', 'You can reserve for today and the next ' . (count($days) - 1) . ' days.', ['date' => 'Out of range.']);
        }
        $slotStates = array_map(fn($t) => ['time' => $t, 'past' => strtotime("$date $t") < time()], self::slots());
        $time = $req->query['time'] ?? null;
        if ($time === null) {
            $firstOpen = array_values(array_filter($slotStates, fn($s) => !$s['past']));
            $time = $firstOpen[0]['time'] ?? self::slots()[0];
        }
        if (!in_array($time, self::slots(), true)) {
            throw new HttpError(422, 'validation_error', 'Choose one of the time slots shown.', ['time' => 'Not a slot.']);
        }
        $party = max(1, min(12, (int) preg_replace('/\D/', '', (string) ($req->query['party'] ?? '2')) ?: 2));
        $end = Support::addMinutes($time, self::duration());

        $taken = array_flip(self::takenTables($date, $time, $end));
        $blocked = array_flip(self::blockedTables($date, $time, $end));
        $tables = array_map(function ($t) use ($taken, $blocked, $party) {
            $status = isset($blocked[$t['code']]) ? 'blocked' : (isset($taken[$t['code']]) ? 'taken' : 'free');
            return [
                'code' => $t['code'],
                'seats' => (int) $t['seats'],
                'row' => (int) $t['row_no'],
                'col' => (int) $t['col_no'],
                'status' => $status,
                'fits' => (int) $t['seats'] >= $party,
            ];
        }, Db::all('SELECT * FROM dining_tables ORDER BY row_no, col_no'));

        return [
            'date' => $date,
            'time' => $time,
            'end_time' => $end,
            'party' => $party,
            'days' => $days,
            'slots' => self::slots(),
            'slot_states' => $slotStates,
            'tables' => $tables,
            'free_count' => count(array_filter($tables, fn($t) => $t['status'] === 'free' && $t['fits'])),
        ];
    }

    /** Tables with a booking overlapping [start, end). */
    public static function takenTables(string $date, string $start, string $end): array
    {
        return array_column(Db::all(
            "SELECT DISTINCT table_code FROM reservations
             WHERE res_date = ? AND status IN ('booked', 'checked_in') AND start_time < ? AND end_time > ?",
            [$date, "$end:00", "$start:00"]
        ), 'table_code');
    }

    /** Tables with a staff block (30-minute slots) overlapping [start, end). */
    public static function blockedTables(string $date, string $start, string $end): array
    {
        return array_column(Db::all(
            "SELECT DISTINCT table_code FROM table_blocks
             WHERE block_date = ? AND slot_time < ? AND ADDTIME(slot_time, '00:30:00') > ?",
            [$date, "$end:00", "$start:00"]
        ), 'table_code');
    }

    // ================================================================ booking
    public static function create(Request $req): array
    {
        self::housekeeping();
        $d = Validator::check($req->body(), [
            'table' => 'required|string|max:8',
            'date' => 'required|date',
            'time' => 'required|time',
            'party_size' => 'required|int|min:1|max:12',
        ]);
        if (!in_array($d['date'], array_column(self::days(), 'date'), true)) {
            throw new HttpError(422, 'validation_error', 'You can only reserve for today and the next few days.', ['date' => 'Out of range.']);
        }
        if (!in_array($d['time'], self::slots(), true)) {
            throw new HttpError(422, 'validation_error', 'Choose one of the time slots shown.', ['time' => 'Not a slot.']);
        }
        if (strtotime("{$d['date']} {$d['time']}") < time()) {
            throw new HttpError(409, 'slot_passed', 'That time has already passed — choose a later slot.');
        }
        $end = Support::addMinutes($d['time'], self::duration());
        $user = $req->user;

        $id = Db::tx(function () use ($d, $end, $user) {
            // Lock the table row: two students booking the same table at once are handled one after the other.
            $table = Db::one('SELECT * FROM dining_tables WHERE code = ? FOR UPDATE', [strtoupper($d['table'])]);
            if (!$table) {
                throw new HttpError(404, 'table_not_found', 'That table does not exist.');
            }
            if ((int) $table['seats'] < $d['party_size']) {
                throw new HttpError(409, 'table_too_small', "{$table['code']} only seats {$table['seats']}. Pick a bigger table.");
            }
            if (in_array($table['code'], self::takenTables($d['date'], $d['time'], $end), true)) {
                throw new HttpError(409, 'table_taken', "{$table['code']} was just booked by someone else. Please pick another table.");
            }
            if (in_array($table['code'], self::blockedTables($d['date'], $d['time'], $end), true)) {
                throw new HttpError(409, 'table_blocked', "{$table['code']} is not available at that time.");
            }
            $mine = Db::one(
                "SELECT table_code FROM reservations WHERE user_id = ? AND res_date = ? AND status IN ('booked', 'checked_in')
                 AND start_time < ? AND end_time > ?",
                [$user['id'], $d['date'], "$end:00", "{$d['time']}:00"]
            );
            if ($mine) {
                throw new HttpError(409, 'already_booked', "You already have {$mine['table_code']} reserved at that time.");
            }
            $id = Db::insert('reservations', [
                'user_id' => $user['id'],
                'table_code' => $table['code'],
                'res_date' => $d['date'],
                'start_time' => $d['time'] . ':00',
                'end_time' => $end . ':00',
                'party_size' => $d['party_size'],
            ]);
            Db::exec('UPDATE reservations SET ref = ? WHERE id = ?', ['RSV-' . $id, $id]);
            return $id;
        });
        $row = Db::one('SELECT * FROM reservations WHERE id = ?', [$id]);
        if (class_exists('Notifier')) {
            Notifier::reservationConfirmed($row);
        }
        return [['reservation' => self::format($row)], 201];
    }

    public static function index(Request $req): array
    {
        self::housekeeping();
        $uid = $req->user['id'];
        $upcoming = Db::all(
            "SELECT * FROM reservations WHERE user_id = ? AND status IN ('booked', 'checked_in') AND TIMESTAMP(res_date, end_time) >= NOW()
             ORDER BY res_date, start_time",
            [$uid]
        );
        $upcomingIds = array_column($upcoming, 'id');
        $past = array_values(array_filter(
            Db::all('SELECT * FROM reservations WHERE user_id = ? ORDER BY res_date DESC, start_time DESC LIMIT 30', [$uid]),
            fn($r) => !in_array($r['id'], $upcomingIds, true)
        ));
        return [
            'upcoming' => array_map([self::class, 'format'], $upcoming),
            'past' => array_map([self::class, 'format'], array_slice($past, 0, 20)),
        ];
    }

    public static function checkIn(Request $req): array
    {
        $r = self::own($req);
        if ($r['status'] !== 'booked') {
            throw new HttpError(409, 'cannot_check_in', $r['status'] === 'checked_in' ? 'You are already checked in.' : 'This reservation is no longer active.');
        }
        $start = strtotime("{$r['res_date']} {$r['start_time']}");
        if (time() < $start - 15 * 60) {
            throw new HttpError(409, 'too_early', 'Check-in opens 15 minutes before your time (' . date('g:i A', $start - 15 * 60) . ').');
        }
        Db::exec("UPDATE reservations SET status = 'checked_in', checked_in_at = NOW() WHERE id = ?", [$r['id']]);
        return ['reservation' => self::format(self::own($req))];
    }

    public static function cancel(Request $req): array
    {
        $r = self::own($req);
        if ($r['status'] !== 'booked') {
            throw new HttpError(409, 'cannot_cancel', 'Only upcoming reservations can be cancelled.');
        }
        Db::exec("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?", [$r['id']]);
        return ['reservation' => self::format(self::own($req))];
    }

    private static function own(Request $req): array
    {
        self::housekeeping();
        $r = Db::one('SELECT * FROM reservations WHERE ref = ? AND user_id = ?', [$req->params['ref'], $req->user['id']]);
        if (!$r) {
            throw new HttpError(404, 'reservation_not_found', 'Reservation not found.');
        }
        return $r;
    }

    // ================================================================ formatting
    public static function format(array $r): array
    {
        $start = hm($r['start_time']);
        $end = hm($r['end_time']);
        $startTs = strtotime("{$r['res_date']} $start");
        $now = time();
        $isToday = $r['res_date'] === date('Y-m-d');
        $labels = [
            'booked' => $isToday ? 'Upcoming' : 'Booked',
            'checked_in' => 'Checked in',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'released' => 'Released',
        ];
        $minutes = (int) round((strtotime("{$r['res_date']} $end") - $startTs) / 60);
        $details = [
            'completed' => "Checked in · $minutes min",
            'released' => 'No-show · auto released',
            'cancelled' => 'Cancelled',
        ];
        $seats = (int) Db::value('SELECT seats FROM dining_tables WHERE code = ?', [$r['table_code']]);
        return [
            'ref' => $r['ref'],
            'table' => $r['table_code'],
            'seats' => $seats,
            'date' => $r['res_date'],
            'day_label' => Support::dayLabel($r['res_date']),
            'time' => $start,
            'end_time' => $end,
            'window' => "$start – $end",
            'party_size' => (int) $r['party_size'],
            'status' => $r['status'],
            'status_label' => $labels[$r['status']] ?? $r['status'],
            'detail' => $details[$r['status']] ?? ((int) $r['party_size'] . ((int) $r['party_size'] === 1 ? ' person' : ' people') . ' · Lawrence Bunzel Canteen'),
            'hold_until' => Support::addMinutes($start, self::grace()),
            'can_check_in' => $r['status'] === 'booked' && $now >= $startTs - 15 * 60 && $now <= $startTs + self::grace() * 60,
            'can_cancel' => $r['status'] === 'booked' && $now < $startTs,
        ];
    }
}

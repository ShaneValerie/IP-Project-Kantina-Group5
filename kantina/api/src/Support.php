<?php
/**
 * Small helpers shared by the Kantina API modules.
 */
final class Support
{
    /** "13:00" -> "1:00" (12-hour, no AM/PM — matches the pickup slot buttons). */
    public static function clock(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return sprintf('%d:%02d', $h12, $m);
    }

    /** "14:00" -> "2 PM", "13:30" -> "1:30 PM" */
    public static function clockAmPm(string $time): string
    {
        $ts = strtotime('2000-01-01 ' . substr($time, 0, 5));
        return date((int) date('i', $ts) === 0 ? 'g A' : 'g:i A', $ts);
    }

    /** "2026-11-28" -> "Today" / "Tomorrow" / "Sun 29" (past dates: "Nov 21") */
    public static function dayLabel(string $date): string
    {
        $today = date('Y-m-d');
        if ($date === $today) return 'Today';
        if ($date === date('Y-m-d', strtotime('+1 day'))) return 'Tomorrow';
        if ($date === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
        $ts = strtotime($date);
        return $date > $today ? date('D j', $ts) : date('M j', $ts);
    }

    /** Add minutes to "HH:MM" and return "HH:MM". */
    public static function addMinutes(string $time, int $minutes): string
    {
        return date('H:i', strtotime('2000-01-01 ' . substr($time, 0, 5)) + $minutes * 60);
    }

    /** "Ana Reyes" -> "Ana R." */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) < 2) {
            return $name;
        }
        return $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.';
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }

    // ---- tiny key/value store (sync timestamps, login throttling) ----
    public static function kvGet(string $key, $default = null)
    {
        $v = Db::value('SELECT v FROM kv_store WHERE k = ?', [$key]);
        return $v === null ? $default : json_decode($v, true);
    }

    public static function kvSet(string $key, $value): void
    {
        Db::exec(
            'INSERT INTO kv_store (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()',
            [$key, json_encode($value)]
        );
    }

    public static function kvDelete(string $key): void
    {
        Db::exec('DELETE FROM kv_store WHERE k = ?', [$key]);
    }
}

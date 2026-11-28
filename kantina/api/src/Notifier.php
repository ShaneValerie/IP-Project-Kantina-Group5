<?php
/**
 * Email notifications through the Brevo transactional email API
 * (third-party cloud service, HTTPS + API key).
 *
 *   order received   after GCash payment, or right away for pay-at-counter orders
 *   order ready      when the stall marks it ready (if the student keeps pickup alerts on)
 *   reservation      when a table is booked
 *
 * Every email is saved in the notifications table. If no Brevo key is set,
 * the address is a demo one (@example.com) or the server can't reach Brevo,
 * the email is just logged — ordering never fails because of email.
 */
final class Notifier
{
    private const BREVO_URL = 'https://api.brevo.com/v3/smtp/email';

    public static function orderReceived(int $checkoutId): void
    {
        $c = Db::one('SELECT c.*, u.id AS uid, u.name, u.email FROM checkouts c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$checkoutId]);
        if (!$c || !$c['email']) {
            return;
        }
        $checkout = Orders::formatCheckout($c);
        $rows = '';
        foreach ($checkout['items'] as $i) {
            $addons = $i['addons'] ? ' <span style="color:#5D6E64">+ ' . self::e(implode(', ', array_column($i['addons'], 'name'))) . '</span>' : '';
            $rows .= '<tr><td style="padding:6px 0">' . $i['qty'] . '× ' . self::e($i['name']) . $addons
                . '<div style="color:#8B9990;font-size:12px">' . self::e($i['stall_name']) . '</div></td>'
                . '<td style="padding:6px 0;text-align:right">' . self::peso($i['line_total']) . '</td></tr>';
        }
        $rows .= '<tr><td style="padding:6px 0;color:#5D6E64">Service fee</td><td style="text-align:right;color:#5D6E64">' . self::peso($checkout['service_fee']) . '</td></tr>';
        $rows .= '<tr><td style="padding:10px 0;border-top:1px solid #E4E7E2"><strong>' . ($c['payment_method'] === 'counter' ? 'To pay at the counter' : 'Total paid')
            . '</strong></td><td style="padding:10px 0;border-top:1px solid #E4E7E2;text-align:right"><strong>' . self::peso($checkout['total']) . '</strong></td></tr>';
        $counters = implode(', ', array_map(fn($o) => self::e($o['stall']['name']) . ' (' . self::e($o['counter']) . ')', $checkout['orders']));
        $html = '<p>Hi ' . self::e(explode(' ', $c['name'])[0]) . ', thanks for your order!</p>'
            . '<p><strong>Pickup:</strong> ' . self::e(Support::dayLabel($checkout['pickup_date']) . ' · ' . Support::clockAmPm($checkout['pickup_time'])) . '<br><strong>Where:</strong> ' . $counters . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>'
            . '<p style="color:#5D6E64;font-size:13px">Reference ' . self::e($checkout['ref']) . '. We will email you again when your food is ready.</p>';
        self::send((int) $c['uid'], 'order_received', $c['email'], $c['name'], 'Your Kantina order ' . $checkout['ref'], self::layout('Order received', $html));
    }

    public static function orderReady(int $orderId): void
    {
        $o = Db::one(Orders::orderSql() . ' WHERE o.id = ?', [$orderId]);
        if (!$o || !$o['customer_email']) {
            return;
        }
        if (!(int) Db::value('SELECT notify_ready FROM users WHERE id = ?', [$o['user_id']])) {
            return;   // the student turned pickup alerts off
        }
        $items = Orders::itemsText(Orders::itemsFor([(int) $o['id']])[$o['id']] ?? []);
        $html = '<p style="font-size:18px;margin:0 0 8px"><strong>Your food is ready!</strong></p>'
            . '<p>' . self::e($items) . ' from <strong>' . self::e($o['stall_name']) . '</strong> is waiting for you at <strong>' . self::e($o['counter_label']) . '</strong>.</p>'
            . '<p style="color:#5D6E64;font-size:13px">Show order #' . (int) $o['id'] . ' (' . self::e($o['ref']) . ') at the counter.</p>';
        self::send((int) $o['user_id'], 'order_ready', $o['customer_email'], $o['customer_name'], 'Ready for pickup: ' . $o['stall_name'] . ' #' . $o['id'], self::layout('Ready for pickup', $html));
    }

    /** The CRM replied to the student's feedback (ticket.updated webhook). */
    public static function feedbackReplied(int $feedbackId): void
    {
        $f = Db::one('SELECT f.*, u.name, u.email FROM feedback f JOIN users u ON u.id = f.user_id WHERE f.id = ?', [$feedbackId]);
        if (!$f || !$f['email'] || !$f['reply']) {
            return;
        }
        $html = '<p>Hi ' . self::e(explode(' ', $f['name'])[0]) . ', the canteen replied to your feedback'
            . ($f['crm_ticket_no'] ? ' (' . self::e($f['crm_ticket_no']) . ')' : '') . ':</p>'
            . '<p style="background:#F3F5F1;border-radius:10px;padding:12px">' . nl2br(self::e($f['reply'])) . '</p>'
            . '<p style="color:#5D6E64;font-size:13px">Your message: “' . self::e(mb_strimwidth($f['message'], 0, 160, '…')) . '”</p>';
        self::send((int) $f['user_id'], 'feedback_reply', $f['email'], $f['name'], 'Reply to your Kantina feedback', self::layout('We replied to your feedback', $html));
    }

    public static function reservationConfirmed(array $r): void
    {
        $u = Db::one('SELECT id, name, email FROM users WHERE id = ?', [$r['user_id']]);
        if (!$u || !$u['email']) {
            return;
        }
        $when = Support::dayLabel($r['res_date']) . ', ' . date('F j', strtotime($r['res_date'])) . ' · ' . hm($r['start_time']) . ' – ' . hm($r['end_time']);
        $hold = Support::addMinutes(hm($r['start_time']), (int) Config::get('reservations.grace_minutes', 10));
        $html = '<p>Hi ' . self::e(explode(' ', $u['name'])[0]) . ', your table is booked.</p>'
            . '<p><strong>Table:</strong> ' . self::e($r['table_code']) . '<br><strong>When:</strong> ' . self::e($when)
            . '<br><strong>Party:</strong> ' . (int) $r['party_size'] . '<br><strong>Where:</strong> Lawrence Bunzel Canteen</p>'
            . '<p style="color:#5D6E64;font-size:13px">Check in from the Kantina app when you arrive. Tables are released if nobody checks in by ' . self::e($hold) . '. Reference ' . self::e($r['ref']) . '.</p>';
        self::send((int) $u['id'], 'reservation_confirmed', $u['email'], $u['name'], 'Table ' . $r['table_code'] . ' reserved · ' . Support::dayLabel($r['res_date']) . ' ' . hm($r['start_time']), self::layout('Table reserved', $html));
    }

    // ------------------------------------------------------------------ sending
    private static function send(?int $userId, string $kind, string $to, string $name, string $subject, string $html): void
    {
        $status = 'logged';
        $providerId = null;
        $error = null;
        try {
            $key = (string) Config::get('email.brevo_api_key');
            $from = (string) Config::get('email.from_email');
            if ($key === '' || $from === '') {
                $error = 'Email not configured (no Brevo API key) — logged only';
            } elseif (preg_match('/@example\.(com|org|net)$/i', $to)) {
                $error = 'Demo address — logged only';
            } else {
                $res = HttpClient::request('POST', self::BREVO_URL, [
                    'sender' => ['name' => (string) Config::get('email.from_name', 'Kantina'), 'email' => $from],
                    'to' => [['email' => $to, 'name' => $name]],
                    'subject' => $subject,
                    'htmlContent' => $html,
                ], ['api-key' => $key], 8);
                if ($res['ok']) {
                    $status = 'sent';
                    $providerId = $res['body']['messageId'] ?? null;
                } else {
                    $status = 'failed';
                    $error = 'Brevo: ' . ($res['body']['message'] ?? $res['error']);
                }
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }
        try {
            Db::insert('notifications', [
                'user_id' => $userId, 'kind' => $kind, 'to_email' => $to, 'subject' => $subject,
                'body' => $html, 'status' => $status, 'provider_id' => $providerId, 'error' => $error,
            ]);
        } catch (Throwable $e) {
            error_log('[kantina] could not log notification: ' . $e->getMessage());
        }
    }

    private static function layout(string $title, string $content): string
    {
        return '<!DOCTYPE html><html><body style="margin:0;background:#F3F5F1;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#132A1E">'
            . '<div style="max-width:520px;margin:0 auto;padding:24px 16px">'
            . '<div style="background:#015E2F;color:#fff;border-radius:14px 14px 0 0;padding:18px 22px;font-size:20px;font-weight:700">Kantina'
            . '<div style="font-size:13px;font-weight:400;opacity:.85">' . self::e($title) . '</div></div>'
            . '<div style="background:#fff;border-radius:0 0 14px 14px;padding:20px 22px;font-size:15px;line-height:1.5">' . $content . '</div>'
            . '<p style="text-align:center;color:#8B9990;font-size:12px">Lawrence Bunzel Canteen · University of San Carlos<br>You get this email because you used Kantina.</p>'
            . '</div></body></html>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function peso($n): string
    {
        $n = (float) $n;
        return '₱' . number_format($n, fmod($n, 1.0) ? 2 : 0);
    }
}

<?php
/**
 * Kantina's side of the Kantina CRM integration (loyalty points and feedback tickets).
 *
 *   Kantina → CRM   through the outbox, so nothing is lost if the CRM is offline:
 *     crm.order.completed  a completed order earns loyalty points   (POST /orders)
 *     crm.ticket.create    a student's feedback becomes a ticket     (POST /tickets)
 *   CRM → Kantina   signed webhooks (see Integrations::handleCrm):
 *     customer.updated     an admin changed the points               → users.loyalty_*
 *     ticket.updated       an admin replied / changed the status     → feedback row
 *
 * CRM messages use their own outbox refs ("CRM-KTN-90214", "CRM-FB-12"), so they
 * are never held up behind the ERP messages of the same order.
 */
final class Crm
{
    /** The `customer` object the CRM expects with every call. */
    public static function customer(int $userId): array
    {
        $u = Db::one('SELECT id, student_id, name, email FROM users WHERE id = ?', [$userId]);
        if (!$u) {
            throw new HttpError(404, 'user_not_found', 'User not found.');
        }
        return array_filter([
            'kantina_user_id' => (int) $u['id'],
            'student_id' => $u['student_id'],
            'name' => $u['name'],
            'email' => $u['email'],
        ], fn($v) => $v !== null);
    }

    public static function orderOutboxRef(string $orderRef): string
    {
        return 'CRM-' . $orderRef;
    }

    public static function feedbackRef(int $feedbackId): string
    {
        return 'FB-' . $feedbackId;
    }

    /** Call inside the transaction that marks the order completed. Returns the outbox ref. */
    public static function enqueueOrderCompleted(array $order): string
    {
        $ref = self::orderOutboxRef($order['ref']);
        Outbox::enqueue('crm.order.completed', $ref, [
            'customer' => self::customer((int) $order['user_id']),
            'order_ref' => $order['ref'],
            'stall_id' => $order['stall_id'],
            'stall_name' => $order['stall_name'],
            'amount' => money($order['subtotal']),
            'completed_at' => date('c'),
        ]);
        return $ref;
    }

    /** Save the CRM's points/tier on the student (from a POST /orders answer or a customer.updated webhook). */
    public static function applyLoyalty(array $customer): void
    {
        if (empty($customer['kantina_user_id']) || !isset($customer['points'])) {
            throw new HttpError(400, 'invalid_payload', 'Missing customer points.');
        }
        Db::exec(
            "UPDATE users SET loyalty_points = ?, loyalty_tier = ? WHERE id = ? AND role = 'student'",
            [max(0, (int) $customer['points']), $customer['tier'] ?? null, (int) $customer['kantina_user_id']]
        );
    }

    /**
     * Save a ticket's number/status/reply on the feedback it came from
     * (from a POST /tickets answer or a ticket.updated webhook).
     * Emails the student when a new reply arrives. Returns a note for the webhook log.
     */
    public static function applyTicket(array $ticket, bool $notify = true): ?string
    {
        if (!preg_match('/^FB-(\d+)$/', (string) ($ticket['external_ref'] ?? ''), $m)) {
            return 'Ignored: not a Kantina feedback ref';
        }
        $fb = Db::one('SELECT * FROM feedback WHERE id = ?', [(int) $m[1]]);
        if (!$fb) {
            return 'Ignored: unknown feedback ' . $ticket['external_ref'];
        }
        $status = in_array($ticket['status'] ?? null, ['open', 'in_progress', 'resolved'], true) ? $ticket['status'] : $fb['status'];
        $reply = array_key_exists('reply', $ticket) ? ($ticket['reply'] === '' ? null : $ticket['reply']) : $fb['reply'];
        Db::exec(
            'UPDATE feedback SET crm_ticket_no = COALESCE(?, crm_ticket_no), status = ?, reply = ? WHERE id = ?',
            [$ticket['ticket_no'] ?? null, $status, $reply, $fb['id']]
        );
        if ($notify && $reply !== null && $reply !== $fb['reply'] && class_exists('Notifier')) {
            Notifier::feedbackReplied((int) $fb['id']);
        }
        return null;
    }
}

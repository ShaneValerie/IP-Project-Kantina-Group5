<?php
/**
 * Feedback / support tickets.
 *
 *   - Kantina sends a student's feedback with POST /tickets. external_ref is
 *     Kantina's feedback id, so sending it twice returns the same ticket.
 *   - An admin replies and moves it open → in_progress → resolved. Each
 *     change is sent back to Kantina as a ticket.updated webhook, so the
 *     student sees the reply.
 */
final class Tickets
{
    public const CATEGORIES = ['order_issue', 'complaint', 'suggestion', 'praise'];
    public const STATUSES = ['open', 'in_progress', 'resolved'];
    public const LABELS = [
        'order_issue' => 'Order issue', 'complaint' => 'Complaint', 'suggestion' => 'Suggestion', 'praise' => 'Praise',
        'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved',
    ];

    public static function format(array $t, bool $withCustomer = true): array
    {
        $out = [
            'ticket_no' => $t['ticket_no'],
            'external_ref' => $t['external_ref'],
            'order_ref' => $t['order_ref'],
            'stall_id' => $t['stall_id'],
            'category' => $t['category'],
            'rating' => $t['rating'] === null ? null : (int) $t['rating'],
            'message' => $t['message'],
            'status' => $t['status'],
            'reply' => $t['reply'],
            'created_at' => iso($t['created_at']),
            'updated_at' => iso($t['updated_at']),
            'resolved_at' => iso($t['resolved_at']),
        ];
        if ($withCustomer && isset($t['customer_name'])) {
            $out['customer'] = [
                'kantina_user_id' => (int) $t['kantina_user_id'],
                'student_id' => $t['student_id'],
                'name' => $t['customer_name'],
            ];
        }
        return $out;
    }

    private static function baseSql(): string
    {
        return 'SELECT t.*, c.kantina_user_id, c.student_id, c.name AS customer_name
                  FROM crm_tickets t JOIN crm_customers c ON c.id = t.customer_id';
    }

    public static function list(?string $status = null, int $limit = 200): array
    {
        if ($status !== null && $status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new HttpError(422, 'validation_error', 'Unknown status.', ['status' => 'Must be one of: ' . implode(', ', self::STATUSES) . '.']);
        }
        $sql = self::baseSql();
        $args = [];
        if ($status) {
            $sql .= ' WHERE t.status = ?';
            $args[] = $status;
        }
        // Open tickets first, then newest.
        $sql .= " ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved'), t.created_at DESC LIMIT " . max(1, $limit);
        return Db::all($sql, $args);
    }

    public static function forCustomer(int $customerId): array
    {
        return Db::all(self::baseSql() . ' WHERE t.customer_id = ? ORDER BY t.created_at DESC', [$customerId]);
    }

    /** By ticket number (TCK-0007) or Kantina's external_ref (FB-12). */
    public static function get(string $ref): array
    {
        $t = Db::one(self::baseSql() . ' WHERE t.ticket_no = ? OR t.external_ref = ?', [$ref, $ref]);
        if (!$t) {
            throw new HttpError(404, 'ticket_not_found', 'Ticket not found.');
        }
        return $t;
    }

    /**
     * POST /tickets. Idempotent by external_ref.
     *
     * @return array [ticketRow, created(bool)]
     */
    public static function create(array $in): array
    {
        $d = Validator::check($in, [
            'customer' => 'required|array',
            'external_ref' => 'required|string|max:40',
            'order_ref' => 'string|max:40',
            'stall_id' => 'string|max:40',
            'category' => 'required|in:' . implode(',', self::CATEGORIES),
            'rating' => 'int|min:1|max:5',
            'message' => 'required|string|min:3|max:2000',
        ]);
        if ($existing = Db::one('SELECT ticket_no FROM crm_tickets WHERE external_ref = ?', [$d['external_ref']])) {
            return [self::get($existing['ticket_no']), false];
        }
        try {
            $ticketNo = Db::tx(function () use ($d, $in) {
                $customer = Customers::upsert($in['customer']);
                $id = Db::insert('crm_tickets', [
                    'external_ref' => $d['external_ref'],
                    'customer_id' => $customer['id'],
                    'order_ref' => $d['order_ref'] ?? null,
                    'stall_id' => $d['stall_id'] ?? null,
                    'category' => $d['category'],
                    'rating' => $d['rating'] ?? null,
                    'message' => $d['message'],
                ]);
                // Readable number from the id, like the ERP's SO-000123.
                $ticketNo = sprintf('TCK-%04d', $id);
                Db::exec('UPDATE crm_tickets SET ticket_no = ? WHERE id = ?', [$ticketNo, $id]);
                Db::insert('crm_interactions', [
                    'customer_id' => $customer['id'],
                    'type' => 'ticket',
                    'ref' => $ticketNo,
                    'note' => self::LABELS[$d['category']] . (!empty($d['order_ref']) ? ' · ' . $d['order_ref'] : ''),
                ]);
                return $ticketNo;
            });
        } catch (PDOException $e) {
            // Sent twice at the same moment: the UNIQUE external_ref let one through.
            if ($e->getCode() === '23000' && ($row = Db::one('SELECT ticket_no FROM crm_tickets WHERE external_ref = ?', [$d['external_ref']]))) {
                return [self::get($row['ticket_no']), false];
            }
            throw $e;
        }
        return [self::get($ticketNo), true];
    }

    /**
     * Admin: save a reply and/or a new status. Sends ticket.updated to Kantina.
     *
     * @return array [ticketRow, notified(bool)]
     */
    public static function update(string $ticketNo, array $in): array
    {
        $d = Validator::check($in, [
            'status' => 'required|in:' . implode(',', self::STATUSES),
            'reply' => 'string|max:2000',
        ]);
        $ticket = self::get($ticketNo);
        $reply = array_key_exists('reply', $d) ? $d['reply'] : $ticket['reply'];
        if ($d['status'] === 'resolved' && ($reply === null || $reply === '')) {
            throw new HttpError(422, 'validation_error', 'Write a reply to the student before resolving the ticket.', ['reply' => 'Required to resolve.']);
        }
        if ($d['status'] === $ticket['status'] && (string) $reply === (string) $ticket['reply']) {
            return [$ticket, null];   // nothing changed, nothing to send
        }
        Db::exec(
            "UPDATE crm_tickets SET status = ?, reply = ?,
                    resolved_at = CASE WHEN ? = 'resolved' THEN COALESCE(resolved_at, NOW()) ELSE NULL END
              WHERE id = ?",
            [$d['status'], $reply === '' ? null : $reply, $d['status'], $ticket['id']]
        );
        $ticket = self::get($ticketNo);
        $notified = Events::send('ticket.updated', ['ticket' => [
            'external_ref' => $ticket['external_ref'],
            'ticket_no' => $ticket['ticket_no'],
            'status' => $ticket['status'],
            'reply' => $ticket['reply'],
        ]]);
        return [$ticket, $notified];
    }
}

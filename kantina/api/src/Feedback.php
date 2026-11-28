<?php
/**
 * Student feedback → Kantina CRM tickets.
 *
 *   POST /feedback   save the feedback and queue it for the CRM (one transaction),
 *                    then try to deliver it right away
 *   GET  /feedback   my feedback, with the CRM's status and reply
 *
 * The CRM turns it into a ticket (TCK-…) and sends replies back with the
 * ticket.updated webhook (Integrations::handleCrm → Crm::applyTicket).
 */
final class Feedback
{
    public const CATEGORIES = ['order_issue', 'complaint', 'suggestion', 'praise'];
    private const LABELS = [
        'order_issue' => 'Problem with an order', 'complaint' => 'Complaint',
        'suggestion' => 'Suggestion', 'praise' => 'Praise',
        'open' => 'Sent', 'in_progress' => 'Being looked at', 'resolved' => 'Answered',
    ];

    public static function routes(Router $r): void
    {
        $r->post('/feedback', [self::class, 'create'], [Auth::student()]);
        $r->get('/feedback', [self::class, 'index'], [Auth::student()]);
    }

    public static function format(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'ref' => Crm::feedbackRef((int) $f['id']),
            'order_ref' => $f['order_ref'],
            'stall' => $f['stall_id'] ? ['id' => $f['stall_id'], 'name' => $f['stall_name'] ?? null] : null,
            'category' => $f['category'],
            'category_label' => self::LABELS[$f['category']],
            'rating' => $f['rating'] === null ? null : (int) $f['rating'],
            'message' => $f['message'],
            'status' => $f['status'],
            // No ticket number yet = still waiting in the outbox (CRM offline).
            'status_label' => $f['crm_ticket_no'] ? self::LABELS[$f['status']] : 'Sending…',
            'reply' => $f['reply'],
            'ticket_no' => $f['crm_ticket_no'],
            'created_at' => iso($f['created_at']),
            'updated_at' => iso($f['updated_at']),
        ];
    }

    private static function sql(): string
    {
        return 'SELECT f.*, s.name AS stall_name FROM feedback f LEFT JOIN stalls s ON s.id = f.stall_id';
    }

    public static function index(Request $req): array
    {
        return ['feedback' => array_map([self::class, 'format'], Db::all(
            self::sql() . ' WHERE f.user_id = ? ORDER BY f.created_at DESC, f.id DESC LIMIT 50',
            [$req->user['id']]
        ))];
    }

    public static function create(Request $req): array
    {
        $d = Validator::check($req->body(), [
            'category' => 'required|in:' . implode(',', self::CATEGORIES),
            'message' => 'required|string|min:3|max:2000',
            'rating' => 'int|min:1|max:5',
            'order_ref' => 'string|max:20',
        ]);
        $uid = (int) $req->user['id'];

        // An order is optional, but if given it must be one of mine (its stall goes on the ticket).
        $stallId = null;
        if (!empty($d['order_ref'])) {
            $stallId = Db::value('SELECT stall_id FROM orders WHERE ref = ? AND user_id = ?', [$d['order_ref'], $uid]);
            if (!$stallId) {
                throw new HttpError(422, 'validation_error', 'Choose one of your own orders.', ['order_ref' => 'Not one of your orders.']);
            }
        }

        $id = Db::tx(function () use ($d, $uid, $stallId) {
            $id = Db::insert('feedback', [
                'user_id' => $uid,
                'order_ref' => $d['order_ref'] ?? null,
                'stall_id' => $stallId,
                'category' => $d['category'],
                'rating' => $d['rating'] ?? null,
                'message' => $d['message'],
            ]);
            // Same transaction: the feedback and its message to the CRM are saved together.
            Outbox::enqueue('crm.ticket.create', 'CRM-' . Crm::feedbackRef($id), [
                'customer' => Crm::customer($uid),
                'external_ref' => Crm::feedbackRef($id),
                'order_ref' => $d['order_ref'] ?? null,
                'stall_id' => $stallId,
                'category' => $d['category'],
                'rating' => $d['rating'] ?? null,
                'message' => $d['message'],
            ]);
            return $id;
        });
        Outbox::process(['CRM-' . Crm::feedbackRef($id)], 1, 3);

        return [['feedback' => self::format(Db::one(self::sql() . ' WHERE f.id = ?', [$id]))], 201];
    }
}

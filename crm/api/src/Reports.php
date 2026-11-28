<?php
/**
 * CRM summary numbers: used by GET /reports/summary and the admin dashboard.
 */
final class Reports
{
    public static function summary(): array
    {
        $tiers = ['Bronze' => 0, 'Silver' => 0, 'Gold' => 0];
        foreach (Db::all('SELECT tier, COUNT(*) AS n FROM crm_customers GROUP BY tier') as $row) {
            $tiers[$row['tier']] = (int) $row['n'];
        }
        $tickets = ['open' => 0, 'in_progress' => 0, 'resolved' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM crm_tickets GROUP BY status') as $row) {
            $tickets[$row['status']] = (int) $row['n'];
        }
        $rating = Db::one('SELECT AVG(rating) AS avg_rating, COUNT(rating) AS rated FROM crm_tickets WHERE rating IS NOT NULL');

        return [
            'customers' => array_sum($tiers),
            'tiers' => $tiers,
            'points_outstanding' => (int) Db::value('SELECT COALESCE(SUM(points), 0) FROM crm_customers'),
            'points_earned_from_orders' => (int) Db::value("SELECT COALESCE(SUM(points), 0) FROM crm_interactions WHERE type = 'order_completed'"),
            'orders' => (int) Db::value("SELECT COUNT(*) FROM crm_interactions WHERE type = 'order_completed'"),
            'revenue' => money(Db::value('SELECT COALESCE(SUM(total_spent), 0) FROM crm_customers')),
            'tickets' => $tickets,
            'average_rating' => $rating['rated'] ? round((float) $rating['avg_rating'], 1) : null,
            'rated_tickets' => (int) $rating['rated'],
        ];
    }
}

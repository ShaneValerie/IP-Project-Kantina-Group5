<?php
/**
 * Sales reports from the ledger. Only PAID sales orders count as revenue.
 */
final class Reports
{
    /** [from, to] as Y-m-d strings; defaults to the last 7 days including today. */
    public static function range(?string $from, ?string $to, int $defaultDays = 7): array
    {
        $to = $to && strtotime($to) ? date('Y-m-d', strtotime($to)) : date('Y-m-d');
        $from = $from && strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-d', strtotime($to . ' -' . ($defaultDays - 1) . ' days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private static function where(?string $stall, string $from, string $to): array
    {
        $sql = " WHERE so.status = 'paid' AND so.created_at BETWEEN ? AND ?";
        $params = ["$from 00:00:00", "$to 23:59:59"];
        if ($stall) {
            $sql .= ' AND so.stall_code = ?';
            $params[] = $stall;
        }
        return [$sql, $params];
    }

    public static function sales(?string $stall, string $from, string $to): array
    {
        [$where, $params] = self::where($stall, $from, $to);
        $totals = Db::one("SELECT COUNT(*) AS orders, COALESCE(SUM(so.total), 0) AS revenue FROM erp_sales_orders so $where", $params);
        $orders = (int) $totals['orders'];
        $revenue = money($totals['revenue']);

        $days = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $key = date('Y-m-d', $d);
            $days[$key] = ['date' => $key, 'label' => date('D', $d), 'revenue' => 0.0, 'orders' => 0];
        }
        foreach (Db::all("SELECT DATE(so.created_at) AS d, COUNT(*) AS orders, SUM(so.total) AS revenue FROM erp_sales_orders so $where GROUP BY DATE(so.created_at)", $params) as $row) {
            if (isset($days[$row['d']])) {
                $days[$row['d']]['revenue'] = money($row['revenue']);
                $days[$row['d']]['orders'] = (int) $row['orders'];
            }
        }

        $hours = [];
        foreach (Db::all("SELECT HOUR(so.created_at) AS h, COUNT(*) AS orders, SUM(so.total) AS revenue FROM erp_sales_orders so $where GROUP BY HOUR(so.created_at) ORDER BY h", $params) as $row) {
            $hours[] = ['hour' => (int) $row['h'], 'orders' => (int) $row['orders'], 'revenue' => money($row['revenue'])];
        }

        $byChannel = [];
        foreach (Db::all("SELECT so.channel, COUNT(*) AS orders, SUM(so.total) AS revenue FROM erp_sales_orders so $where GROUP BY so.channel", $params) as $row) {
            $byChannel[$row['channel']] = ['orders' => (int) $row['orders'], 'revenue' => money($row['revenue'])];
        }

        return [
            'stall' => $stall,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'revenue' => $revenue,
                'orders' => $orders,
                'avg_ticket' => $orders ? round($revenue / $orders, 2) : 0,
            ],
            'by_channel' => $byChannel,
            'by_day' => array_values($days),
            'by_hour' => $hours,
        ];
    }

    public static function topItems(?string $stall, string $from, string $to, int $limit = 5, ?string $type = 'menu'): array
    {
        [$where, $params] = self::where($stall, $from, $to);
        $typeSql = '';
        if ($type) {
            $typeSql = ' AND p.type = ?';
            $params[] = $type;
        }
        return array_map(fn($r) => [
            'sku' => $r['sku'],
            'name' => $r['name'],
            'qty' => (int) $r['qty'],
            'revenue' => money($r['revenue']),
        ], Db::all(
            "SELECT l.sku, p.name, SUM(l.qty) AS qty, SUM(l.line_total) AS revenue
             FROM erp_sales_order_lines l
             JOIN erp_sales_orders so ON so.id = l.sales_order_id
             JOIN erp_products p ON p.sku = l.sku
             $where $typeSql
             GROUP BY l.sku, p.name
             ORDER BY qty DESC, revenue DESC
             LIMIT " . max(1, min(50, $limit)),
            $params
        ));
    }
}

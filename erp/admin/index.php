<?php
require __DIR__ . '/_init.php';
require_login();

$today = Reports::sales(null, date('Y-m-d'), date('Y-m-d'));
$week = Reports::sales(null, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
$openOrders = (int) Db::value("SELECT COUNT(*) FROM erp_sales_orders WHERE status = 'open'");
$lowStock = Inventory::list(['low_stock' => true]);
$recent = Sales::list([], 8);
$failedHooks = (int) Db::value("SELECT COUNT(*) FROM erp_webhook_deliveries WHERE status <> 'delivered'");
$kantina = HttpClient::request('GET', Config::url('kantina') . '/api/health', null, [], 3);
$names = stall_names();
$max = max(array_map(fn($d) => $d['revenue'], $week['by_day'])) ?: 1;

layout_start('Dashboard', 'index');
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <div class="muted"><?= h(date('l, F j, Y')) ?></div>
  </div>
  <div>
    Kantina connection:
    <?php if ($kantina['ok']): ?><span class="badge ok">online</span>
    <?php else: ?><span class="badge bad" title="<?= h($kantina['error']) ?>">offline</span><?php endif; ?>
  </div>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="label">Revenue today</div><div class="value"><?= peso($today['totals']['revenue']) ?></div></div>
  <div class="card stat"><div class="label">Paid orders today</div><div class="value"><?= (int) $today['totals']['orders'] ?></div></div>
  <div class="card stat"><div class="label">Open (unpaid) orders</div><div class="value"><?= $openOrders ?></div></div>
  <div class="card stat"><div class="label">Low-stock items</div><div class="value"><?= count($lowStock) ?></div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Revenue, last 7 days · <?= peso($week['totals']['revenue']) ?></h2>
    <div class="bars">
      <?php foreach ($week['by_day'] as $d): ?>
        <div class="bar" style="height:<?= round($d['revenue'] / $max * 100) ?>%" title="<?= h($d['date'] . ': ' . peso($d['revenue'])) ?>"></div>
      <?php endforeach; ?>
    </div>
    <div class="bar-labels"><?php foreach ($week['by_day'] as $d): ?><span><?= h($d['label']) ?></span><?php endforeach; ?></div>
  </div>
  <div class="card">
    <h2>Needs restocking</h2>
    <?php if (!$lowStock): ?><p class="muted">All items are above their reorder level.</p><?php else: ?>
    <table>
      <tr><th>Item</th><th>Stall</th><th class="num">Stock</th><th class="num">Reorder at</th></tr>
      <?php foreach ($lowStock as $p): ?>
        <tr>
          <td><a href="products.php?stall=<?= h($p['stall_code']) ?>#<?= h($p['sku']) ?>"><?= h($p['name']) ?></a></td>
          <td><?= h($names[$p['stall_code']] ?? $p['stall_code']) ?></td>
          <td class="num"><span class="badge <?= (int) $p['stock'] <= 0 ? 'bad' : 'warn' ?>"><?= (int) $p['stock'] ?></span></td>
          <td class="num"><?= (int) $p['reorder_level'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Latest sales orders</h2>
  <table>
    <tr><th>SO</th><th>Channel</th><th>Reference</th><th>Stall</th><th>Status</th><th class="num">Total</th><th>Time</th></tr>
    <?php foreach ($recent as $so): ?>
      <tr>
        <td><a href="sales.php?so=<?= h($so['so_no']) ?>"><?= h($so['so_no']) ?></a></td>
        <td><?= $so['channel'] === 'kantina' ? '<span class="badge">Kantina app</span>' : '<span class="badge gray">Walk-in</span>' ?></td>
        <td><?= h($so['external_ref'] ?? '—') ?></td>
        <td><?= h($names[$so['stall_code']] ?? $so['stall_code']) ?></td>
        <td><?= status_badge($so['status']) ?></td>
        <td class="num"><?= peso($so['total']) ?></td>
        <td class="muted"><?= h(date('M j, g:i A', strtotime($so['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($failedHooks): ?>
  <div class="flash error"><?= $failedHooks ?> webhook(s) to Kantina have not been delivered. <a href="webhooks.php">Review and resend</a></div>
<?php endif; ?>
<?php
layout_end();

<?php
require __DIR__ . '/_init.php';
require_login();

$sum = Reports::summary();
$latest = array_slice(Tickets::list(null, 50), 0, 6);
$top = Customers::list(null, 5);
$failed = (int) Db::value("SELECT COUNT(*) FROM crm_webhook_deliveries WHERE status <> 'delivered'");
$kantina = HttpClient::request('GET', Config::url('kantina') . '/api/health', null, [], 3);

layout_start('Dashboard', 'index');
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <div class="muted">Customer records, loyalty and feedback for the Kantina canteen.</div>
  </div>
  <div>
    Kantina connection:
    <?php if ($kantina['ok']): ?><span class="badge ok">online</span>
    <?php else: ?><span class="badge bad" title="<?= h($kantina['error']) ?>">offline</span><?php endif; ?>
  </div>
</div>

<?php if ($failed): ?>
  <div class="flash error"><?= $failed ?> webhook(s) to Kantina have not been delivered. <a href="webhooks.php">Review and resend</a></div>
<?php endif; ?>

<div class="grid grid-4">
  <div class="card stat"><div class="label">Customers</div><div class="value"><?= $sum['customers'] ?></div>
    <div class="small muted"><?= $sum['tiers']['Gold'] ?> Gold · <?= $sum['tiers']['Silver'] ?> Silver · <?= $sum['tiers']['Bronze'] ?> Bronze</div></div>
  <div class="card stat"><div class="label">Open tickets</div><div class="value"><?= $sum['tickets']['open'] ?></div>
    <div class="small muted"><?= $sum['tickets']['in_progress'] ?> in progress · <?= $sum['tickets']['resolved'] ?> resolved</div></div>
  <div class="card stat"><div class="label">Average rating</div><div class="value"><?= $sum['average_rating'] === null ? '—' : h($sum['average_rating']) . ' / 5' ?></div>
    <div class="small muted">from <?= $sum['rated_tickets'] ?> rated ticket(s)</div></div>
  <div class="card stat"><div class="label">Points held</div><div class="value"><?= number_format($sum['points_outstanding']) ?></div>
    <div class="small muted"><?= number_format($sum['points_earned_from_orders']) ?> earned from <?= $sum['orders'] ?> order(s)</div></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Latest tickets</h2>
    <?php if (!$latest): ?><p class="muted">No tickets yet.</p><?php else: ?>
    <table>
      <tr><th>Ticket</th><th>Customer</th><th>Category</th><th>Status</th></tr>
      <?php foreach ($latest as $t): ?>
        <tr class="clickable" onclick="location.href='tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>'">
          <td><a href="tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>"><?= h($t['ticket_no']) ?></a></td>
          <td><?= h($t['customer_name']) ?></td>
          <td><?= h(Tickets::LABELS[$t['category']]) ?></td>
          <td><?= status_badge($t['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Top customers by points</h2>
    <?php if (!$top): ?><p class="muted">No customers yet. They appear when Kantina sends their first order or feedback.</p><?php else: ?>
    <table>
      <tr><th>Customer</th><th>Tier</th><th class="num">Points</th></tr>
      <?php foreach ($top as $c): ?>
        <tr class="clickable" onclick="location.href='customers.php?id=<?= (int) $c['id'] ?>'">
          <td><a href="customers.php?id=<?= (int) $c['id'] ?>"><?= h($c['name']) ?></a><div class="small muted"><?= h($c['student_id']) ?></div></td>
          <td><?= tier_badge($c['tier']) ?></td>
          <td class="num"><?= number_format((int) $c['points']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Loyalty rules</h2>
  <p class="muted" style="margin:0;">1 point for every ₱<?= Customers::PESOS_PER_POINT ?> of a completed order (rounded down).
  Tiers: <strong>Bronze</strong> below 100 points, <strong>Silver</strong> 100–299, <strong>Gold</strong> 300 and up.
  API: <code><?= h(Config::url('crm')) ?>/api/v1</code> · Webhooks to <code><?= h(Events::url()) ?></code></p>
</div>
<?php
layout_end();

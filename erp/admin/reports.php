<?php
require __DIR__ . '/_init.php';
require_login();
$names = stall_names();
$stall = isset($_GET['stall'], $names[$_GET['stall']]) ? $_GET['stall'] : null;
$days = in_array((int) ($_GET['days'] ?? 7), [7, 30], true) ? (int) ($_GET['days'] ?? 7) : 7;
[$from, $to] = Reports::range($_GET['from'] ?? null, $_GET['to'] ?? null, $days);

$sales = Reports::sales($stall, $from, $to);
$top = Reports::topItems($stall, $from, $to, 8);
$max = max(array_map(fn($d) => $d['revenue'], $sales['by_day'])) ?: 1;
$hourMax = max(array_map(fn($h) => $h['orders'], $sales['by_hour']) ?: [1]) ?: 1;
$topMax = $top ? max(array_column($top, 'qty')) : 1;

layout_start('Reports', 'reports');
?>
<div class="page-head">
  <div>
    <h1>Sales report</h1>
    <div class="muted"><?= h(date('M j', strtotime($from))) ?> – <?= h(date('M j, Y', strtotime($to))) ?> · <?= $stall ? h($names[$stall]) : 'All stalls' ?> · paid orders only</div>
  </div>
  <form class="filters" method="get">
    <div class="field"><label for="stall">Stall</label>
      <select id="stall" name="stall"><option value="">All stalls</option>
        <?php foreach ($names as $code => $name): ?><option value="<?= h($code) ?>"<?= $stall === $code ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="days">Period</label>
      <select id="days" name="days"><option value="7"<?= $days === 7 ? ' selected' : '' ?>>Last 7 days</option><option value="30"<?= $days === 30 ? ' selected' : '' ?>>Last 30 days</option></select></div>
    <button class="btn" type="submit">Show</button>
  </form>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="label">Revenue</div><div class="value"><?= peso($sales['totals']['revenue']) ?></div></div>
  <div class="card stat"><div class="label">Orders</div><div class="value"><?= number_format($sales['totals']['orders']) ?></div></div>
  <div class="card stat"><div class="label">Average ticket</div><div class="value"><?= peso(round($sales['totals']['avg_ticket'])) ?></div></div>
  <div class="card stat"><div class="label">Via Kantina app</div><div class="value"><?= peso($sales['by_channel']['kantina']['revenue'] ?? 0) ?></div></div>
</div>

<div class="card">
  <h2>Revenue by day</h2>
  <div class="bars">
    <?php foreach ($sales['by_day'] as $d): ?>
      <div class="bar" style="height:<?= round($d['revenue'] / $max * 100) ?>%" title="<?= h($d['date'] . ': ' . peso($d['revenue']) . ' · ' . $d['orders'] . ' orders') ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="bar-labels"><?php foreach ($sales['by_day'] as $d): ?><span><?= h($days === 7 ? $d['label'] : date('j', strtotime($d['date']))) ?></span><?php endforeach; ?></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Top items</h2>
    <table>
      <?php foreach ($top as $i => $item): ?>
        <tr>
          <td style="width:40%;"><?= $i + 1 ?>. <?= h($item['name']) ?></td>
          <td><div class="hbar"><span style="width:<?= round($item['qty'] / $topMax * 100) ?>%"></span></div></td>
          <td class="num"><?= $item['qty'] ?> sold</td>
          <td class="num muted"><?= peso($item['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card">
    <h2>Orders by hour</h2>
    <table>
      <?php foreach ($sales['by_hour'] as $hRow): ?>
        <tr>
          <td style="width:90px;"><?= h(date('g A', mktime($hRow['hour'], 0))) ?></td>
          <td><div class="hbar"><span style="width:<?= round($hRow['orders'] / $hourMax * 100) ?>%"></span></div></td>
          <td class="num"><?= $hRow['orders'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php
layout_end();

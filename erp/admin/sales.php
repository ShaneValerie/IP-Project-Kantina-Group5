<?php
require __DIR__ . '/_init.php';
require_login();
$names = stall_names();

$filters = [
    'stall' => $_GET['stall'] ?? '',
    'status' => $_GET['status'] ?? '',
    'channel' => $_GET['channel'] ?? '',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
    'q' => trim($_GET['q'] ?? ''),
];
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = Sales::count($filters);
$rows = Sales::list($filters, $perPage, ($page - 1) * $perPage);
$pages = max(1, (int) ceil($total / $perPage));
$detail = !empty($_GET['so']) ? Sales::find($_GET['so']) : null;

layout_start('Sales ledger', 'sales');
?>
<div class="page-head">
  <div>
    <h1>Sales ledger</h1>
    <div class="muted">Every sale: online orders from the Kantina app and walk-in counter sales.</div>
  </div>
</div>

<?php if ($detail): $so = Sales::format($detail); ?>
<div class="card">
  <h2><?= h($so['so_no']) ?> · <?= h($names[$so['stall_code']] ?? $so['stall_code']) ?> <?= status_badge($so['status']) ?></h2>
  <p class="muted" style="margin-top:-6px;">
    <?= $so['channel'] === 'kantina' ? 'Kantina app order ' . h($so['external_ref']) : 'Walk-in sale' ?>
    · created <?= h(date('M j, Y g:i A', strtotime($detail['created_at']))) ?>
    <?= $detail['paid_at'] ? '· paid ' . h(date('g:i A', strtotime($detail['paid_at']))) : '' ?>
    <?= $detail['cancelled_at'] ? '· cancelled ' . h(date('g:i A', strtotime($detail['cancelled_at']))) : '' ?>
  </p>
  <table>
    <tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Line total</th></tr>
    <?php foreach ($so['lines'] as $l): ?>
      <tr><td class="small"><?= h($l['sku']) ?></td><td><?= h($l['name']) ?></td><td class="num"><?= $l['qty'] ?></td><td class="num"><?= peso($l['unit_price']) ?></td><td class="num"><?= peso($l['line_total']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="4" class="num"><strong>Total</strong></td><td class="num"><strong><?= peso($so['total']) ?></strong></td></tr>
  </table>
</div>
<?php endif; ?>

<form class="card filters" method="get">
  <div class="field"><label for="q">SO / Kantina ref</label><input type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="KTN-90214"></div>
  <div class="field"><label for="stall">Stall</label>
    <select id="stall" name="stall"><option value="">All</option>
      <?php foreach ($names as $code => $name): ?><option value="<?= h($code) ?>"<?= $filters['stall'] === $code ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="status">Status</label>
    <select id="status" name="status"><option value="">All</option>
      <?php foreach (['open', 'paid', 'cancelled'] as $s): ?><option<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="channel">Channel</label>
    <select id="channel" name="channel"><option value="">All</option>
      <option value="kantina"<?= $filters['channel'] === 'kantina' ? ' selected' : '' ?>>Kantina app</option>
      <option value="walk_in"<?= $filters['channel'] === 'walk_in' ? ' selected' : '' ?>>Walk-in</option>
    </select></div>
  <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($filters['from']) ?>"></div>
  <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($filters['to']) ?>"></div>
  <button class="btn" type="submit">Filter</button>
  <a class="btn secondary" href="sales.php">Reset</a>
</form>

<div class="card table-wrap">
  <p class="muted" style="margin-top:0;"><?= number_format($total) ?> sales orders</p>
  <table>
    <tr><th>SO</th><th>Channel</th><th>Reference</th><th>Stall</th><th>Status</th><th class="num">Total</th><th>Created</th></tr>
    <?php foreach ($rows as $so): ?>
      <tr>
        <td><a href="?<?= h(http_build_query(array_merge(array_filter($filters), ['page' => $page, 'so' => $so['so_no']]))) ?>"><?= h($so['so_no']) ?></a></td>
        <td><?= $so['channel'] === 'kantina' ? '<span class="badge">Kantina app</span>' : '<span class="badge gray">Walk-in</span>' ?></td>
        <td><?= h($so['external_ref'] ?? '—') ?></td>
        <td><?= h($names[$so['stall_code']] ?? $so['stall_code']) ?></td>
        <td><?= status_badge($so['status']) ?></td>
        <td class="num"><?= peso($so['total']) ?></td>
        <td class="muted"><?= h(date('M j, g:i A', strtotime($so['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($pages > 1): ?>
    <p>
      <?php if ($page > 1): ?><a class="btn small secondary" href="?<?= h(http_build_query(array_merge(array_filter($filters), ['page' => $page - 1]))) ?>">← Newer</a><?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn small secondary" href="?<?= h(http_build_query(array_merge(array_filter($filters), ['page' => $page + 1]))) ?>">Older →</a><?php endif; ?>
    </p>
  <?php endif; ?>
</div>
<?php
layout_end();

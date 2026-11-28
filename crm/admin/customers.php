<?php
require __DIR__ . '/_init.php';
$admin = require_login();

// ---- Adjust points (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'adjust') {
        $sign = ($_POST['direction'] ?? 'add') === 'remove' ? -1 : 1;
        $amount = trim((string) ($_POST['points'] ?? ''));
        try {
            if (!ctype_digit($amount)) {
                throw new HttpError(422, 'validation_error', 'Enter a whole number of points.');
            }
            [$c, $notified] = Customers::adjustPoints($id, $sign * (int) $amount, (string) ($_POST['reason'] ?? ''), $admin['username']);
            flash_notified("Points saved: {$c['name']} now has {$c['points']} ({$c['tier']})", $notified);
        } catch (HttpError $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('customers.php?id=' . $id);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

// ---- Detail view ----
if ($id) {
    try {
        $c = Customers::byId($id);
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
        redirect('customers.php');
    }
    $timeline = Customers::interactions($id);
    $tickets = Tickets::forCustomer($id);
    $nextTier = null;
    foreach (array_reverse(Customers::TIERS, true) as $tier => $min) {
        if ((int) $c['points'] < $min) {
            $nextTier = [$tier, $min - (int) $c['points']];
            break;
        }
    }

    layout_start($c['name'], 'customers');
    ?>
    <div class="page-head">
      <div>
        <div class="small"><a href="customers.php">← All customers</a></div>
        <h1><?= h($c['name']) ?> <?= tier_badge($c['tier']) ?></h1>
        <div class="muted"><?= h($c['student_id'] ?: '—') ?> · <?= h($c['email'] ?: 'no email') ?> · Kantina user #<?= (int) $c['kantina_user_id'] ?></div>
      </div>
    </div>

    <div class="grid grid-4">
      <div class="card stat"><div class="label">Points</div><div class="value"><?= number_format((int) $c['points']) ?></div>
        <div class="small muted"><?= $nextTier ? h($nextTier[1]) . ' more for ' . h($nextTier[0]) : 'Top tier' ?></div></div>
      <div class="card stat"><div class="label">Orders</div><div class="value"><?= (int) $c['orders_count'] ?></div></div>
      <div class="card stat"><div class="label">Total spent</div><div class="value"><?= peso($c['total_spent']) ?></div></div>
      <div class="card stat"><div class="label">Last order</div><div class="value" style="font-size:18px;"><?= $c['last_order_at'] ? h(date('M j, Y', strtotime($c['last_order_at']))) : '—' ?></div>
        <div class="small muted">Customer since <?= h(date('M j, Y', strtotime($c['first_order_at'] ?: $c['created_at']))) ?></div></div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <h2>Timeline</h2>
        <?php if (!$timeline): ?><p class="muted">Nothing yet.</p><?php else: ?>
        <ul class="timeline">
          <?php foreach ($timeline as $i): ?>
            <li>
              <div>
                <?php if ($i['type'] === 'order_completed'): ?>
                  <strong>Order <?= h($i['ref']) ?></strong> · <?= peso($i['amount']) ?><div class="small muted"><?= h($i['note']) ?></div>
                <?php elseif ($i['type'] === 'points_adjusted'): ?>
                  <strong>Points adjusted</strong><div class="small muted"><?= h($i['note']) ?></div>
                <?php else: ?>
                  <strong>Ticket <a href="tickets.php?no=<?= h(urlencode($i['ref'])) ?>"><?= h($i['ref']) ?></a></strong><div class="small muted"><?= h($i['note']) ?></div>
                <?php endif; ?>
                <div class="small muted"><?= h(date('M j, Y g:i A', strtotime($i['created_at']))) ?></div>
              </div>
              <div class="nowrap">
                <?php if ((int) $i['points'] > 0): ?><span class="pts-plus">+<?= (int) $i['points'] ?> pts</span>
                <?php elseif ((int) $i['points'] < 0): ?><span class="pts-minus"><?= (int) $i['points'] ?> pts</span><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

      <div>
        <div class="card">
          <h2>Adjust points</h2>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="form-row">
              <div><label for="dir">Action</label>
                <select id="dir" name="direction"><option value="add">Add points</option><option value="remove">Remove points</option></select></div>
              <div><label for="pts">Points</label><input type="number" id="pts" name="points" min="1" max="100000" step="1" required></div>
            </div>
            <div class="field" style="margin-top:12px;"><label for="reason">Reason (shown on the timeline)</label>
              <input type="text" id="reason" name="reason" required minlength="3" maxlength="200" placeholder="e.g. Sorry for the cold meal"></div>
            <button class="btn" type="submit">Save and notify Kantina</button>
            <p class="small muted" style="margin:10px 0 0;">The tier is recalculated, and Kantina receives a <code>customer.updated</code> webhook so the student's profile shows the new points.</p>
          </form>
        </div>

        <div class="card">
          <h2>Tickets</h2>
          <?php if (!$tickets): ?><p class="muted">No feedback from this customer.</p><?php else: ?>
          <table>
            <tr><th>Ticket</th><th>Category</th><th>Rating</th><th>Status</th></tr>
            <?php foreach ($tickets as $t): ?>
              <tr class="clickable" onclick="location.href='tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>'">
                <td><a href="tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>"><?= h($t['ticket_no']) ?></a></td>
                <td><?= h(Tickets::LABELS[$t['category']]) ?></td>
                <td><?= stars($t['rating'] === null ? null : (int) $t['rating']) ?></td>
                <td><?= status_badge($t['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ---- List view ----
$q = trim((string) ($_GET['q'] ?? ''));
$customers = Customers::list($q);

layout_start('Customers', 'customers');
?>
<div class="page-head">
  <div>
    <h1>Customers</h1>
    <div class="muted">Kantina students, their orders and loyalty points. Customers are added automatically when Kantina sends their first order or feedback.</div>
  </div>
  <form method="get" class="filters">
    <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name, student ID or email"></div>
    <button class="btn secondary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn secondary" href="customers.php">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card table-wrap">
  <?php if (!$customers): ?><p class="muted"><?= $q !== '' ? 'No customers match "' . h($q) . '".' : 'No customers yet.' ?></p><?php else: ?>
  <table>
    <tr><th>Customer</th><th>Student ID</th><th class="num">Orders</th><th class="num">Total spent</th><th class="num">Points</th><th>Tier</th><th>Last order</th></tr>
    <?php foreach ($customers as $c): ?>
      <tr class="clickable" onclick="location.href='customers.php?id=<?= (int) $c['id'] ?>'">
        <td><a href="customers.php?id=<?= (int) $c['id'] ?>"><?= h($c['name']) ?></a><div class="small muted"><?= h($c['email']) ?></div></td>
        <td><?= h($c['student_id']) ?></td>
        <td class="num"><?= (int) $c['orders_count'] ?></td>
        <td class="num"><?= peso($c['total_spent']) ?></td>
        <td class="num"><?= number_format((int) $c['points']) ?></td>
        <td><?= tier_badge($c['tier']) ?></td>
        <td class="muted"><?= $c['last_order_at'] ? h(date('M j, Y', strtotime($c['last_order_at']))) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php
layout_end();

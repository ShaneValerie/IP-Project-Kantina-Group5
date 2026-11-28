<?php
require __DIR__ . '/_init.php';
require_login();

// ---- Reply / change status (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $no = (string) ($_POST['ticket_no'] ?? '');
    if (($_POST['action'] ?? '') === 'update') {
        try {
            [$t, $notified] = Tickets::update($no, [
                'status' => $_POST['status'] ?? '',
                'reply' => $_POST['reply'] ?? '',
            ]);
            flash_notified("Saved $no (" . Tickets::LABELS[$t['status']] . ')', $notified);
        } catch (HttpError $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('tickets.php?no=' . urlencode($no));
}

// ---- Detail view ----
if (!empty($_GET['no'])) {
    try {
        $t = Tickets::get((string) $_GET['no']);
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
        redirect('tickets.php');
    }
    $customer = Customers::byId((int) $t['customer_id']);

    layout_start($t['ticket_no'], 'tickets');
    ?>
    <div class="page-head">
      <div>
        <div class="small"><a href="tickets.php">← All tickets</a></div>
        <h1><?= h($t['ticket_no']) ?> <?= status_badge($t['status']) ?></h1>
        <div class="muted"><?= h(Tickets::LABELS[$t['category']]) ?> · received <?= h(date('M j, Y g:i A', strtotime($t['created_at']))) ?> · Kantina ref <code><?= h($t['external_ref']) ?></code></div>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <h2>Feedback</h2>
        <p class="message"><?= h($t['message']) ?></p>
        <table>
          <tr><th>Customer</th><td><a href="customers.php?id=<?= (int) $customer['id'] ?>"><?= h($customer['name']) ?></a> <?= tier_badge($customer['tier']) ?><div class="small muted"><?= h($customer['student_id']) ?> · <?= number_format((int) $customer['points']) ?> points</div></td></tr>
          <tr><th>Rating</th><td><?= stars($t['rating'] === null ? null : (int) $t['rating']) ?></td></tr>
          <tr><th>Order</th><td><?= $t['order_ref'] ? '<code>' . h($t['order_ref']) . '</code>' : '<span class="muted">—</span>' ?></td></tr>
          <tr><th>Stall</th><td><?= $t['stall_id'] ? h($t['stall_id']) : '<span class="muted">—</span>' ?></td></tr>
          <?php if ($t['resolved_at']): ?><tr><th>Resolved</th><td><?= h(date('M j, Y g:i A', strtotime($t['resolved_at']))) ?></td></tr><?php endif; ?>
        </table>
      </div>

      <div class="card">
        <h2>Reply to the student</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="ticket_no" value="<?= h($t['ticket_no']) ?>">
          <div class="field"><label for="reply">Reply (the student sees this in Kantina)</label>
            <textarea id="reply" name="reply" maxlength="2000" rows="6"><?= h($t['reply']) ?></textarea></div>
          <div class="field"><label for="status">Status</label>
            <select id="status" name="status">
              <?php foreach (Tickets::STATUSES as $s): ?>
                <option value="<?= h($s) ?>"<?= $s === $t['status'] ? ' selected' : '' ?>><?= h(Tickets::LABELS[$s]) ?></option>
              <?php endforeach; ?>
            </select></div>
          <button class="btn" type="submit">Save and notify Kantina</button>
          <p class="small muted" style="margin:10px 0 0;">A reply is required to resolve a ticket. Saving sends a <code>ticket.updated</code> webhook to Kantina.</p>
        </form>
        <p class="small muted" style="margin:14px 0 0;">Want to make it up to them? <a href="customers.php?id=<?= (int) $customer['id'] ?>">Add points on their profile</a>.</p>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ---- List view ----
$status = isset($_GET['status']) && in_array($_GET['status'], Tickets::STATUSES, true) ? $_GET['status'] : null;
$tickets = Tickets::list($status);
$counts = Reports::summary()['tickets'];

layout_start('Tickets', 'tickets');
?>
<div class="page-head">
  <div>
    <h1>Feedback tickets</h1>
    <div class="muted">Feedback students send from Kantina. Reply and resolve them here; the reply is sent back to Kantina.</div>
  </div>
</div>

<div class="tabs">
  <a href="tickets.php"<?= $status ? '' : ' class="active"' ?>>All (<?= array_sum($counts) ?>)</a>
  <?php foreach (Tickets::STATUSES as $s): ?>
    <a href="tickets.php?status=<?= h($s) ?>"<?= $status === $s ? ' class="active"' : '' ?>><?= h(Tickets::LABELS[$s]) ?> (<?= (int) $counts[$s] ?>)</a>
  <?php endforeach; ?>
</div>

<div class="card table-wrap">
  <?php if (!$tickets): ?><p class="muted">No tickets<?= $status ? ' with this status' : '' ?>.</p><?php else: ?>
  <table>
    <tr><th>Ticket</th><th>Customer</th><th>Category</th><th>Rating</th><th>Message</th><th>Status</th><th>Received</th></tr>
    <?php foreach ($tickets as $t): ?>
      <tr class="clickable" onclick="location.href='tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>'">
        <td><a href="tickets.php?no=<?= h(urlencode($t['ticket_no'])) ?>"><?= h($t['ticket_no']) ?></a></td>
        <td><?= h($t['customer_name']) ?><div class="small muted"><?= h($t['student_id']) ?></div></td>
        <td><?= h(Tickets::LABELS[$t['category']]) ?></td>
        <td><?= stars($t['rating'] === null ? null : (int) $t['rating']) ?></td>
        <td style="max-width:320px;" class="muted"><?= h(mb_strimwidth($t['message'], 0, 80, '…')) ?></td>
        <td><?= status_badge($t['status']) ?></td>
        <td class="muted nowrap"><?= h(date('M j, g:i A', strtotime($t['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php
layout_end();

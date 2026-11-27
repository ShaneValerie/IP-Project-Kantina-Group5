<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'resend' && !empty($_POST['id'])) {
        $ok = WebhookSender::deliver('erp_webhook_deliveries', (int) $_POST['id'], Events::url(), (string) Config::get('webhook_secret'));
        flash($ok ? 'ok' : 'error', $ok ? 'Delivered to Kantina.' : 'Kantina did not accept it — check that Kantina is online and the secrets match.');
    } elseif (($_POST['action'] ?? '') === 'resend_all') {
        [$ok, $bad] = Events::retryFailed(50);
        flash($bad ? 'error' : 'ok', "$ok delivered, $bad still failing.");
    }
    redirect('webhooks.php');
}

$rows = Db::all('SELECT * FROM erp_webhook_deliveries ORDER BY id DESC LIMIT 100');
$failed = (int) Db::value("SELECT COUNT(*) FROM erp_webhook_deliveries WHERE status <> 'delivered'");

layout_start('Webhooks', 'webhooks');
?>
<div class="page-head">
  <div>
    <h1>Webhooks to Kantina</h1>
    <div class="muted">The ERP tells Kantina when prices, stock or availability change. Each message is signed with HMAC-SHA256.</div>
    <div class="small muted">Endpoint: <code><?= h(Events::url()) ?></code></div>
  </div>
  <?php if ($failed): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend_all"><button class="btn" type="submit">Resend <?= $failed ?> undelivered</button></form>
  <?php endif; ?>
</div>

<div class="card table-wrap">
  <?php if (!$rows): ?><p class="muted">No webhooks sent yet. Change a price or stock level to send one.</p><?php else: ?>
  <table>
    <tr><th>Event</th><th>Status</th><th class="num">Attempts</th><th>Created</th><th>Details</th><th></th></tr>
    <?php foreach ($rows as $w): ?>
      <tr>
        <td><code><?= h($w['event']) ?></code><div class="small muted"><?= h($w['event_id']) ?></div></td>
        <td>
          <?php if ($w['status'] === 'delivered'): ?><span class="badge ok">delivered</span>
          <?php else: ?><span class="badge bad"><?= h($w['status']) ?></span><?php endif; ?>
          <?php if ($w['response_code']): ?><span class="small muted">HTTP <?= (int) $w['response_code'] ?></span><?php endif; ?>
        </td>
        <td class="num"><?= (int) $w['attempts'] ?></td>
        <td class="muted"><?= h(date('M j, g:i:s A', strtotime($w['created_at']))) ?></td>
        <td style="max-width:420px;">
          <?php if ($w['last_error']): ?><div class="small" style="color:var(--bad);"><?= h($w['last_error']) ?></div><?php endif; ?>
          <details><summary class="small">Payload</summary><pre class="payload"><?= h(json_encode(json_decode($w['payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></details>
        </td>
        <td>
          <?php if ($w['status'] !== 'delivered'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button class="btn small secondary" type="submit">Resend</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php
layout_end();

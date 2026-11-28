<?php
require __DIR__ . '/_init.php';
require_login();

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

<div class="card">
  <h2>Getting started</h2>
  <p class="muted" style="margin-top:0;">This CRM is ready for its features: sign-in, API keys and signed webhooks to Kantina already work.</p>
  <ul class="muted" style="margin:0;padding-left:18px;">
    <li>API: <code><?= h(Config::url('crm')) ?>/api/v1</code> (send the <code>X-Api-Key</code> header)</li>
    <li>Webhooks to Kantina: <code><?= h(Events::url()) ?></code></li>
  </ul>
</div>

<?php if ($failed): ?>
  <div class="flash error"><?= $failed ?> webhook(s) to Kantina have not been delivered. <a href="webhooks.php">Review and resend</a></div>
<?php endif; ?>
<?php
layout_end();

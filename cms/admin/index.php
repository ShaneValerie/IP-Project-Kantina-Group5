<?php
require __DIR__ . '/_init.php';
require_login();

$counts = [
    'stalls' => (int) Db::value('SELECT COUNT(*) FROM cms_stalls WHERE is_published = 1'),
    'entries' => (int) Db::value("SELECT COUNT(*) FROM cms_menu_entries WHERE is_published = 1 AND type = 'menu'"),
    'drafts' => (int) Db::value('SELECT COUNT(*) FROM cms_menu_entries WHERE is_published = 0'),
    'announcements' => count(Content::announcements()),
];
$recent = Db::all(
    "SELECT 'Menu' AS kind, sku AS id, name AS title, updated_at, CONCAT('menu.php?sku=', sku) AS link FROM cms_menu_entries
     UNION ALL SELECT 'Stall', id, name, updated_at, CONCAT('stalls.php?id=', id) FROM cms_stalls
     UNION ALL SELECT 'Announcement', id, title, updated_at, CONCAT('announcements.php?id=', id) FROM cms_announcements
     ORDER BY updated_at DESC LIMIT 8"
);
$failed = (int) Db::value("SELECT COUNT(*) FROM cms_webhook_deliveries WHERE status <> 'delivered'");
$kantina = HttpClient::request('GET', Config::url('kantina') . '/api/health', null, [], 3);

layout_start('Dashboard', 'index');
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <div class="muted">Everything students read in the Kantina app comes from here.</div>
  </div>
  <div>
    Kantina connection:
    <?php if ($kantina['ok']): ?><span class="badge ok">online</span>
    <?php else: ?><span class="badge bad" title="<?= h($kantina['error']) ?>">offline</span><?php endif; ?>
  </div>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="label">Published stalls</div><div class="value"><?= $counts['stalls'] ?></div></div>
  <div class="card stat"><div class="label">Published menu items</div><div class="value"><?= $counts['entries'] ?></div></div>
  <div class="card stat"><div class="label">Drafts</div><div class="value"><?= $counts['drafts'] ?></div></div>
  <div class="card stat"><div class="label">Live announcements</div><div class="value"><?= $counts['announcements'] ?></div></div>
</div>

<div class="card">
  <h2>Recently edited</h2>
  <table>
    <tr><th>Type</th><th>Content</th><th>Last edited</th></tr>
    <?php foreach ($recent as $row): ?>
      <tr>
        <td><span class="badge gray"><?= h($row['kind']) ?></span></td>
        <td><a href="<?= h($row['link']) ?>"><?= h($row['title']) ?></a></td>
        <td class="muted"><?= h(date('M j, g:i A', strtotime($row['updated_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>How publishing works</h2>
  <ol class="muted" style="margin:0;padding-left:18px;">
    <li>Edit a stall, menu item or announcement and press <strong>Save &amp; publish</strong>.</li>
    <li>The CMS sends a signed <code>content.published</code> webhook to Kantina.</li>
    <li>Kantina fetches the new version from the CMS API and updates the student app right away.</li>
  </ol>
  <p class="small muted" style="margin-bottom:0;">Prices and stock are not edited here — they live in Kantina ERP.</p>
</div>

<?php if ($failed): ?>
  <div class="flash error"><?= $failed ?> webhook(s) to Kantina have not been delivered. <a href="webhooks.php">Review and resend</a></div>
<?php endif; ?>
<?php
layout_end();

<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete' && $id) {
        Db::exec('DELETE FROM cms_announcements WHERE id = ?', [$id]);
        publish('announcement', (string) $id, 'Announcement', 'deleted');
        redirect('announcements.php');
    }
    try {
        $a = Content::saveAnnouncement($id ?: null, [
            'title' => $_POST['title'] ?? '',
            'body' => $_POST['body'] ?? '',
            'image' => $_POST['image'] ?? '',
            'link_url' => $_POST['link_url'] ?? '',
            'starts_at' => $_POST['starts_at'] ?? '',
            'ends_at' => $_POST['ends_at'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'is_published' => isset($_POST['is_published']),
        ]);
        publish('announcement', (string) $a['id'], 'Announcement');
        redirect('announcements.php?id=' . $a['id']);
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
        redirect('announcements.php' . ($id ? '?id=' . $id : '?new=1'));
    }
}

$all = Content::announcements(true);
$editing = null;
if (!empty($_GET['id'])) {
    $editing = Db::one('SELECT * FROM cms_announcements WHERE id = ?', [(int) $_GET['id']]);
} elseif (!empty($_GET['new'])) {
    $editing = ['id' => 0, 'title' => '', 'body' => '', 'image' => null, 'link_url' => '', 'starts_at' => null, 'ends_at' => null, 'sort_order' => 0, 'is_published' => 1];
}
$dt = fn(?string $v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';

layout_start('Announcements', 'announcements');
?>
<div class="page-head">
  <div>
    <h1>Announcements</h1>
    <div class="muted">Banners on the student home page (promos, schedule changes, reminders).</div>
  </div>
  <a class="btn" href="announcements.php?new=1">+ New announcement</a>
</div>

<?php if ($editing): $a = $editing; ?>
<form class="card" method="post">
  <h2><?= $a['id'] ? 'Edit announcement' : 'New announcement' ?></h2>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
  <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" value="<?= h($a['title']) ?>" required maxlength="120"></div>
  <div class="field"><label for="body">Text</label><textarea id="body" name="body" maxlength="2000"><?= h($a['body']) ?></textarea></div>
  <div class="form-row">
    <div class="field"><label for="image">Image</label><select id="image" name="image"><?= media_options($a['image']) ?></select></div>
    <div class="field"><label for="link_url">Link (page in the app)</label><input type="text" id="link_url" name="link_url" value="<?= h($a['link_url']) ?>" placeholder="reserve.html" maxlength="255"></div>
    <div class="field"><label for="starts_at">Show from</label><input type="datetime-local" id="starts_at" name="starts_at" value="<?= h($dt($a['starts_at'])) ?>"></div>
    <div class="field"><label for="ends_at">Show until</label><input type="datetime-local" id="ends_at" name="ends_at" value="<?= h($dt($a['ends_at'])) ?>"></div>
    <div class="field"><label for="sort_order">Order</label><input type="number" id="sort_order" name="sort_order" value="<?= (int) $a['sort_order'] ?>"></div>
  </div>
  <div class="field"><label class="check"><input type="checkbox" name="is_published"<?= (int) $a['is_published'] ? ' checked' : '' ?>> Published</label></div>
  <button class="btn" type="submit">Save &amp; publish</button>
  <a class="btn secondary" href="announcements.php">Cancel</a>
</form>
<?php if ($a['id']): ?>
  <form method="post" onsubmit="return confirm('Delete this announcement?');" style="margin:-6px 0 18px;">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="delete">
    <button class="btn small danger" type="submit">Delete announcement</button>
  </form>
<?php endif; ?>
<?php endif; ?>

<div class="card table-wrap">
  <?php if (!$all): ?><p class="muted">No announcements yet.</p><?php else: ?>
  <table>
    <tr><th>Title</th><th>Showing</th><th>Status</th><th></th></tr>
    <?php foreach ($all as $a):
        $live = (int) $a['is_published'] && (!$a['starts_at'] || strtotime($a['starts_at']) <= time()) && (!$a['ends_at'] || strtotime($a['ends_at']) >= time()); ?>
      <tr>
        <td><strong><?= h($a['title']) ?></strong><div class="small muted" style="max-width:480px;"><?= h($a['body']) ?></div></td>
        <td class="small muted"><?= $a['starts_at'] ? h(date('M j g:i A', strtotime($a['starts_at']))) : 'now' ?> → <?= $a['ends_at'] ? h(date('M j g:i A', strtotime($a['ends_at']))) : 'no end' ?></td>
        <td><?= $live ? '<span class="badge ok">live</span>' : ((int) $a['is_published'] ? '<span class="badge warn">scheduled / ended</span>' : '<span class="badge gray">draft</span>') ?></td>
        <td><a class="btn small secondary" href="announcements.php?id=<?= (int) $a['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php
layout_end();

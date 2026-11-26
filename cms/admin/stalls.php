<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (string) ($_POST['id'] ?? '');
    try {
        $stall = Content::saveStall($id, [
            'name' => $_POST['name'] ?? '',
            'category' => $_POST['category'] ?? '',
            'description' => $_POST['description'] ?? '',
            'photo' => $_POST['photo'] ?? '',
            'counter_label' => $_POST['counter_label'] ?? '',
            'prep_minutes' => $_POST['prep_minutes'] ?? '',
            'opens_at' => $_POST['opens_at'] ?? '',
            'closes_at' => $_POST['closes_at'] ?? '',
            'temporarily_closed' => isset($_POST['temporarily_closed']),
            'closed_note' => $_POST['closed_note'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'is_published' => isset($_POST['is_published']),
        ]);
        publish('stall', $id, $stall['name']);
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
    }
    redirect('stalls.php?id=' . urlencode($id));
}

$stalls = Content::stalls(true);
$editing = !empty($_GET['id']) ? Content::stall($_GET['id']) : null;

layout_start('Stalls', 'stalls');
?>
<div class="page-head">
  <div>
    <h1>Stalls</h1>
    <div class="muted">Name, photo, opening hours and pickup counter shown to students.</div>
  </div>
</div>

<?php if ($editing): $s = $editing; ?>
<form class="card" method="post">
  <h2>Edit <?= h($s['name']) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= h($s['id']) ?>">
  <div class="form-row">
    <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" value="<?= h($s['name']) ?>" required maxlength="80"></div>
    <div class="field"><label for="category">Category</label><input type="text" id="category" name="category" value="<?= h($s['category']) ?>" required maxlength="40" list="cats"></div>
    <div class="field"><label for="counter_label">Pickup counter</label><input type="text" id="counter_label" name="counter_label" value="<?= h($s['counter_label']) ?>" required maxlength="40"></div>
    <div class="field"><label for="prep_minutes">Usual prep time (min)</label><input type="number" id="prep_minutes" name="prep_minutes" value="<?= (int) $s['prep_minutes'] ?>" min="1" max="120" required></div>
  </div>
  <datalist id="cats"><option>Rice meals</option><option>Drinks</option><option>Noodles</option><option>Snacks</option></datalist>
  <div class="field"><label for="description">Description</label><textarea id="description" name="description" maxlength="2000"><?= h($s['description']) ?></textarea></div>
  <div class="form-row">
    <div class="field"><label for="photo">Photo</label><select id="photo" name="photo"><?= media_options($s['photo']) ?></select></div>
    <div class="field"><label for="opens_at">Opens</label><input type="time" id="opens_at" name="opens_at" value="<?= h(hm($s['opens_at'])) ?>" required></div>
    <div class="field"><label for="closes_at">Closes</label><input type="time" id="closes_at" name="closes_at" value="<?= h(hm($s['closes_at'])) ?>" required></div>
    <div class="field"><label for="sort_order">Order on home page</label><input type="number" id="sort_order" name="sort_order" value="<?= (int) $s['sort_order'] ?>"></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="check"><input type="checkbox" name="temporarily_closed"<?= (int) $s['temporarily_closed'] ? ' checked' : '' ?>> Temporarily closed (no orders)</label></div>
    <div class="field"><label for="closed_note">Closed note shown to students</label><input type="text" id="closed_note" name="closed_note" value="<?= h($s['closed_note']) ?>" maxlength="80" placeholder="Closed today — restocking"></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_published"<?= (int) $s['is_published'] ? ' checked' : '' ?>> Published</label></div>
  </div>
  <?php if ($s['photo']): ?><p><img src="<?= h(Content::mediaUrl($s['photo'])) ?>" alt="" style="max-width:260px;border-radius:10px;"></p><?php endif; ?>
  <button class="btn" type="submit">Save &amp; publish</button>
  <a class="btn secondary" href="stalls.php">Cancel</a>
</form>
<?php endif; ?>

<div class="card table-wrap">
  <table>
    <tr><th></th><th>Stall</th><th>Category</th><th>Hours</th><th>Counter</th><th>Status</th><th></th></tr>
    <?php foreach ($stalls as $s): ?>
      <tr>
        <td style="width:64px;"><?php if ($s['photo']): ?><img src="<?= h(Content::mediaUrl($s['photo'])) ?>" alt="" style="width:56px;height:40px;object-fit:cover;border-radius:6px;"><?php endif; ?></td>
        <td><strong><?= h($s['name']) ?></strong><div class="small muted"><?= h($s['id']) ?></div></td>
        <td><?= h($s['category']) ?></td>
        <td><?= h(date('g:i A', strtotime($s['opens_at']))) ?> – <?= h(date('g:i A', strtotime($s['closes_at']))) ?></td>
        <td><?= h($s['counter_label']) ?></td>
        <td>
          <?= (int) $s['is_published'] ? '<span class="badge ok">published</span>' : '<span class="badge gray">draft</span>' ?>
          <?= (int) $s['temporarily_closed'] ? '<span class="badge warn">closed</span>' : '' ?>
        </td>
        <td><a class="btn small secondary" href="stalls.php?id=<?= h($s['id']) ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php
layout_end();

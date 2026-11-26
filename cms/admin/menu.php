<?php
require __DIR__ . '/_init.php';
require_login();
$names = stall_names();
$stall = isset($_GET['stall'], $names[$_GET['stall']]) ? $_GET['stall'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $isNew = ($_POST['mode'] ?? '') === 'new';
    $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
    $input = [
        'stall_id' => $_POST['stall_id'] ?? null,
        'type' => $_POST['type'] ?? 'menu',
        'section' => $_POST['section'] ?? '',
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'photo' => $_POST['photo'] ?? '',
        'addon_skus' => $_POST['addon_skus'] ?? [],
        'sort_order' => $_POST['sort_order'] ?? 0,
        'is_published' => isset($_POST['is_published']),
    ];
    if ($isNew) {
        $input['sku'] = $sku;
    }
    try {
        $entry = Content::saveEntry($isNew ? null : $sku, $input, $isNew);
        publish('menu_item', $entry['sku'], $entry['name']);
        redirect('menu.php?sku=' . urlencode($entry['sku']));
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
        redirect($isNew ? 'menu.php?new=1' : 'menu.php?sku=' . urlencode($sku));
    }
}

$editing = !empty($_GET['sku']) ? Content::entry($_GET['sku']) : null;
$creating = !empty($_GET['new']);
$entries = Content::entries($stall, true);

layout_start('Menu content', 'menu');

/** The add/edit form. */
function entry_form(?array $e, array $names, ?string $stall): void
{
    $isNew = $e === null;
    $e = $e ?? ['sku' => '', 'stall_id' => $stall ?? array_key_first($names), 'type' => 'menu', 'section' => '', 'name' => '', 'description' => '', 'photo' => null, 'addon_skus' => '', 'sort_order' => 0, 'is_published' => 1];
    $chosen = array_filter(array_map('trim', explode(',', (string) $e['addon_skus'])));
    $addons = Db::all("SELECT sku, name FROM cms_menu_entries WHERE type = 'addon' AND stall_id = ? ORDER BY sort_order", [$e['stall_id']]);
    ?>
    <form class="card" method="post">
      <h2><?= $isNew ? 'New menu item' : 'Edit ' . h($e['name']) ?></h2>
      <?= csrf_field() ?><input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">
      <div class="form-row">
        <div class="field"><label for="sku">SKU (same as in Kantina ERP)</label>
          <input type="text" id="sku" name="sku" value="<?= h($e['sku']) ?>" <?= $isNew ? 'required pattern="[A-Z0-9-]+" placeholder="LS-TOCILOG"' : 'readonly' ?>></div>
        <div class="field"><label for="stall_id">Stall</label>
          <select id="stall_id" name="stall_id"><?php foreach ($names as $id => $n): ?><option value="<?= h($id) ?>"<?= $id === $e['stall_id'] ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="type">Type</label>
          <select id="type" name="type"><option value="menu">Menu item</option><option value="addon"<?= $e['type'] === 'addon' ? ' selected' : '' ?>>Add-on</option></select></div>
        <div class="field"><label for="section">Menu section</label><input type="text" id="section" name="section" value="<?= h($e['section']) ?>" maxlength="60" placeholder="Silog"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" value="<?= h($e['name']) ?>" required maxlength="80"></div>
        <div class="field"><label for="photo">Photo</label><select id="photo" name="photo"><?= media_options($e['photo']) ?></select></div>
        <div class="field"><label for="sort_order">Order</label><input type="number" id="sort_order" name="sort_order" value="<?= (int) $e['sort_order'] ?>"></div>
      </div>
      <div class="field"><label for="description">Description</label><textarea id="description" name="description" maxlength="2000"><?= h($e['description']) ?></textarea></div>
      <?php if ($addons && $e['type'] === 'menu'): ?>
        <div class="field"><label>Add-ons offered with this item</label>
          <?php foreach ($addons as $a): ?>
            <label class="check"><input type="checkbox" name="addon_skus[]" value="<?= h($a['sku']) ?>"<?= in_array($a['sku'], $chosen, true) ? ' checked' : '' ?>> <?= h($a['name']) ?> <span class="small muted"><?= h($a['sku']) ?></span></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="field"><label class="check"><input type="checkbox" name="is_published"<?= (int) $e['is_published'] ? ' checked' : '' ?>> Published (visible to students)</label></div>
      <?php if ($e['photo']): ?><p><img src="<?= h(Content::mediaUrl($e['photo'])) ?>" alt="" style="max-width:220px;border-radius:10px;"></p><?php endif; ?>
      <button class="btn" type="submit">Save &amp; publish</button>
      <a class="btn secondary" href="menu.php">Cancel</a>
    </form>
    <?php
}
?>
<div class="page-head">
  <div>
    <h1>Menu content</h1>
    <div class="muted">Names, descriptions, photos, sections and add-ons. Prices and stock are managed in Kantina ERP.</div>
  </div>
  <a class="btn" href="menu.php?new=1<?= $stall ? '&stall=' . h($stall) : '' ?>">+ New menu item</a>
</div>

<?php if ($editing) { entry_form($editing, $names, $stall); } elseif ($creating) { entry_form(null, $names, $stall); } ?>

<div class="tabs">
  <a href="menu.php"<?= $stall ? '' : ' class="active"' ?>>All stalls</a>
  <?php foreach ($names as $id => $n): ?><a href="menu.php?stall=<?= h($id) ?>"<?= $stall === $id ? ' class="active"' : '' ?>><?= h($n) ?></a><?php endforeach; ?>
</div>

<div class="card table-wrap">
  <table>
    <tr><th></th><th>Item</th><th>SKU</th><?php if (!$stall): ?><th>Stall</th><?php endif; ?><th>Section</th><th>Status</th><th></th></tr>
    <?php foreach ($entries as $e): ?>
      <tr>
        <td style="width:56px;"><?php if ($e['photo']): ?><img src="<?= h(Content::mediaUrl($e['photo'])) ?>" alt="" style="width:48px;height:36px;object-fit:cover;border-radius:6px;"><?php endif; ?></td>
        <td><strong><?= h($e['name']) ?></strong><?= $e['type'] === 'addon' ? ' <span class="badge gray">add-on</span>' : '' ?><div class="small muted" style="max-width:380px;"><?= h($e['description']) ?></div></td>
        <td class="small nowrap"><?= h($e['sku']) ?></td>
        <?php if (!$stall): ?><td class="muted"><?= h($names[$e['stall_id']] ?? $e['stall_id']) ?></td><?php endif; ?>
        <td><?= h($e['section']) ?></td>
        <td><?= (int) $e['is_published'] ? '<span class="badge ok">published</span>' : '<span class="badge gray">draft</span>' ?></td>
        <td><a class="btn small secondary" href="menu.php?sku=<?= h($e['sku']) ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php
layout_end();

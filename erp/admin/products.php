<?php
require __DIR__ . '/_init.php';
$admin = require_login();
$actor = 'admin:' . $admin['username'];
$names = stall_names();
$stall = isset($_GET['stall'], $names[$_GET['stall']]) ? $_GET['stall'] : null;
$back = 'products.php' . ($stall ? '?stall=' . urlencode($stall) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $sku = (string) ($_POST['sku'] ?? '');
    if ($action === 'update') {
        attempt(fn() => Inventory::update($sku, [
            'price' => $_POST['price'] ?? null,
            'reorder_level' => $_POST['reorder_level'] ?? null,
            'is_active' => isset($_POST['is_active']),
        ], $actor), "Saved $sku. Kantina was notified.");
    } elseif ($action === 'restock') {
        attempt(fn() => Inventory::adjust([
            'sku' => $sku,
            'qty_change' => $_POST['qty_change'] ?? 0,
            'note' => $_POST['note'] ?? null,
        ], $actor), "Stock updated for $sku.");
    } elseif ($action === 'create') {
        attempt(fn() => Inventory::create([
            'stall_code' => $_POST['stall_code'] ?? '',
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? 'menu',
            'price' => $_POST['price'] ?? '',
            'stock' => $_POST['stock'] ?? 0,
            'track_stock' => isset($_POST['track_stock']),
            'reorder_level' => $_POST['reorder_level'] ?? 5,
        ], $actor), 'Product added. Add its description and photo in the CMS so students can see it.');
    }
    redirect($back . (isset($_POST['sku']) ? '#' . rawurlencode($sku) : ''));
}

$products = Inventory::list(['stall' => $stall]);
$historySku = $_GET['history'] ?? null;
$history = $historySku ? Inventory::movements($historySku, 30) : [];

layout_start('Products & stock', 'products');
?>
<div class="page-head">
  <div>
    <h1>Products &amp; stock</h1>
    <div class="muted">Prices, stock and availability here are what students see in Kantina.</div>
  </div>
</div>

<div class="tabs">
  <a href="products.php"<?= $stall ? '' : ' class="active"' ?>>All stalls</a>
  <?php foreach ($names as $code => $name): ?>
    <a href="products.php?stall=<?= h($code) ?>"<?= $stall === $code ? ' class="active"' : '' ?>><?= h($name) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <details>
    <summary>Add a product</summary>
    <form method="post" style="margin-top:14px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="form-row">
        <div><label for="n-stall">Stall</label>
          <select id="n-stall" name="stall_code" required>
            <?php foreach ($names as $code => $name): ?><option value="<?= h($code) ?>"<?= $stall === $code ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="n-name">Name</label><input type="text" id="n-name" name="name" required maxlength="80"></div>
        <div><label for="n-type">Type</label>
          <select id="n-type" name="type"><option value="menu">Menu item</option><option value="addon">Add-on</option></select></div>
        <div><label for="n-price">Price (₱)</label><input type="number" id="n-price" name="price" min="0" step="0.01" required></div>
        <div><label for="n-stock">Opening stock</label><input type="number" id="n-stock" name="stock" min="0" value="0"></div>
        <div><label for="n-reorder">Reorder at</label><input type="number" id="n-reorder" name="reorder_level" min="0" value="5"></div>
        <div><label class="check"><input type="checkbox" name="track_stock" checked> Track stock</label></div>
        <div><button class="btn" type="submit">Add product</button></div>
      </div>
    </form>
  </details>
</div>

<div class="card table-wrap">
  <table>
    <tr>
      <th>SKU</th><th>Item</th><?php if (!$stall): ?><th>Stall</th><?php endif; ?>
      <th class="num">Price (₱)</th><th class="num">Stock</th><th class="num">Reorder at</th><th>For sale</th><th></th><th>Restock / adjust</th>
    </tr>
    <?php foreach ($products as $p):
        $track = (int) $p['track_stock'];
        $stock = (int) $p['stock'];
        $form = 'f-' . $p['sku'];
        $rform = 'r-' . $p['sku'];
    ?>
      <tr id="<?= h($p['sku']) ?>">
        <td class="small nowrap"><a href="?<?= h(http_build_query(array_filter(['stall' => $stall, 'history' => $p['sku']]))) ?>#history"><?= h($p['sku']) ?></a></td>
        <td><?= h($p['name']) ?><?= $p['type'] === 'addon' ? ' <span class="badge gray">add-on</span>' : '' ?></td>
        <?php if (!$stall): ?><td class="muted"><?= h($names[$p['stall_code']] ?? $p['stall_code']) ?></td><?php endif; ?>
        <td class="num"><input class="inline" form="<?= h($form) ?>" type="number" name="price" min="0" step="0.01" value="<?= h(money($p['price'])) ?>" aria-label="Price of <?= h($p['name']) ?>"></td>
        <td class="num">
          <?php if (!$track): ?><span class="badge gray" title="Made to order">n/a</span>
          <?php elseif ($stock <= 0): ?><span class="badge bad">0 · sold out</span>
          <?php elseif ($stock <= (int) $p['reorder_level']): ?><span class="badge warn"><?= $stock ?> · low</span>
          <?php else: ?><span class="badge ok"><?= $stock ?></span><?php endif; ?>
        </td>
        <td class="num"><input class="inline" form="<?= h($form) ?>" type="number" name="reorder_level" min="0" value="<?= (int) $p['reorder_level'] ?>" aria-label="Reorder level of <?= h($p['name']) ?>"></td>
        <td><label class="check"><input form="<?= h($form) ?>" type="checkbox" name="is_active"<?= (int) $p['is_active'] ? ' checked' : '' ?>> <span class="small">active</span></label></td>
        <td>
          <form id="<?= h($form) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="sku" value="<?= h($p['sku']) ?>"></form>
          <button class="btn small" form="<?= h($form) ?>" type="submit">Save</button>
        </td>
        <td>
          <?php if ($track): ?>
            <form id="<?= h($rform) ?>" method="post" style="display:flex;gap:6px;align-items:center;">
              <?= csrf_field() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="sku" value="<?= h($p['sku']) ?>">
              <input class="inline" type="number" name="qty_change" placeholder="+10" required aria-label="Quantity to add for <?= h($p['name']) ?>">
              <input type="hidden" name="note" value="Restock from ERP admin">
              <button class="btn small secondary" type="submit">Apply</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="small muted">Positive numbers add stock (restock), negative numbers remove it (spoilage, count corrections). Every change is recorded as a stock movement and pushed to Kantina by webhook.</p>
</div>

<?php if ($historySku): ?>
<div class="card" id="history">
  <h2>Stock movements · <?= h($historySku) ?></h2>
  <?php if (!$history): ?><p class="muted">No movements recorded (stock is not tracked for this item).</p><?php else: ?>
  <table>
    <tr><th>When</th><th>Reason</th><th class="num">Change</th><th class="num">Balance</th><th>Reference</th><th>By</th><th>Note</th></tr>
    <?php foreach ($history as $m): ?>
      <tr>
        <td class="muted"><?= h(date('M j, g:i A', strtotime($m['created_at']))) ?></td>
        <td><?= h(str_replace('_', ' ', $m['reason'])) ?></td>
        <td class="num"><?= (int) $m['qty_change'] > 0 ? '+' : '' ?><?= (int) $m['qty_change'] ?></td>
        <td class="num"><?= (int) $m['balance_after'] ?></td>
        <td><?= h($m['reference']) ?></td>
        <td class="small"><?= h($m['created_by']) ?></td>
        <td class="small muted"><?= h($m['note']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
layout_end();

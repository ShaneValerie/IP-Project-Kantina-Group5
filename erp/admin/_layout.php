<?php
/** Page frame for the ERP admin pages. */

function layout_start(string $title, string $active): void
{
    $admin = $_SESSION['admin'] ?? null;
    $nav = [
        'index' => ['index.php', 'Dashboard'],
        'products' => ['products.php', 'Products & stock'],
        'sales' => ['sales.php', 'Sales ledger'],
        'reports' => ['reports.php', 'Reports'],
        'webhooks' => ['webhooks.php', 'Webhooks to Kantina'],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> · Kantina ERP</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="shell">
  <nav class="side" aria-label="ERP">
    <div class="brand">Kantina ERP<small>Inventory &amp; sales ledger</small></div>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= $href ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
    <div class="spacer"></div>
    <?php if ($admin): ?>
      <span class="small" style="padding:0 10px;opacity:.8;">Signed in as <?= h($admin['name']) ?></span>
      <a href="logout.php">Sign out</a>
    <?php endif; ?>
  </nav>
  <main class="main">
    <?php if ($f = flash()): ?>
      <div class="flash <?= $f[0] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($f[1]) ?></div>
    <?php endif; ?>
<?php
}

function layout_end(): void
{
    ?>
  </main>
</div>
</body>
</html>
<?php
}

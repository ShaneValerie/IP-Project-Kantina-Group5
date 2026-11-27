<?php
require __DIR__ . '/_init.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = Db::one('SELECT * FROM cms_admins WHERE username = ?', [trim($_POST['username'] ?? '')]);
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => (int) $user['id'], 'username' => $user['username'], 'name' => $user['name']];
        redirect('index.php');
    }
    $error = 'Wrong username or password.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · Kantina CMS</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="login">
  <form class="card" method="post">
    <div class="brand-big">Kantina CMS</div>
    <p class="muted" style="margin-top:0;">Stall profiles, menus, photos and announcements.</p>
    <?php if ($error): ?><div class="flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autocomplete="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn" type="submit" style="width:100%;justify-content:center;">Sign in</button>
    <p class="small muted" style="margin-bottom:0;">Demo: admin / kantina123</p>
  </form>
</div>
</body>
</html>

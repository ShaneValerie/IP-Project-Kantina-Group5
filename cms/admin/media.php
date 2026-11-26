<?php
require __DIR__ . '/_init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $path = Content::upload($_FILES['image'] ?? []);
        flash('ok', "Uploaded $path — choose it in a stall, menu item or announcement.");
    } catch (HttpError $e) {
        flash('error', $e->getMessage());
    }
    redirect('media.php');
}

$files = Content::mediaFiles();
layout_start('Media library', 'media');
?>
<div class="page-head">
  <div>
    <h1>Media library</h1>
    <div class="muted">Photos used by stalls, menu items and announcements. Served from the CMS at <code>/media/…</code>.</div>
  </div>
</div>

<form class="card filters" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="field" style="min-width:280px;"><label for="image">Upload an image (JPG, PNG, WEBP · max 2 MB)</label><input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required></div>
  <button class="btn" type="submit">Upload</button>
</form>

<div class="grid grid-4">
  <?php foreach ($files as $path): ?>
    <figure class="card" style="margin:0;padding:10px;">
      <img src="<?= h(Content::mediaUrl($path)) ?>" alt="" style="width:100%;height:130px;object-fit:cover;border-radius:8px;" loading="lazy">
      <figcaption class="small muted" style="margin-top:6px;word-break:break-all;"><?= h($path) ?></figcaption>
    </figure>
  <?php endforeach; ?>
</div>
<?php
layout_end();

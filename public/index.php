<?php
require __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'Welcome';
require APP_PATH . '/views/header.php';
?>
<section class="hero">
  <h1>Crockery for every <?=brand_ur()?></h1>
  <p>Browse, check dates and book crockery for weddings, daawats and parties.</p>
  <?php if (!Auth::user()): ?>
    <p><a class="btn" href="<?= e(url('register.php')) ?>">Create account</a>
       <a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a></p>
  <?php else: ?>
    <p><a class="btn" href="<?= e(url(Auth::homeUrl())) ?>">Go to dashboard</a></p>
  <?php endif; ?>
</section>
<?php require APP_PATH . '/views/footer.php'; ?>

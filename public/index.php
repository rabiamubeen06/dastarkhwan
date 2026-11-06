<?php
require __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'Welcome';
require APP_PATH . '/views/header.php';
?>
<section class="hero">
  <h1>Crockery for every <?= brand_ur() ?>.</h1>
  <p>Browse, check dates and book crockery for weddings, daawats and parties.</p>
  <div class="actions">
    <?php if (!Auth::user()): ?>
      <a class="btn" href="<?= e(url('register.php')) ?>">Create account</a>
      <a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a>
    <?php else: ?>
      <a class="btn" href="<?= e(url(Auth::homeUrl())) ?>">Go to dashboard</a>
    <?php endif; ?>
  </div>
</section>
<ol class="steps">
  <li><i>01</i><b>Check availability</b><span>Pick your event dates and see what is free.</span></li>
  <li><i>02</i><b>Build your package</b><span>Choose plates, dishes and glasses for your guest count.</span></li>
  <li><i>03</i><b>Request a booking</b><span>See the full cost up front and send your request.</span></li>
</ol>
<?php require APP_PATH . '/views/footer.php'; ?>
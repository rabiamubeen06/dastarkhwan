<?php
require __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'Welcome';
require APP_PATH . '/views/header.php';
?>
<section class="hero">
  <div>
    <h1>Everything for the table, ready for your event.</h1>
    <p>Browse crockery, check dates and get an instant quote for weddings, daawats and family gatherings.</p>
    <div class="actions">
      <a class="btn" href="<?= e(url('catalog.php')) ?>">Browse</a>
      <?php if (!Auth::user()): ?>
        <a class="btn ghost" href="<?= e(url('register.php')) ?>">Create account</a>
        <a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a>
      <?php else: ?>
        <a class="btn ghost" href="<?= e(url(Auth::homeUrl())) ?>">Go to dashboard</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-photo"><img src="https://images.pexels.com/photos/12876507/pexels-photo-12876507.jpeg?auto=compress&cs=tinysrgb&w=1200" alt="Beautifully set table with crockery for an event" loading="eager"></div>
</section>
<section class="steps">
  <div><h2>Choose your items</h2><p>Pick from plates, cutlery, serving dishes and more.</p></div>
  <div><h2>Check your dates</h2><p>See what is available for your event before you book.</p></div>
  <div><h2>Get your quote</h2><p>Rent, delivery and deposit, calculated instantly.</p></div>
</section>
<?php require APP_PATH . '/views/footer.php'; ?>

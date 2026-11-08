<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('customer');

$st = Database::pdo()->prepare(
    'SELECT booking_no, start_date, end_date, total_amount, status
     FROM bookings WHERE user_id = ? ORDER BY booking_id DESC LIMIT 10'
);
$st->execute([$user['id']]);
$bookings = $st->fetchAll();

$pageTitle = 'My dashboard';
require APP_PATH . '/views/header.php';
?>
<section class="welcome">
  <div class="welcome-text">
    <span class="eyebrow">Customer dashboard</span>
    <h1>Welcome, <?= e($user['name']) ?></h1>
    <p>We are glad to have you with us. Pick your crockery, check your dates and get an instant quote for your event. Your bookings appear below.</p>
    <div class="actions">
      <a class="btn" href="<?= e(url('catalog.php')) ?>">Browse catalog</a>
      <a class="btn ghost" href="<?= e(url('packages.php')) ?>">View packages</a>
    </div>
  </div>
  <div class="welcome-photo"><img src="https://images.pexels.com/photos/28674660/pexels-photo-28674660.jpeg?auto=compress&cs=tinysrgb&w=1200" alt="Elegant table setting for a gathering" loading="lazy"></div>
</section>

<section class="guide">
  <h2>How to book in 5 simple steps</h2>
  <ol class="guide-steps">
    <li><span class="n">1</span><div><strong>Browse the catalog</strong><p>Find plates, cutlery and serving dishes, or pick a ready-made package.</p></div></li>
    <li><span class="n">2</span><div><strong>Add items to your cart</strong><p>Choose quantities and set your event dates.</p></div></li>
    <li><span class="n">3</span><div><strong>Check your quote</strong><p>See rent, delivery and deposit calculated instantly.</p></div></li>
    <li><span class="n">4</span><div><strong>Place your booking</strong><p>Check out with your delivery or pickup details and pay the advance.</p></div></li>
    <li><span class="n">5</span><div><strong>Track and return</strong><p>Follow your booking status here and return items on time to get your deposit back.</p></div></li>
  </ol>
</section>

<h2 class="section-title">Your recent bookings</h2>
<?php if (!$bookings): ?>
<div class="panel">
  <h2>No bookings yet</h2>
  <p>Browse the catalog to see what is available for your event.</p>
  <p class="mt"><a class="btn" href="<?= e(url('catalog.php')) ?>">Browse catalog</a></p>
</div>
<?php else: ?>
<div class="cart-table-wrap">
  <table class="cart-table">
    <thead><tr><th>Booking</th><th>Dates</th><th>Status</th><th class="num">Total</th></tr></thead>
    <tbody>
      <?php foreach ($bookings as $b): ?>
        <tr>
          <td data-label="Booking"><a href="<?= e(url('customer/booking.php?no=' . rawurlencode($b['booking_no']))) ?>"><strong><?= e($b['booking_no']) ?></strong></a></td>
          <td data-label="Dates"><?= e(date('j M', strtotime($b['start_date']))) ?> to <?= e(date('j M Y', strtotime($b['end_date']))) ?></td>
          <td data-label="Status"><span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span></td>
          <td data-label="Total" class="num"><?= e(money($b['total_amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mt">
  <a class="btn" href="<?= e(url('catalog.php')) ?>">Browse catalog</a>
  <a class="btn ghost" href="<?= e(url('customer/bookings.php')) ?>">All my bookings</a>
</p>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

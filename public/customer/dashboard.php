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
<div class="page-head">
  <h1>Welcome, <?= e($user['name']) ?></h1>
  <p>Your bookings and event packages will appear here.</p>
</div>
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
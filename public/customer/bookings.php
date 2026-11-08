<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('customer');

$page   = max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));
$result = BookingService::history($user['id'], $page);
$fmt    = fn(string $d): string => date('j M Y', strtotime($d));

$pageTitle = 'My bookings';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">My account</span>
  <h1>My bookings</h1>
  <p>Every request you have made, with its status, payments and invoice.</p>
</div>

<?php if (!$result['rows']): ?>
  <div class="panel empty">
    <h2>No bookings yet</h2>
    <p>Browse the catalog to see what is available for your event.</p>
    <p class="mt"><a class="btn" href="<?= e(url('catalog.php')) ?>">Browse catalog</a></p>
  </div>
<?php else: ?>
  <p class="muted result-count"><?= (int) $result['total'] ?> booking<?= $result['total'] === 1 ? '' : 's' ?></p>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Booking</th><th>Event dates</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $b): ?>
          <tr>
            <td data-label="Booking">
              <a href="<?= e(url('customer/booking.php?no=' . rawurlencode($b['booking_no']))) ?>"><strong><?= e($b['booking_no']) ?></strong></a>
              <div class="muted small-text"><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?> &middot; requested <?= e($fmt(substr($b['created_at'], 0, 10))) ?></div>
            </td>
            <td data-label="Event dates"><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?></td>
            <td data-label="Status"><span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span></td>
            <td data-label="Total" class="num"><?= e(money($b['total_amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($result['pages'] > 1): ?>
    <div class="pager">
      <?php if ($result['page'] > 1): ?><a class="btn ghost small" href="<?= e(url('customer/bookings.php?page=' . ($result['page'] - 1))) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn ghost small" href="<?= e(url('customer/bookings.php?page=' . ($result['page'] + 1))) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

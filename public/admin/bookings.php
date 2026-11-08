<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$get = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$status = isset(BookingAdminService::STATUSES[$get('status')]) ? $get('status') : '';
$q      = mb_substr($get('q'), 0, 60);
$result = BookingAdminService::search($status, $q, max(1, (int) $get('page')));
$counts = BookingAdminService::counts();

$link = function (array $over = []) use ($status, $q): string {
    $p = array_filter(array_merge(['status' => $status, 'q' => $q], $over), fn($v) => $v !== '' && $v !== 0);
    return url('admin/bookings.php') . ($p ? '?' . http_build_query($p) : '');
};
$fmt = fn(string $d): string => date('j M Y', strtotime($d));

$pageTitle = 'Bookings';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Orders</span>
  <h1>Bookings</h1>
  <p>Review requests, confirm them once the advance is in, and follow each booking through its stages.</p>
</div>

<div class="cats">
  <a href="<?= e($link(['status' => '', 'page' => 0])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All (<?= array_sum($counts) ?>)</a>
  <?php foreach (BookingAdminService::STATUSES as $key => $label): ?>
    <a href="<?= e($link(['status' => $key, 'page' => 0])) ?>" class="<?= $status === $key ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) $counts[$key] ?>)</a>
  <?php endforeach; ?>
</div>

<form method="get" class="toolbar">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <label class="grow">Search
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Booking number, customer name or phone">
  </label>
  <button class="btn ghost" type="submit">Search</button>
</form>

<?php if (!$result['rows']): ?>
  <div class="panel empty">
    <h2>No bookings found</h2>
    <p><?= ($q !== '' || $status !== '') ? 'Try a different status or search.' : 'Booking requests will appear here when customers submit them.' ?></p>
  </div>
<?php else: ?>
  <p class="muted result-count"><?= (int) $result['total'] ?> booking<?= $result['total'] === 1 ? '' : 's' ?></p>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Booking</th><th>Customer</th><th>Event dates</th><th>Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $b): ?>
          <tr>
            <td data-label="Booking"><strong><?= e($b['booking_no']) ?></strong><div class="muted small-text">Requested <?= e($fmt(substr($b['created_at'], 0, 10))) ?></div></td>
            <td data-label="Customer"><?= e($b['full_name']) ?><div class="muted small-text"><?= e($b['phone']) ?></div></td>
            <td data-label="Event dates"><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?><div class="muted small-text"><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?></div></td>
            <td data-label="Total"><?= e(money($b['total_amount'])) ?></td>
            <td data-label="Status"><span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span></td>
            <td class="num"><a href="<?= e(url('admin/booking.php?id=' . (int) $b['booking_id'])) ?>"><?= $b['status'] === 'pending' ? 'Review' : 'View' ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($result['pages'] > 1): ?>
    <div class="pager">
      <?php if ($result['page'] > 1): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

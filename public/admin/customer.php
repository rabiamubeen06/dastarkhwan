<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$id = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : (is_string($_POST['user_id'] ?? null) ? $_POST['user_id'] : 0));
$c  = $id > 0 ? UserAdminService::find($id) : null;
if ($c === null || $c['role'] !== 'customer') {
    flash('error', 'That customer was not found.');
    redirect('admin/customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (in_array($action, ['block', 'unblock'], true)) {
        $err = UserAdminService::setStatus($id, $action === 'block' ? 'blocked' : 'active', $admin['id'], 'customer');
        $err === null ? flash('success', $action === 'block' ? 'Customer blocked. They can no longer log in or send requests.' : 'Customer unblocked.') : flash('error', $err);
    }
    redirect('admin/customer.php?id=' . $id);
}

$stats    = UserAdminService::stats($id);
$bookings = UserAdminService::bookings($id);
$blocked  = $c['status'] === 'blocked';
$fmt      = fn(string $d): string => date('j M Y', strtotime($d));
$waLink   = whatsapp_link((string) $c['phone'], 'Hello ' . $c['full_name'] . ',');

$pageTitle = $c['full_name'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('admin/customers.php')) ?>">&larr; All customers</a></p>

<div class="confirm-top">
  <div>
    <span class="muted small-text">Customer</span>
    <div class="bk-no"><?= e($c['full_name']) ?></div>
  </div>
  <span class="status <?= $blocked ? 'bad' : 'on' ?>"><?= $blocked ? 'Blocked' : 'Active' ?></span>
</div>

<div class="checkout">
  <div>
    <dl class="facts">
      <div><dt>Email</dt><dd><?= e($c['email']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= e($c['phone']) ?><?php if ($waLink !== ''): ?> &middot; <a href="<?= e($waLink) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></dd></div>
      <?php if ($c['address'] || $c['city']): ?><div><dt>Address</dt><dd><?= e(trim((string) $c['address'] . ', ' . (string) $c['city'], ', ')) ?></dd></div><?php endif; ?>
      <div><dt>Joined</dt><dd><?= e($fmt($c['created_at'])) ?></dd></div>
      <div><dt>Last booking</dt><dd><?= $stats['last_booking'] ? e($fmt($stats['last_booking'])) : 'No bookings yet' ?></dd></div>
    </dl>

    <h2 class="mt">Bookings</h2>
    <?php if (!$bookings): ?>
      <p class="muted small-text">This customer has not made any booking requests.</p>
    <?php else: ?>
      <div class="cart-table-wrap">
        <table class="cart-table">
          <thead><tr><th>Booking</th><th>Event dates</th><th>Status</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach ($bookings as $b): ?>
              <tr>
                <td data-label="Booking"><a href="<?= e(url('admin/booking.php?id=' . (int) $b['booking_id'])) ?>"><strong><?= e($b['booking_no']) ?></strong></a>
                  <div class="muted small-text"><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?></div></td>
                <td data-label="Event dates"><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?></td>
                <td data-label="Status"><span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span></td>
                <td data-label="Total" class="num"><?= e(money($b['total_amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <section class="panel quote-panel">
      <h2>History</h2>
      <dl class="quote">
        <div><dt>Bookings</dt><dd><?= (int) $stats['bookings'] ?></dd></div>
        <div class="total"><dt>Paid so far</dt><dd><?= e(money($stats['paid'])) ?></dd></div>
        <div class="after"><dt>Damage charges</dt><dd><?= e(money($stats['damage'])) ?></dd></div>
        <div class="after"><dt>Late fees</dt><dd><?= e(money($stats['late_fees'])) ?></dd></div>
      </dl>
      <p class="hint">Paid is rental, late fee and damage payments received, before refunds. Security deposits are not counted.</p>
    </section>

    <section class="panel mt">
      <h2><?= $blocked ? 'Unblock customer' : 'Block customer' ?></h2>
      <p class="small-text">
        <?= $blocked
            ? 'This customer cannot log in or send booking requests. Unblock them to restore access.'
            : 'A blocked customer cannot log in or send booking requests, and is signed out at once. Their existing bookings are not changed.' ?>
      </p>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $c['user_id'] ?>">
        <input type="hidden" name="action" value="<?= $blocked ? 'unblock' : 'block' ?>">
        <div class="cart-actions mt"><button class="btn<?= $blocked ? '' : ' ghost' ?>" type="submit"><?= $blocked ? 'Unblock customer' : 'Block customer' ?></button></div>
      </form>
    </section>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
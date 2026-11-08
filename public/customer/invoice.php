<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('customer');

$no = is_string($_GET['no'] ?? null) ? $_GET['no'] : '';
$b  = BookingService::find($no, $user['id']);
if ($b === null) {
    flash('error', 'That booking was not found.');
    redirect('customer/bookings.php');
}
if (!BookingService::invoiceAvailable($b['status'])) {
    flash('error', 'An invoice is available once the shop confirms your booking.');
    redirect('customer/booking.php?no=' . rawurlencode($b['booking_no']));
}

$pay = PaymentService::summary($b);
// The invoice lists what was paid towards the rental. The security deposit is held separately.
$paid = array_values(array_filter(PaymentService::forBooking((int) $b['booking_id']), fn($p) => in_array($p['type'], ['advance', 'balance'], true)));
$cust = Database::pdo()->prepare('SELECT full_name, phone, email FROM users WHERE user_id = ?');
$cust->execute([$user['id']]);
$cust = $cust->fetch() ?: ['full_name' => $user['name'], 'phone' => '', 'email' => ''];
$shopWa = trim(setting('shop_whatsapp', ''));
$fmt = fn(string $d): string => date('j M Y', strtotime($d));

$pageTitle = 'Invoice ' . $b['booking_no'];
$scripts = ['assets/js/print.js'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text no-print"><a href="<?= e(url('customer/booking.php?no=' . rawurlencode($b['booking_no']))) ?>">&larr; Back to booking</a></p>

<div class="invoice">
  <div class="invoice-head">
    <div>
      <div class="brand-line"><?= logo_svg(24) ?> <?= brand_ur() ?></div>
      <div class="muted small-text">Crockery rental<?= $shopWa !== '' ? ' &middot; WhatsApp ' . e($shopWa) : '' ?></div>
    </div>
    <div class="invoice-meta">
      <h1>Invoice</h1>
      <div><strong><?= e($b['booking_no']) ?></strong></div>
      <div class="muted small-text">Date: <?= e($fmt(substr($b['created_at'], 0, 10))) ?></div>
    </div>
  </div>

  <dl class="facts invoice-facts">
    <div><dt>Billed to</dt><dd><?= e($cust['full_name']) ?><div class="muted small-text"><?= e($cust['phone']) ?> &middot; <?= e($cust['email']) ?></div></dd></div>
    <div><dt>Event</dt><dd><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?><?= $b['event_name'] ? ' &middot; ' . e($b['event_name']) : '' ?></dd></div>
    <div><dt>Rental period</dt><dd><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?> (<?= (int) $b['rental_days'] ?> day<?= (int) $b['rental_days'] === 1 ? '' : 's' ?>)</dd></div>
  </dl>

  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Item</th><th>Qty</th><th>Rent / day</th><th>Days</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($b['items'] as $it): ?>
          <tr>
            <td data-label="Item"><?= e($it['name']) ?></td>
            <td data-label="Qty"><?= (int) $it['quantity'] ?></td>
            <td data-label="Rent / day"><?= e(money($it['unit_price'])) ?></td>
            <td data-label="Days"><?= (int) $b['rental_days'] ?></td>
            <td data-label="Amount" class="num"><?= e(money($it['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <dl class="quote invoice-totals">
    <div><dt>Rental subtotal</dt><dd><?= e(money($b['subtotal'])) ?></dd></div>
    <?php if ((float) $b['discount'] > 0): ?><div><dt>Discount</dt><dd>- <?= e(money($b['discount'])) ?></dd></div><?php endif; ?>
    <div><dt>Delivery fee</dt><dd><?= e(money($b['delivery_fee'])) ?></dd></div>
    <div class="total"><dt>Total</dt><dd><?= e(money($b['total_amount'])) ?></dd></div>
    <?php foreach ($paid as $p): ?>
      <div class="after"><dt><?= e(PaymentService::typeLabel($p['type'])) ?> paid, <?= e($fmt(substr($p['paid_at'], 0, 10))) ?></dt><dd>- <?= e(money($p['amount'])) ?></dd></div>
    <?php endforeach; ?>
    <div class="after"><dt>Balance due</dt><dd><?= e(money($pay['balance_due'])) ?></dd></div>
  </dl>

  <p class="muted small-text">The refundable security deposit of <?= e(money($b['security_deposit'])) ?> is held separately and is not part of this total.</p>
  <p class="no-print mt"><button class="btn" type="button" data-print>Print invoice</button></p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
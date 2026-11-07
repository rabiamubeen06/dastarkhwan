<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('customer');

$no = is_string($_GET['no'] ?? null) ? $_GET['no'] : '';
$b  = BookingService::find($no, $user['id']);
if ($b === null) {
    flash('error', 'That booking was not found.');
    redirect('customer/bookings.php');
}

$pv       = PaymentService::customerView($b);
$pay      = $pv['summary'];
$payments = PaymentService::forBooking((int) $b['booking_id']);
$closed   = in_array($b['status'], ['rejected', 'cancelled'], true);
$fmt      = fn(string $d): string => date('j M Y', strtotime($d));
$noParam  = rawurlencode($b['booking_no']);

$pageTitle = 'Booking ' . $b['booking_no'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('customer/bookings.php')) ?>">&larr; My bookings</a></p>

<div class="confirm-top">
  <div>
    <span class="muted small-text">Booking number</span>
    <div class="bk-no"><?= e($b['booking_no']) ?></div>
  </div>
  <span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span>
</div>

<?php if ($closed && $b['status_reason']): ?>
  <div class="alert warning">
    <strong>This booking was <?= e($b['status']) ?> by the shop.</strong>
    <div><?= nl2br(e($b['status_reason'])) ?></div>
  </div>
<?php endif; ?>

<div class="checkout">
  <div>
    <dl class="facts">
      <div><dt>Event</dt><dd><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?><?= $b['event_name'] ? ' &middot; ' . e($b['event_name']) : '' ?></dd></div>
      <div><dt>Dates</dt><dd><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?> (<?= (int) $b['rental_days'] ?> day<?= (int) $b['rental_days'] === 1 ? '' : 's' ?>)</dd></div>
      <div><dt>Receiving</dt><dd><?= $b['delivery_method'] === 'delivery'
          ? 'Delivery to ' . e((string) $b['delivery_address']) . ($b['zone_city'] ? ' (' . e($b['zone_city'] . ', ' . $b['zone_area']) . ')' : '')
          : 'Pickup from the shop' ?></dd></div>
      <?php if ($b['guest_count']): ?><div><dt>Guests</dt><dd><?= (int) $b['guest_count'] ?></dd></div><?php endif; ?>
      <?php if ($b['customer_notes']): ?><div><dt>Your notes</dt><dd><?= nl2br(e($b['customer_notes'])) ?></dd></div><?php endif; ?>
    </dl>

    <div class="cart-table-wrap mt">
      <table class="cart-table">
        <thead><tr><th>Item</th><th>Quantity</th><th>Rent / day</th><th class="num">Line total</th></tr></thead>
        <tbody>
          <?php foreach ($b['items'] as $it): ?>
            <tr>
              <td data-label="Item"><strong><?= e($it['name']) ?></strong><div class="muted small-text"><?= e($it['sku']) ?></div></td>
              <td data-label="Quantity"><?= (int) $it['quantity'] ?></td>
              <td data-label="Rent / day"><?= e(money($it['unit_price'])) ?></td>
              <td data-label="Line total" class="num"><?= e(money($it['line_total'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h2 class="mt">Payments</h2>
    <?php if (!$payments): ?>
      <p class="muted small-text">No payments recorded yet. Payments appear here after the shop records them.</p>
    <?php else: ?>
      <div class="cart-table-wrap">
        <table class="cart-table">
          <thead><tr><th>Date</th><th>Type</th><th>Method</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td data-label="Date"><?= e(date('j M Y, g:i a', strtotime($p['paid_at']))) ?><?php if ($p['reference_no']): ?><div class="muted small-text">Ref: <?= e($p['reference_no']) ?></div><?php endif; ?></td>
                <td data-label="Type"><?= e(PaymentService::typeLabel($p['type'])) ?></td>
                <td data-label="Method"><?= e(PaymentService::METHODS[$p['method']] ?? $p['method']) ?></td>
                <td data-label="Amount" class="num"><?= $p['type'] === 'refund' ? '- ' : '' ?><?= e(money($p['amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <section class="panel quote-panel">
    <h2>Amounts</h2>
    <dl class="quote">
      <div><dt>Rental subtotal</dt><dd><?= e(money($b['subtotal'])) ?></dd></div>
      <div><dt>Package discount</dt><dd><?= e((float) $b['discount'] > 0 ? '- ' . money($b['discount']) : money(0)) ?></dd></div>
      <div><dt>Delivery fee</dt><dd><?= e(money($b['delivery_fee'])) ?></dd></div>
      <div class="total"><dt>Total</dt><dd><?= e(money($b['total_amount'])) ?></dd></div>
      <div class="after"><dt>Paid so far</dt><dd><?= e(money($pay['paid'])) ?></dd></div>
      <?php if (!$closed): ?><div class="after"><dt>Balance due</dt><dd><?= e(money($pay['balance_due'])) ?></dd></div><?php endif; ?>
      <div class="after"><dt>Refundable security deposit</dt><dd><?= e(money($pay['deposit_received'])) ?> of <?= e(money($pay['deposit_required'])) ?></dd></div>
      <?php if ($pay['refunded'] > 0): ?><div class="after"><dt>Refunded to you</dt><dd><?= e(money($pay['refunded'])) ?></dd></div><?php endif; ?>
    </dl>

    <?php require APP_PATH . '/views/payment-panel.php'; ?>

    <?php if (BookingService::invoiceAvailable($b['status'])): ?>
      <a class="btn ghost block mt" href="<?= e(url('customer/invoice.php?no=' . $noParam)) ?>">View invoice</a>
    <?php endif; ?>
  </section>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
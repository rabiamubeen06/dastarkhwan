<?php
require __DIR__ . '/../app/bootstrap.php';
$user = Auth::requireRole('customer');

$no = is_string($_GET['no'] ?? null) ? $_GET['no'] : '';
$b  = BookingService::find($no, $user['id']);
if ($b === null) {
    flash('error', 'That booking was not found.');
    redirect('customer/dashboard.php');
}

$advance  = round((float) $b['total_amount'] * (float) setting('advance_percent', '30') / 100, 2);
$shopWa   = trim(setting('shop_whatsapp', ''));
$waLink   = whatsapp_link($shopWa, 'Hello, my booking number is ' . $b['booking_no'] . '.');
$pv       = PaymentService::customerView($b);
$fmtDate  = fn(string $d): string => date('j M Y', strtotime($d));

$pageTitle = 'Booking ' . $b['booking_no'];
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Booking</span>
  <h1>Request received</h1>
  <p>Thank you. Keep your booking number for any message to the shop.</p>
</div>

<div class="confirm-top">
  <div>
    <span class="muted small-text">Booking number</span>
    <div class="bk-no"><?= e($b['booking_no']) ?></div>
  </div>
  <span class="status"><?= e(ucfirst(str_replace('_', ' ', $b['status']))) ?></span>
</div>

<div class="checkout">
  <div>
    <dl class="facts">
      <div><dt>Event</dt><dd><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?><?= $b['event_name'] ? ' &middot; ' . e($b['event_name']) : '' ?></dd></div>
      <div><dt>Dates</dt><dd><?= e($fmtDate($b['start_date'])) ?> to <?= e($fmtDate($b['end_date'])) ?> (<?= (int) $b['rental_days'] ?> day<?= (int) $b['rental_days'] === 1 ? '' : 's' ?>)</dd></div>
      <div><dt>Receiving</dt><dd><?= $b['delivery_method'] === 'delivery'
          ? 'Delivery to ' . e((string) $b['delivery_address']) . ' (' . e($b['zone_city'] . ', ' . $b['zone_area']) . ')'
          : 'Pickup from the shop' ?></dd></div>
      <?php if ($b['guest_count']): ?><div><dt>Guests</dt><dd><?= (int) $b['guest_count'] ?></dd></div><?php endif; ?>
      <?php if ($b['customer_notes']): ?><div><dt>Your notes</dt><dd><?= e($b['customer_notes']) ?></dd></div><?php endif; ?>
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
  </div>

  <section class="panel quote-panel">
    <h2>Amounts</h2>
    <dl class="quote">
      <div><dt>Rental subtotal</dt><dd><?= e(money($b['subtotal'])) ?></dd></div>
      <div><dt>Package discount<?= !empty($b['package_name']) ? ' (' . e($b['package_name']) . ')' : '' ?></dt><dd><?= e((float) $b['discount'] > 0 ? '- ' . money($b['discount']) : money(0)) ?></dd></div>
      <div><dt>Delivery fee</dt><dd><?= e(money($b['delivery_fee'])) ?></dd></div>
      <div class="total"><dt>Total</dt><dd><?= e(money($b['total_amount'])) ?></dd></div>
      <div class="after"><dt>Advance to confirm</dt><dd><?= e(money($advance)) ?></dd></div>
      <div class="after"><dt>Refundable security deposit</dt><dd><?= e(money($b['security_deposit'])) ?></dd></div>
    </dl>

    <?php require APP_PATH . '/views/payment-panel.php'; ?>

    <h2 class="mt">What happens next</h2>
    <ol class="next-steps">
      <li>The shop reviews your request and checks stock for your dates.</li>
      <li>Once approved, you pay the advance. Your items are locked only after it is received.</li>
      <li>Your items are delivered or ready for pickup. The deposit is returned after they come back undamaged.</li>
    </ol>

    <?php if ($waLink !== ''): ?>
      <p class="mt muted small-text">Questions? Message the shop on WhatsApp: <strong><?= e($shopWa) ?></strong></p>
      <a class="btn ghost block" href="<?= e($waLink) ?>" target="_blank" rel="noopener">Message the shop on WhatsApp</a>
    <?php else: ?>
      <p class="mt muted small-text">The shop will contact you on your registered phone number.</p>
    <?php endif; ?>
  </section>
</div>
<p class="mt">
  <a class="btn" href="<?= e(url('customer/booking.php?no=' . rawurlencode($b['booking_no']))) ?>">View booking details</a>
  <a class="btn ghost" href="<?= e(url('customer/dashboard.php')) ?>">Go to my dashboard</a>
</p>
<?php require APP_PATH . '/views/footer.php'; ?>

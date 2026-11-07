<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$id  = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : $str('booking_id'));
$errors = [];
$conflicts = [];
$payErrors = [];
$open = '';   // which action form to keep open after a failed submit

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = $str('action');
    $note   = $str('note');
    $res = null;
    if ($action === 'confirm') {
        $res = BookingAdminService::confirm($id, $admin['id'], $note);
        $ok = 'Booking confirmed. The items are now locked for these dates.';
    } elseif ($action === 'reject') {
        $res = BookingAdminService::reject($id, $admin['id'], $note);
        $ok = 'Booking request rejected.';
    } elseif ($action === 'cancel') {
        $res = BookingAdminService::cancel($id, $admin['id'], $note);
        $ok = !empty($res['non_refundable'])
            ? 'Booking cancelled. The event is within ' . BookingAdminService::NON_REFUNDABLE_DAYS . ' days, so the advance is non-refundable.'
            : 'Booking cancelled.';
    } elseif ($action === 'deliver') {
        $res = BookingAdminService::markDelivered($id, $admin['id'], $note);
        $ok = 'Booking marked as delivered.';
    } elseif ($action === 'returnpending') {
        $res = BookingAdminService::markReturnPending($id, $admin['id'], $note);
        $ok = 'Booking marked as awaiting return.';
    }
    if ($action === 'payment') {
        $pr = PaymentService::record($id, $admin['id'], $_POST);
        if ($pr['ok']) {
            flash('success', 'Payment recorded.');
            redirect('admin/booking.php?id=' . $id);
        }
        $payErrors = $pr['errors'];
        $open = 'payment';
    }
    if ($res !== null) {
        if ($res['ok']) {
            flash('success', $ok);
            redirect('admin/booking.php?id=' . $id);
        }
        $errors[$action] = $res['error'] ?? 'Could not save.';
        $conflicts = $res['conflicts'] ?? [];
        $open = $action;
    }
}

$b = $id > 0 ? BookingAdminService::find($id) : null;
if ($b === null) {
    flash('error', 'That booking was not found.');
    redirect('admin/bookings.php');
}

$pending   = $b['status'] === 'pending';
$canCancel = in_array($b['status'], ['pending', 'confirmed'], true);
$canDeliver = $b['status'] === 'confirmed';
$canReturnPending = $b['status'] === 'delivered';
$canReceive = in_array($b['status'], ReturnService::RECEIVABLE, true);
$return    = ReturnService::forBooking($id);
$avail     = $pending ? BookingAdminService::itemAvailability($b) : [];
$pay       = PaymentService::summary($b);
$payments  = PaymentService::forBooking($id);
$payTypes  = PaymentService::allowedTypes($b['status']);
$pv        = fn(string $k): string => e($open === 'payment' ? trim($str($k)) : '');
$nowLocal  = date('Y-m-d\TH:i');
$nonRef    = $b['status'] === 'confirmed' && BookingAdminService::advanceNonRefundable($b['start_date']);
$fmt       = fn(string $d): string => date('j M Y', strtotime($d));
$waDigits  = Auth::normalizePhone((string) preg_replace('/\D+/', '', (string) $b['phone'])) ?? '';
$waLink    = $waDigits !== '' ? 'https://wa.me/' . $waDigits . '?text=' . rawurlencode('Hello, regarding your booking ' . $b['booking_no'] . '.') : '';
$noteVal   = fn(string $a): string => e($open === $a ? trim($str('note')) : '');

$pageTitle = 'Booking ' . $b['booking_no'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('admin/bookings.php')) ?>">&larr; All bookings</a></p>

<div class="confirm-top">
  <div>
    <span class="muted small-text">Booking number</span>
    <div class="bk-no"><?= e($b['booking_no']) ?></div>
  </div>
  <span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span>
</div>

<div class="checkout">
  <div>
    <dl class="facts">
      <div><dt>Customer</dt><dd><?= e($b['full_name']) ?> &middot; <?= e($b['phone']) ?> &middot; <?= e($b['email']) ?>
        <?php if ($waLink !== ''): ?> &middot; <a href="<?= e($waLink) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></dd></div>
      <div><dt>Event</dt><dd><?= e(BookingService::EVENT_TYPES[$b['event_type']] ?? $b['event_type']) ?><?= $b['event_name'] ? ' &middot; ' . e($b['event_name']) : '' ?></dd></div>
      <div><dt>Dates</dt><dd><?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?> (<?= (int) $b['rental_days'] ?> day<?= (int) $b['rental_days'] === 1 ? '' : 's' ?>)</dd></div>
      <div><dt>Receiving</dt><dd><?= $b['delivery_method'] === 'delivery'
          ? 'Delivery to ' . e((string) $b['delivery_address']) . ($b['zone_city'] ? ' (' . e($b['zone_city'] . ', ' . $b['zone_area']) . ')' : '')
          : 'Pickup from the shop' ?></dd></div>
      <?php if ($b['guest_count']): ?><div><dt>Guests</dt><dd><?= (int) $b['guest_count'] ?></dd></div><?php endif; ?>
      <?php if ($b['customer_notes']): ?><div><dt>Customer notes</dt><dd><?= nl2br(e($b['customer_notes'])) ?></dd></div><?php endif; ?>
      <?php if ($b['confirmed_by_name']): ?><div><dt>Confirmed by</dt><dd><?= e($b['confirmed_by_name']) ?></dd></div><?php endif; ?>
      <?php if ($b['admin_notes']): ?><div><dt>Internal notes</dt><dd><?= nl2br(e($b['admin_notes'])) ?></dd></div><?php endif; ?>
    </dl>

    <div class="cart-table-wrap mt">
      <table class="cart-table">
        <thead><tr><th>Item</th><th>Quantity</th><?php if ($pending): ?><th>Free for these dates</th><?php endif; ?><th class="num">Line total</th></tr></thead>
        <tbody>
          <?php foreach ($b['items'] as $it):
              $have = $avail[(int) $it['product_id']] ?? 0;
              $short = $pending && (int) $it['quantity'] > $have; ?>
            <tr>
              <td data-label="Item"><strong><?= e($it['name']) ?></strong><div class="muted small-text"><?= e($it['sku']) ?> &middot; <?= e(money($it['unit_price'])) ?> / day</div></td>
              <td data-label="Quantity"><?= (int) $it['quantity'] ?></td>
              <?php if ($pending): ?>
                <td data-label="Free for these dates"><span class="pill <?= $short ? 'bad' : 'ok' ?>"><?= $short ? 'Short: ' . $have . ' free' : $have . ' free' ?></span></td>
              <?php endif; ?>
              <td data-label="Line total" class="num"><?= e(money($it['line_total'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($return): ?>
      <h2 class="mt">Return</h2>
      <dl class="facts">
        <div><dt>Received</dt><dd><?= e(date('j M Y, g:i a', strtotime($return['returned_at']))) ?> by <?= e((string) $return['received_by_name']) ?></dd></div>
        <div><dt>Late fee</dt><dd><?= e(money($return['late_fee'])) ?> (<?= (int) $return['late_days'] ?> late day<?= (int) $return['late_days'] === 1 ? '' : 's' ?>)</dd></div>
        <div><dt>Damage and loss charges</dt><dd><?= e(money($return['damage_total'])) ?></dd></div>
        <div><dt>Total charges</dt><dd><strong><?= e(money($return['charges_total'])) ?></strong> <span class="muted small-text">(settled against the security deposit)</span></dd></div>
        <?php if ($return['notes']): ?><div><dt>Return note</dt><dd><?= e($return['notes']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="cart-table-wrap mt">
        <table class="cart-table">
          <thead><tr><th>Item</th><th>Returned</th><th>Damaged</th><th>Missing</th><th class="num">Charge</th></tr></thead>
          <tbody>
            <?php foreach ($return['lines'] as $l): ?>
              <tr>
                <td data-label="Item"><strong><?= e($l['name']) ?></strong><?php if ($l['remarks']): ?><div class="muted small-text"><?= e($l['remarks']) ?></div><?php endif; ?></td>
                <td data-label="Returned"><?= (int) $l['qty_returned'] ?> of <?= (int) $l['quantity'] ?></td>
                <td data-label="Damaged"><?= (int) $l['qty_damaged'] ?></td>
                <td data-label="Missing"><?= (int) $l['qty_missing'] ?></td>
                <td data-label="Charge" class="num"><?= e(money($l['damage_charge'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h2 class="mt">Payments</h2>
    <?php if (!$payments): ?>
      <p class="muted small-text">No payments recorded yet.</p>
    <?php else: ?>
      <div class="cart-table-wrap">
        <table class="cart-table">
          <thead><tr><th>Date</th><th>Type</th><th>Method</th><th>Received by</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td data-label="Date"><?= e(date('j M Y, g:i a', strtotime($p['paid_at']))) ?><?php if ($p['reference_no']): ?><div class="muted small-text">Ref: <?= e($p['reference_no']) ?></div><?php endif; ?></td>
                <td data-label="Type"><?= e(PaymentService::typeLabel($p['type'])) ?><?php if ($p['notes']): ?><div class="muted small-text"><?= e($p['notes']) ?></div><?php endif; ?></td>
                <td data-label="Method"><?= e(PaymentService::METHODS[$p['method']] ?? $p['method']) ?></td>
                <td data-label="Received by"><?= e((string) ($p['received_by_name'] ?? '')) ?></td>
                <td data-label="Amount" class="num"><?= $p['type'] === 'refund' ? '- ' : '' ?><?= e(money($p['amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <section class="panel quote-panel">
      <h2>Amounts</h2>
      <dl class="quote">
        <div><dt>Rental subtotal</dt><dd><?= e(money($b['subtotal'])) ?></dd></div>
        <div><dt>Package discount</dt><dd><?= e((float) $b['discount'] > 0 ? '- ' . money($b['discount']) : money(0)) ?></dd></div>
        <div><dt>Delivery fee</dt><dd><?= e(money($b['delivery_fee'])) ?></dd></div>
        <div class="total"><dt>Total</dt><dd><?= e(money($b['total_amount'])) ?></dd></div>
        <div class="after"><dt>Paid so far</dt><dd><?= e(money($pay['paid'])) ?></dd></div>
        <div class="after"><dt>Balance due</dt><dd><?= e(money($pay['balance_due'])) ?></dd></div>
        <div class="after"><dt>Advance required (<?= e(setting('advance_percent', '30')) ?>%)</dt><dd><?= e(money($pay['advance_required'])) ?></dd></div>
        <div class="after"><dt>Security deposit received</dt><dd><?= e(money($pay['deposit_received'])) ?> of <?= e(money($pay['deposit_required'])) ?></dd></div>
        <?php if ($pay['refunded'] > 0): ?><div class="after"><dt>Refunded</dt><dd><?= e(money($pay['refunded'])) ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($payTypes): ?>
      <section class="panel mt">
        <h2>Record payment</h2>
        <p class="muted small-text">Enter a payment after the customer sends proof on WhatsApp. Payments cannot be edited afterwards.</p>
        <form method="post" novalidate>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="payment">
          <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
          <label>Type
            <select name="type">
              <?php foreach ($payTypes as $t): ?>
                <option value="<?= e($t) ?>" <?= $pv('type') === $t ? 'selected' : '' ?>><?= e(PaymentService::TYPES[$t]) ?></option>
              <?php endforeach; ?>
            </select>
          </label><?= field_error($payErrors, 'type') ?>
          <label>Amount (PKR)
            <input type="text" name="amount" inputmode="decimal" maxlength="13" value="<?= $pv('amount') ?>" required>
          </label><?= field_error($payErrors, 'amount') ?>
          <p class="hint">Advance still needed: <?= e(money(max(0, $pay['advance_required'] - $pay['advance_paid']))) ?> &middot; Balance due: <?= e(money($pay['balance_due'])) ?><?= $pay['refundable_left'] > 0 ? ' &middot; Refundable: ' . e(money($pay['refundable_left'])) : '' ?></p>
          <label>Method
            <select name="method">
              <?php foreach (PaymentService::METHODS as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $pv('method') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label><?= field_error($payErrors, 'method') ?>
          <label>Reference (transaction ID, optional)
            <input type="text" name="reference_no" maxlength="80" value="<?= $pv('reference_no') ?>">
          </label><?= field_error($payErrors, 'reference_no') ?>
          <label>Paid on
            <input type="datetime-local" name="paid_at" max="<?= e($nowLocal) ?>" value="<?= $open === 'payment' ? $pv('paid_at') : e($nowLocal) ?>">
          </label><?= field_error($payErrors, 'paid_at') ?>
          <label>Note (optional)
            <input type="text" name="notes" maxlength="255" value="<?= $pv('notes') ?>">
          </label><?= field_error($payErrors, 'notes') ?>
          <div class="cart-actions mt"><button class="btn" type="submit">Record payment</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($canDeliver || $canReturnPending || $canReceive): ?>
      <section class="panel mt">
        <h2>Delivery and return</h2>
        <?php if (isset($errors['deliver']) || isset($errors['returnpending'])): ?><div class="alert error"><?= e($errors['deliver'] ?? $errors['returnpending']) ?></div><?php endif; ?>
        <?php if ($canDeliver): ?>
          <?php if ($pay['balance_due'] > 0 || $pay['deposit_received'] < $pay['deposit_required']): ?>
            <div class="alert warning">Still unpaid:
              <?= $pay['balance_due'] > 0 ? 'balance ' . e(money($pay['balance_due'])) : '' ?><?= $pay['balance_due'] > 0 && $pay['deposit_received'] < $pay['deposit_required'] ? ' and ' : '' ?><?= $pay['deposit_received'] < $pay['deposit_required'] ? 'security deposit ' . e(money($pay['deposit_required'] - $pay['deposit_received'])) : '' ?>.</div>
          <?php endif; ?>
          <p class="muted small-text">Mark as delivered when the items are handed over or collected.</p>
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="deliver">
            <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
            <div class="cart-actions"><button class="btn" type="submit">Mark as delivered</button></div>
          </form>
        <?php endif; ?>
        <?php if ($canReturnPending): ?>
          <p class="muted small-text">When the event is over, mark the booking as awaiting return.</p>
          <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="returnpending">
            <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
            <div class="cart-actions"><button class="btn ghost" type="submit">Event over, awaiting return</button></div>
          </form>
        <?php endif; ?>
        <?php if ($canReceive): ?>
          <p class="muted small-text<?= $canReturnPending ? ' mt' : '' ?>">When the items are back, count them and record any damage.</p>
          <div class="cart-actions"><a class="btn<?= $canReturnPending ? '' : ' block' ?>" href="<?= e(url('admin/booking-return.php?id=' . (int) $b['booking_id'])) ?>">Receive items</a></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($nonRef): ?>
      <div class="alert warning mt">The event starts within <?= BookingAdminService::NON_REFUNDABLE_DAYS ?> days. If this booking is cancelled now, the advance is non-refundable.</div>
    <?php endif; ?>

    <?php if ($pending): ?>
      <section class="panel mt">
        <h2>Confirm booking</h2>
        <?php if ($pay['advance_ok']): ?>
          <p class="muted small-text">The advance of <?= e(money($pay['advance_paid'])) ?> is recorded. Stock is re-checked and locked when you confirm.</p>
        <?php else: ?>
          <p class="muted small-text">Record the advance of <?= e(money($pay['advance_required'])) ?> above first. Stock is locked only after the advance is received.</p>
        <?php endif; ?>
        <?php if (isset($errors['confirm'])): ?>
          <div class="alert error"><?= e($errors['confirm']) ?>
            <?php foreach ($conflicts as $c): ?><div><?= e($c['name']) ?>: wanted <?= (int) $c['wanted'] ?>, only <?= (int) $c['available'] ?> free</div><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="confirm">
          <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
          <label>Note (optional)
            <textarea name="note" rows="2" maxlength="500"><?= $noteVal('confirm') ?></textarea>
          </label>
          <div class="cart-actions mt"><button class="btn" type="submit"<?= $pay['advance_ok'] ? '' : ' disabled' ?>>Confirm booking</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($pending): ?>
      <section class="panel mt">
        <h2>Reject request</h2>
        <?php if (isset($errors['reject'])): ?><div class="alert error"><?= e($errors['reject']) ?></div><?php endif; ?>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
          <label>Reason (the customer will see this)
            <textarea name="note" rows="2" maxlength="500" required><?= $noteVal('reject') ?></textarea>
          </label>
          <div class="cart-actions mt"><button class="btn ghost" type="submit">Reject request</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($canCancel): ?>
      <section class="panel mt">
        <h2>Cancel booking</h2>
        <?php if (isset($errors['cancel'])): ?><div class="alert error"><?= e($errors['cancel']) ?></div><?php endif; ?>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
          <label>Reason (the customer will see this)
            <textarea name="note" rows="2" maxlength="500" required><?= $noteVal('cancel') ?></textarea>
          </label>
          <div class="cart-actions mt"><button class="btn ghost" type="submit">Cancel booking</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if (!$canCancel && !$canDeliver && !$canReceive): ?>
      <p class="muted small-text mt">No actions are available at this stage.</p>
    <?php endif; ?>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
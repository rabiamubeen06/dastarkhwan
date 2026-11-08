<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$id  = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : $str('booking_id'));
$errors = [];
$conflicts = [];
$payErrors = [];
$settleErrors = [];
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
    if ($action === 'settle') {
        $sr = DepositService::settle($id, $admin['id'], $_POST);
        if ($sr['ok']) {
            flash('success', 'Deposit settled: ' . money($sr['deducted']) . ' deducted, ' . money($sr['refund']) . ' refunded.'
                . ($sr['extra_owed'] > 0 ? ' The customer still owes ' . money($sr['extra_owed']) . ' in extra charges.' : '')
                . (!empty($sr['completed']) ? ' The booking is now completed.' : ''));
            redirect('admin/booking.php?id=' . $id);
        }
        $settleErrors = $sr['errors'];
        $open = 'settle';
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
$showDeposit = in_array($b['status'], ['confirmed', 'delivered', 'return_pending', 'returned', 'completed'], true);
$dep       = $showDeposit ? DepositService::view($b) : null;
$sv        = fn(string $k): string => e($open === 'settle' ? trim($str($k)) : '');
$payments  = PaymentService::forBooking($id);
$payTypes  = PaymentService::allowedTypes($b['status']);
$pv        = fn(string $k): string => e($open === 'payment' ? trim($str($k)) : '');
$nowLocal  = date('Y-m-d\TH:i');
$nonRef    = $b['status'] === 'confirmed' && BookingAdminService::advanceNonRefundable($b['start_date']);
$fmt       = fn(string $d): string => date('j M Y', strtotime($d));
$waDigits  = Auth::normalizePhone((string) preg_replace('/\D+/', '', (string) $b['phone'])) ?? '';
$waLink    = $waDigits !== '' ? 'https://wa.me/' . $waDigits . '?text=' . rawurlencode('Hello, regarding your booking ' . $b['booking_no'] . '.') : '';
$noteVal   = fn(string $a): string => e($open === $a ? trim($str('note')) : '');

// ---------- Progress tracker (display only; nothing here changes how bookings work) ----------
$status       = $b['status'];
$ended        = in_array($status, ['rejected', 'cancelled'], true);
$doneConfirm  = in_array($status, ['confirmed', 'delivered', 'return_pending', 'returned', 'completed'], true);
$doneDeliver  = in_array($status, ['delivered', 'return_pending', 'returned', 'completed'], true);
$doneReturn   = in_array($status, ['returned', 'completed'], true);
$doneDeposit  = $status === 'completed' || ($dep !== null && $dep['settled']);

// The step that needs the admin next, based on the booking status.
$current = match (true) {
    $ended                                    => '',
    $pending && !$pay['advance_ok']           => 'payment',
    $pending                                  => 'confirm',
    $status === 'confirmed'                   => 'deliver',
    in_array($status, ['delivered', 'return_pending'], true) => 'return',
    $status === 'returned' && !$doneDeposit   => 'deposit',
    default                                   => '',
};

// After a failed form submit, reopen the panel that form lives in.
$defaultTab = match ($open) {
    'payment'                => 'payment',
    'confirm', 'reject'      => 'confirm',
    'deliver'                => 'deliver',
    'returnpending'          => 'return',
    'settle'                 => 'deposit',
    'cancel'                 => 'more',
    default                  => $current !== '' ? $current : 'payment',
};

$depReq = $dep ? (float) $dep['required'] : (float) $pay['deposit_required'];
$flow = [
    'payment' => ['Payment', $pay['advance_ok'],
        money($pay['advance_paid']) . ' of ' . money($pay['advance_required']) . ' advance',
        (float) $pay['advance_paid'], (float) $pay['advance_required']],
    'confirm' => ['Confirm', $doneConfirm,
        $doneConfirm ? 'Confirmed' : ($pending ? ($pay['advance_ok'] ? 'Ready to confirm' : 'Needs the advance first') : 'Not confirmed'),
        $doneConfirm ? 1.0 : 0.0, 1.0],
    'deliver' => ['Deliver', $doneDeliver,
        $doneDeliver ? 'Items handed over' : ($canDeliver ? 'Ready to hand over' : 'After confirmation'),
        $doneDeliver ? 1.0 : 0.0, 1.0],
    'return'  => ['Return', $doneReturn,
        $doneReturn ? 'Items received back' : ($canReceive ? 'Items due back' : 'After delivery'),
        $doneReturn ? 1.0 : 0.0, 1.0],
    'deposit' => ['Deposit', $doneDeposit,
        $doneDeposit ? 'Deposit settled' : money($pay['deposit_received']) . ' of ' . money($depReq) . ' held',
        $doneDeposit ? 1.0 : (float) $pay['deposit_received'], $doneDeposit ? 1.0 : $depReq],
];
$flowState = function (string $key, bool $done) use ($current, $ended): string {
    if ($done) { return 'is-done'; }
    if ($ended) { return 'is-off'; }
    return $key === $current ? 'is-current' : 'is-todo';
};
$flowLabel = ['is-done' => 'Done', 'is-current' => 'Next', 'is-todo' => 'Waiting', 'is-off' => 'Stopped'];
$bar = function (float $value, float $max): string {
    $max = max(0.01, $max);
    $value = max(0, min($value, $max));
    return '<progress class="bar" value="' . e((string) round($value, 2)) . '" max="' . e((string) round($max, 2)) . '"></progress>';
};
$meter = function (string $label, float $value, float $max, string $note) use ($bar): string {
    return '<div class="meter"><div class="meter-top"><span>' . e($label) . '</span><span class="muted small-text">' . e($note) . '</span></div>' . $bar($value, $max) . '</div>';
};
$nextText = [
    'payment' => 'Record the advance payment',
    'confirm' => 'Review stock, then confirm or reject',
    'deliver' => 'Hand over the items to the customer',
    'return'  => 'Receive the items back and check them',
    'deposit' => 'Settle the security deposit',
][$current] ?? '';
$tabs = ['payment' => 'Payment', 'confirm' => 'Confirm', 'deliver' => 'Deliver', 'return' => 'Return', 'deposit' => 'Deposit'];
if ($canCancel) { $tabs['more'] = 'Cancel'; }

$pageTitle = 'Booking ' . $b['booking_no'];
$scripts   = ['assets/js/booking-steps.js'];
require APP_PATH . '/views/header.php';
?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="<?= e(url('admin/bookings.php')) ?>">Bookings</a> <span>/</span> <span><?= e($b['booking_no']) ?></span></nav>

<section class="bk-head">
  <div class="bk-head-main">
    <span class="eyebrow">Booking</span>
    <div class="bk-no"><?= e($b['booking_no']) ?></div>
    <p class="muted"><?= e($b['full_name']) ?> &middot; <?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?></p>
  </div>
  <div class="bk-head-side">
    <span class="status <?= e(BookingAdminService::statusClass($b['status'])) ?>"><?= e(BookingAdminService::statusLabel($b['status'])) ?></span>
    <div class="bk-kpis">
      <div><span>Total</span><strong><?= e(money($b['total_amount'])) ?></strong></div>
      <div><span>Paid</span><strong><?= e(money($pay['paid'])) ?></strong></div>
      <div><span>Balance due</span><strong><?= e(money($pay['balance_due'])) ?></strong></div>
    </div>
  </div>
</section>

<?php if ($ended): ?>
  <div class="alert warning">This booking was <?= e(strtolower(BookingAdminService::statusLabel($status))) ?>, so the remaining steps are closed.</div>
<?php endif; ?>


<div class="checkout">
  <div>
    <ol class="flow" aria-label="Booking progress">
      <li class="flow-step is-done">
        <div class="flow-card">
          <span class="flow-top"><span class="flow-no" aria-hidden="true">1</span><span class="flow-state">Done</span></span>
          <span class="flow-title">Request</span>
          <span class="flow-sub">Booking placed</span>
          <?= $bar(1, 1) ?>
        </div>
      </li>
      <?php $n = 2; foreach ($flow as $key => [$title, $done, $sub, $val, $max]): $state = $flowState($key, (bool) $done); ?>
        <li class="flow-step <?= e($state) ?>">
          <a class="flow-card" href="#act-<?= e($key) ?>" data-act="<?= e($key) ?>">
            <span class="flow-top"><span class="flow-no" aria-hidden="true"><?= $n++ ?></span><span class="flow-state"><?= e($flowLabel[$state]) ?></span></span>
            <span class="flow-title"><?= e($title) ?></span>
            <span class="flow-sub"><?= e($sub) ?></span>
            <?= $bar($val, $max) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>

    <section class="panel">
    <h2 class="sec-h">Customer and event</h2>
    <dl class="facts facts-2">
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
    </section>

    <h2 class="sec-h">Items</h2>

    <div class="cart-table-wrap">
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

    <h2 class="sec-h">Amounts</h2>
    <section class="panel quote-panel">
      <dl class="quote">
        <div><dt>Rental subtotal</dt><dd><?= e(money($b['subtotal'])) ?></dd></div>
        <div><dt>Package discount<?= !empty($b['package_name']) ? ' (' . e($b['package_name']) . ')' : '' ?></dt><dd><?= e((float) $b['discount'] > 0 ? '- ' . money($b['discount']) : money(0)) ?></dd></div>
        <div><dt>Delivery fee</dt><dd><?= e(money($b['delivery_fee'])) ?></dd></div>
        <div class="total"><dt>Total</dt><dd><?= e(money($b['total_amount'])) ?></dd></div>
        <div class="after"><dt>Paid so far</dt><dd><?= e(money($pay['paid'])) ?></dd></div>
        <div class="after"><dt>Balance due</dt><dd><?= e(money($pay['balance_due'])) ?></dd></div>
        <div class="after"><dt>Advance required (<?= e(setting('advance_percent', '30')) ?>%)</dt><dd><?= e(money($pay['advance_required'])) ?></dd></div>
        <div class="after"><dt>Security deposit received</dt><dd><?= e(money($pay['deposit_received'])) ?> of <?= e(money($pay['deposit_required'])) ?></dd></div>
        <?php if ($pay['refunded'] > 0): ?><div class="after"><dt>Refunded</dt><dd><?= e(money($pay['refunded'])) ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($return): ?>
      <h2 class="sec-h">Return</h2>
      <div class="panel"><dl class="facts facts-2">
        <div><dt>Received</dt><dd><?= e(date('j M Y, g:i a', strtotime($return['returned_at']))) ?> by <?= e((string) $return['received_by_name']) ?></dd></div>
        <div><dt>Late fee</dt><dd><?= e(money($return['late_fee'])) ?> (<?= (int) $return['late_days'] ?> late day<?= (int) $return['late_days'] === 1 ? '' : 's' ?>)</dd></div>
        <div><dt>Damage and loss charges</dt><dd><?= e(money($return['damage_total'])) ?></dd></div>
        <div><dt>Total charges</dt><dd><strong><?= e(money($return['charges_total'])) ?></strong> <span class="muted small-text">(settled against the security deposit)</span></dd></div>
        <?php if ($return['notes']): ?><div><dt>Return note</dt><dd><?= e($return['notes']) ?></dd></div><?php endif; ?>
      </dl></div>
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

    <h2 class="sec-h">Payments</h2>
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

  <!-- Action center: one panel at a time, always in view. Without JavaScript every panel is simply listed. -->
  <aside class="bk-side act-center" id="actCenter" data-default="<?= e($defaultTab) ?>" aria-label="Booking actions">
    <div class="act-next <?= $current !== '' ? 'is-action' : ($ended ? 'is-closed' : 'is-finished') ?>">
      <span class="act-next-label"><?= $current !== '' ? 'Next step' : ($ended ? 'Closed' : 'Finished') ?></span>
      <strong><?= e($current !== '' ? $nextText : ($ended ? 'No further action is needed' : 'All steps are complete')) ?></strong>
    </div>
    <div class="act-tabs" role="tablist" aria-label="Choose an action">
      <?php foreach ($tabs as $key => $label):
          $tabState = $key === 'more' ? '' : $flowState($key, (bool) $flow[$key][1]); ?>
        <button type="button" role="tab" class="act-tab <?= e($tabState) ?>" data-act="<?= e($key) ?>" aria-controls="act-<?= e($key) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>

    <?php if ($nonRef): ?>
      <div class="alert warning">The event starts within <?= BookingAdminService::NON_REFUNDABLE_DAYS ?> days. If this booking is cancelled now, the advance is non-refundable.</div>
    <?php endif; ?>

    <!-- 1. Payment -->
    <section class="panel act-panel" id="act-payment" data-act="payment" role="tabpanel">
      <h2>Payments</h2>
      <div class="meters">
        <?= $meter('Advance', (float) $pay['advance_paid'], (float) $pay['advance_required'], money($pay['advance_paid']) . ' of ' . money($pay['advance_required'])) ?>
        <?= $meter('Rental paid', (float) $pay['paid'], (float) $b['total_amount'], money($pay['paid']) . ' of ' . money($b['total_amount'])) ?>
        <?= $meter('Security deposit', (float) $pay['deposit_received'], (float) $pay['deposit_required'], money($pay['deposit_received']) . ' of ' . money($pay['deposit_required'])) ?>
      </div>
      <?php if ($payTypes): ?>
        <h2 class="mt">Record payment</h2>
        <p class="muted small-text">Enter a payment after the customer sends proof on WhatsApp. Payments cannot be edited afterwards.</p>
        <form method="post" novalidate>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="payment">
          <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
          <label>Type
            <select name="type">
              <?php foreach ($payTypes as $t): ?>
                <option value="<?= e($t) ?>" <?= $pv('type') === $t ? 'selected' : '' ?>><?= e(PaymentService::typeLabel($t)) ?></option>
              <?php endforeach; ?>
            </select>
          </label><?= field_error($payErrors, 'type') ?>
          <label>Amount (PKR)
            <input type="text" name="amount" inputmode="decimal" maxlength="13" value="<?= $pv('amount') ?>" required>
          </label><?= field_error($payErrors, 'amount') ?>
          <p class="hint">Advance still needed: <?= e(money(max(0, $pay['advance_required'] - $pay['advance_paid']))) ?> &middot; Balance due: <?= e(money($pay['balance_due'])) ?><?= $pay['refundable_left'] > 0 ? ' &middot; Refundable: ' . e(money($pay['refundable_left'])) : '' ?><?= $dep && $dep['extra_due'] > 0 ? ' &middot; Extra charges due: ' . e(money($dep['extra_due'])) : '' ?></p>
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
      <?php else: ?>
        <p class="muted small-text mt">No payments can be recorded at this stage.</p>
      <?php endif; ?>
    </section>

    <!-- 2. Confirm or reject -->
    <section class="panel act-panel" id="act-confirm" data-act="confirm" role="tabpanel">
      <?php if ($pending): ?>
        <h2>Confirm booking</h2>
        <?php if ($pay['advance_ok']): ?>
          <p class="muted small-text">The advance of <?= e(money($pay['advance_paid'])) ?> is recorded. Stock is re-checked and locked when you confirm.</p>
        <?php else: ?>
          <p class="muted small-text">Record the advance of <?= e(money($pay['advance_required'])) ?> in the Payment step first. Stock is locked only after the advance is received.</p>
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

        <h2 class="mt act-sub">Reject request</h2>
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
      <?php else: ?>
        <h2>Confirm booking</h2>
        <p class="muted small-text"><?= $doneConfirm
            ? 'This booking is confirmed' . ($b['confirmed_by_name'] ? ' by ' . e($b['confirmed_by_name']) : '') . '. Its items are locked for the event dates.'
            : 'This booking was ' . e(strtolower(BookingAdminService::statusLabel($status))) . ', so there is nothing to confirm.' ?></p>
      <?php endif; ?>
    </section>

    <!-- 3. Deliver -->
    <section class="panel act-panel" id="act-deliver" data-act="deliver" role="tabpanel">
      <h2>Delivery</h2>
      <?php if (isset($errors['deliver'])): ?><div class="alert error"><?= e($errors['deliver']) ?></div><?php endif; ?>
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
      <?php else: ?>
        <p class="muted small-text"><?= $doneDeliver ? 'The items have been handed over.' : ($ended ? 'This booking is closed, so nothing will be delivered.' : 'Delivery opens once the booking is confirmed.') ?></p>
      <?php endif; ?>
    </section>

    <!-- 4. Return -->
    <section class="panel act-panel" id="act-return" data-act="return" role="tabpanel">
      <h2>Return</h2>
      <?php if (isset($errors['returnpending'])): ?><div class="alert error"><?= e($errors['returnpending']) ?></div><?php endif; ?>
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
      <?php elseif (!$canReturnPending): ?>
        <p class="muted small-text"><?= $doneReturn ? 'The items were received back. See the return details on this page.' : ($ended ? 'This booking is closed, so no return is expected.' : 'Returns open after the items are delivered.') ?></p>
      <?php endif; ?>
    </section>

    <!-- 5. Security deposit -->
    <section class="panel act-panel" id="act-deposit" data-act="deposit" role="tabpanel">
      <h2>Security deposit</h2>
      <?php if ($dep): ?>
        <p class="muted small-text">Held separately. It is not rental income.</p>
        <dl class="quote">
          <div><dt>Required</dt><dd><?= e(money($dep['required'])) ?></dd></div>
          <div><dt>Held</dt><dd><?= e(money($dep['held'])) ?></dd></div>
          <?php if ($dep['has_return']): ?>
            <div><dt>Return charges</dt><dd><?= e(money($dep['charges'])) ?></dd></div>
            <div><dt><?= $dep['settled'] ? 'Deducted' : 'To deduct' ?></dt><dd><?= e(money($dep['deduct'])) ?></dd></div>
            <div><dt><?= $dep['settled'] ? 'Refunded' : 'To refund' ?></dt><dd><?= e(money($dep['refund'])) ?></dd></div>
            <?php if ($dep['extra_owed'] > 0): ?>
              <div class="after"><dt>Extra owed by customer</dt><dd><?= e(money($dep['extra_owed'])) ?></dd></div>
              <div class="after"><dt>Extra paid</dt><dd><?= e(money($dep['extra_paid'])) ?></dd></div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="after"><dt>Status</dt><dd><span class="pill <?= $dep['settled'] ? 'ok' : 'off' ?>"><?= e($dep['status_label']) ?></span></dd></div>
        </dl>
        <?php if ($dep['settled'] && $dep['extra_due'] > 0): ?>
          <div class="alert warning mt">The customer still owes <?= e(money($dep['extra_due'])) ?>. Record it in the Payment step as a damage charge or late fee payment.</div>
        <?php endif; ?>

        <?php if ($dep['can_settle']): ?>
          <h2 class="mt act-sub">Settle deposit</h2>
          <p class="muted small-text">
            <?= e(money($dep['deduct'])) ?> is deducted for the return charges<?= $dep['refund'] > 0 ? ' and ' . e(money($dep['refund'])) . ' is refunded to the customer' : '' ?>.
            <?= $dep['extra_owed'] > 0 ? 'The charges are higher than the deposit, so ' . e(money($dep['extra_owed'])) . ' stays owed. ' : '' ?>
            This can only be done once.
          </p>
          <?php if (isset($settleErrors['form'])): ?><div class="alert error"><?= e($settleErrors['form']) ?></div><?php endif; ?>
          <form method="post" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="settle">
            <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
            <?php if ($dep['refund'] > 0): ?>
              <label>Refund method
                <select name="method">
                  <?php foreach (PaymentService::METHODS as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $sv('method') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label><?= field_error($settleErrors, 'method') ?>
              <label>Reference (optional)
                <input type="text" name="reference_no" maxlength="80" value="<?= $sv('reference_no') ?>">
              </label><?= field_error($settleErrors, 'reference_no') ?>
              <label>Refunded on
                <input type="datetime-local" name="paid_at" max="<?= e($nowLocal) ?>" value="<?= $open === 'settle' ? $sv('paid_at') : e($nowLocal) ?>">
              </label><?= field_error($settleErrors, 'paid_at') ?>
              <label>Note (optional)
                <input type="text" name="notes" maxlength="255" value="<?= $sv('notes') ?>">
              </label><?= field_error($settleErrors, 'notes') ?>
              <?= field_error($settleErrors, 'amount') ?><?= field_error($settleErrors, 'type') ?>
            <?php endif; ?>
            <div class="cart-actions mt"><button class="btn" type="submit">Settle deposit</button></div>
          </form>
        <?php elseif (!$dep['settled'] && $b['status'] === 'returned' && !$dep['has_return']): ?>
          <p class="muted small-text">Record the return first.</p>
        <?php elseif (!$dep['settled'] && in_array($b['status'], ['confirmed', 'delivered', 'return_pending'], true)): ?>
          <p class="muted small-text">The deposit is settled after the items are received back.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted small-text">The security deposit is tracked once the booking is confirmed.</p>
      <?php endif; ?>
    </section>

    <!-- Cancel (only while a booking can still be cancelled) -->
    <?php if ($canCancel): ?>
      <section class="panel act-panel" id="act-more" data-act="more" role="tabpanel">
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
  </aside>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
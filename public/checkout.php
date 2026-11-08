<?php
require __DIR__ . '/../app/bootstrap.php';

// Guests can fill a cart, but must log in (or register) to submit. The cart survives login.
if (!Auth::user()) {
    $_SESSION['after_login'] = 'checkout.php';
    flash('warning', 'Log in or create an account to submit your booking. Your cart will be kept.');
    redirect('login.php');
}
$user = Auth::requireRole('customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach (CartService::clamp() as $note) {
        flash('warning', $note);
    }
}
$dates = CartService::dates();
$lines = CartService::lines();
if ($dates === null || !$lines) {
    flash('error', 'Your cart is empty. Add items before checking out.');
    redirect('cart.php');
}
$delivery = CartService::delivery();
$zone     = $delivery['method'] === 'delivery' ? PricingService::zone($delivery['zone']) : null;
if ($delivery['method'] === 'delivery' && $zone === null) {
    flash('error', 'Choose your delivery area before checking out.');
    redirect('cart.php');
}

$errors = [];
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    [$errors, $clean] = BookingService::validate($_POST, $delivery['method']);
    if (!$errors) {
        $result = BookingService::create($user['id'], $clean);
        if ($result['ok']) {
            flash('success', 'Your booking request has been submitted.');
            redirect('booking-confirmation.php?no=' . rawurlencode($result['booking_no']));
        }
        if (!empty($result['notes'])) {          // stock changed: back to the cart to review
            foreach ($result['notes'] as $note) {
                flash('warning', $note);
            }
            flash('error', $result['error']);
            redirect('cart.php');
        }
        $formError = $result['error'];
    }
}

$days  = AvailabilityService::rentalDays($dates['start'], $dates['end']);
$quote = PricingService::quote($lines, $days, $delivery['method'], $delivery['zone'], CartService::discountPercent());
$fmt   = PricingService::format($quote);
$st = Database::pdo()->prepare('SELECT phone FROM users WHERE user_id = ?');
$st->execute([$user['id']]);
$phone = (string) $st->fetchColumn();
$chosenType = is_string($_POST['event_type'] ?? null) ? $_POST['event_type'] : '';

$pageTitle = 'Checkout';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Checkout</span>
  <h1>Submit your booking request</h1>
  <p>The shop reviews every request. Nothing is charged now.</p>
</div>

<?php if ($formError): ?><div class="alert error"><?= e($formError) ?></div><?php endif; ?>

<div class="checkout">
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <h2>Event details</h2>
    <div class="row">
      <label>Type of event
        <select name="event_type" required>
          <option value="">Choose</option>
          <?php foreach (BookingService::EVENT_TYPES as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $chosenType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Number of guests (optional)
        <input type="number" name="guest_count" min="1" max="100000" value="<?= old('guest_count') ?>">
      </label>
    </div>
    <?= field_error($errors, 'event_type') ?><?= field_error($errors, 'guest_count') ?>
    <label>Event name (optional)
      <input type="text" name="event_name" maxlength="150" value="<?= old('event_name') ?>" placeholder="e.g. Ayesha and Ali's walima">
    </label><?= field_error($errors, 'event_name') ?>

    <?php if ($delivery['method'] === 'delivery'): ?>
      <label>Delivery address
        <input type="text" name="delivery_address" maxlength="255" value="<?= old('delivery_address') ?>" required
               placeholder="House, street, area">
      </label><?= field_error($errors, 'delivery_address') ?>
      <p class="muted small-text mt">Delivery area: <?= e($zone['city'] . ', ' . $zone['area']) ?>. <a href="<?= e(url('cart.php')) ?>">Change</a></p>
    <?php else: ?>
      <p class="muted mt">You chose to pick up your items from the shop. <a href="<?= e(url('cart.php')) ?>">Change</a></p>
    <?php endif; ?>

    <label>Notes for the shop (optional)
      <textarea name="customer_notes" rows="3" maxlength="1000"><?= old('customer_notes') ?></textarea>
    </label><?= field_error($errors, 'customer_notes') ?>

    <p class="muted small-text mt">The shop will contact you on <?= e($phone) ?>.</p>
    <button class="btn block" type="submit">Submit booking request</button>
  </form>

  <section class="panel quote-panel">
    <h2>Your order</h2>
    <p class="muted small-text"><?= e(date('j M Y', strtotime($dates['start']))) ?> to <?= e(date('j M Y', strtotime($dates['end']))) ?> &middot; <?= $days ?> day<?= $days === 1 ? '' : 's' ?></p>
    <ul class="mini-items">
      <?php foreach ($lines as $l): ?>
        <li><span><?= (int) $l['quantity'] ?> &times; <?= e($l['name']) ?></span><span><?= e(money($l['line_total'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <dl class="quote">
      <div><dt>Rental subtotal</dt><dd><?= e($fmt['subtotal']) ?></dd></div>
      <div><dt>Package discount</dt><dd><?= e($fmt['discount']) ?></dd></div>
      <div><dt>Delivery fee</dt><dd><?= e($fmt['delivery_fee']) ?></dd></div>
      <div class="total"><dt>Total</dt><dd><?= e($fmt['total']) ?></dd></div>
      <div class="after"><dt>Advance to confirm (<?= e(setting('advance_percent', '30')) ?>%)</dt><dd><?= e($fmt['advance']) ?></dd></div>
      <div class="after"><dt>Refundable security deposit</dt><dd><?= e($fmt['deposit']) ?></dd></div>
    </dl>
  </section>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

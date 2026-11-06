<?php
require __DIR__ . '/../app/bootstrap.php';   // guests can use the cart; login is needed at checkout

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = $str('action');

    if ($action === 'add') {
        // Posted from a catalog card. "return" is the catalog query string, so the customer lands back on the same results.
        $back = 'catalog.php' . (($qs = mb_substr($str('return'), 0, 500)) !== '' ? '?' . $qs : '');
        $id   = (int) $str('product_id');
        $err  = CartService::setDates($str('start'), $str('end')) ?? CartService::add($id, (int) $str('qty'));
        if ($err !== null) {
            flash('error', $err);
        } else {
            flash('success', 'Added to your cart.');
        }
        redirect($back);
    }

    if ($action === 'dates') {
        $err = CartService::setDates($str('start'), $str('end'));
        $err === null ? flash('success', 'Dates updated.') : flash('error', $err);
    } elseif ($action === 'delivery') {
        $err = CartService::setDelivery($str('method'), (int) $str('zone_id'));
        $err === null ? flash('success', 'Delivery option saved.') : flash('error', $err);
    } elseif ($action === 'remove') {
        CartService::remove((int) $str('product_id'));
    } elseif ($action === 'clear') {
        CartService::clear();
        flash('success', 'Your cart is empty.');
    } elseif ($action === 'update') {
        $qtys = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
        foreach ($qtys as $id => $q) {
            if (is_string($q) && ctype_digit((string) $id)) {
                $err = CartService::setQty((int) $id, (int) $q);
                if ($err !== null) {
                    flash('error', $err);
                }
            }
        }
    }
    redirect('cart.php');
}

// Re-check stock every time the cart is opened, then show what changed.
foreach (CartService::clamp() as $note) {
    flash('warning', $note);
}
$dates = CartService::dates();
$lines = CartService::lines();
$days  = $dates ? AvailabilityService::rentalDays($dates['start'], $dates['end']) : 0;
$delivery = CartService::delivery();
$zones    = PricingService::zones();
$quote    = PricingService::quote($lines, $days, $delivery['method'], $delivery['zone']);
$fmt      = PricingService::format($quote);
$catalogUrl = url('catalog.php') . ($dates ? '?' . http_build_query($dates) : '');

$pageTitle = 'Cart';
$scripts   = ['assets/js/cart.js'];
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <h1>Your cart</h1>
  <p>Quantities are limited to what is available for your event dates.</p>
</div>

<form method="post" class="cart-dates">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="dates">
  <label>Start date
    <input type="date" name="start" value="<?= e($dates['start'] ?? '') ?>" min="<?= e(date('Y-m-d')) ?>" required>
  </label>
  <label>End date
    <input type="date" name="end" value="<?= e($dates['end'] ?? '') ?>" min="<?= e(date('Y-m-d')) ?>" required>
  </label>
  <button class="btn ghost" type="submit"><?= $dates ? 'Change dates' : 'Set dates' ?></button>
  <?php if ($dates): ?>
    <span class="muted"><?= $days ?> rental day<?= $days === 1 ? '' : 's' ?></span>
  <?php endif; ?>
</form>

<?php if (!$lines): ?>
  <div class="panel empty">
    <h2>Your cart is empty</h2>
    <p><?= $dates ? 'Browse the catalog and add what you need.' : 'Set your event dates above, then add items from the catalog.' ?></p>
    <p class="mt"><a class="btn" href="<?= e($catalogUrl) ?>">Browse catalog</a></p>
  </div>
<?php else: ?>
  <form method="post" id="cart-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update">
  </form>

  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead>
        <tr><th>Item</th><th>Rent / day</th><th>Quantity</th><th class="num">Line total</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td data-label="Item">
              <strong><?= e($l['name']) ?></strong>
              <div class="muted small-text"><?= e($l['sku']) ?> &middot; <?= (int) $l['available'] ?> available</div>
            </td>
            <td data-label="Rent / day"><?= e(money($l['rent_per_day'])) ?></td>
            <td data-label="Quantity">
              <input class="qty" form="cart-form" type="number" name="qty[<?= (int) $l['product_id'] ?>]"
                     value="<?= (int) $l['quantity'] ?>" min="0" max="<?= (int) $l['available'] ?>"
                     aria-label="Quantity of <?= e($l['name']) ?>">
            </td>
            <td data-label="Line total" class="num"><?= e(money($l['line_total'])) ?></td>
            <td class="num">
              <button class="linkbtn" type="submit" form="rm-<?= (int) $l['product_id'] ?>">Remove</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($lines as $l): ?>
    <form method="post" id="rm-<?= (int) $l['product_id'] ?>" class="inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="product_id" value="<?= (int) $l['product_id'] ?>">
    </form>
  <?php endforeach; ?>

  <div class="cart-foot">
    <div class="cart-actions">
      <button class="btn ghost" type="submit" form="cart-form">Update quantities</button>
      <a class="btn ghost" href="<?= e($catalogUrl) ?>">Continue browsing</a>
      <form method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="clear">
        <button class="linkbtn" type="submit">Empty cart</button>
      </form>
    </div>
  </div>

  <div class="cart-extra">
    <form method="post" id="delivery-form" class="cart-delivery" data-quote-url="<?= e(url('api/quote.php')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delivery">
      <h2>Pickup or delivery</h2>
      <label>How would you like to receive your items?
        <select name="method">
          <option value="pickup" <?= $delivery['method'] === 'pickup' ? 'selected' : '' ?>>I will pick them up from the shop</option>
          <option value="delivery" <?= $delivery['method'] === 'delivery' ? 'selected' : '' ?>>Deliver to my event</option>
        </select>
      </label>
      <label id="zone-field">Delivery area
        <select name="zone_id">
          <option value="0">Choose an area</option>
          <?php foreach ($zones as $z): ?>
            <option value="<?= (int) $z['zone_id'] ?>" <?= $delivery['zone'] === (int) $z['zone_id'] ? 'selected' : '' ?>>
              <?= e($z['city'] . ', ' . $z['area'] . ' (' . money($z['fee']) . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="field-error" data-q-error <?= $quote['error'] === null ? 'hidden' : '' ?>><?= e((string) $quote['error']) ?></div>
      <button class="btn ghost small mt" type="submit" id="delivery-apply">Apply</button>
    </form>

    <section class="panel quote-panel">
      <h2>Cost summary</h2>
      <dl class="quote">
        <div><dt>Rental subtotal (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</dt><dd data-q="subtotal"><?= e($fmt['subtotal']) ?></dd></div>
        <div><dt>Package discount</dt><dd data-q="discount"><?= e($fmt['discount']) ?></dd></div>
        <div><dt>Delivery fee</dt><dd data-q="delivery_fee"><?= e($fmt['delivery_fee']) ?></dd></div>
        <div class="total"><dt>Total</dt><dd data-q="total"><?= e($fmt['total']) ?></dd></div>
        <div class="after"><dt>Advance to confirm (<?= e(setting('advance_percent', '30')) ?>%)</dt><dd data-q="advance"><?= e($fmt['advance']) ?></dd></div>
        <div class="after"><dt>Refundable security deposit</dt><dd data-q="deposit"><?= e($fmt['deposit']) ?></dd></div>
      </dl>
      <a class="btn block" href="<?= e(url('checkout.php')) ?>">Continue to checkout</a>
      <p class="muted small-text mt">The deposit is held separately and is not part of the total. It is returned after your items come back undamaged.</p>
    </section>
  </div>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
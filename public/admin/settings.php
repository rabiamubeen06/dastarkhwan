<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$errors = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($posted) {
    Csrf::enforce();
    [$errors, $clean] = SettingsService::validate($_POST);
    if (!$errors) {
        SettingsService::save($clean);
        flash('success', 'Settings saved. They apply to new booking requests.');
        redirect('admin/settings.php');
    }
}

$current = SettingsService::all();
$val = fn(string $k): string => e($posted && is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $current[$k]);

$pageTitle = 'Settings';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">System</span>
  <h1>Settings</h1>
  <p>Rules and contact details used across the shop. Changes apply to new booking requests; existing bookings keep their amounts.</p>
</div>

<form method="post" class="panel settings" novalidate>
  <?= Csrf::field() ?>

  <section class="set-sec first">
    <h2>Rental rules</h2>
    <div class="row">
      <div>
        <label>Buffer days
          <input type="text" name="buffer_days" inputmode="numeric" maxlength="2" value="<?= $val('buffer_days') ?>">
        </label><?= field_error($errors, 'buffer_days') ?>
        <p class="hint">Days kept free after an event for collection, washing and counting.</p>
      </div>
      <div>
        <label>Late fee per day (PKR)
          <input type="text" name="late_fee_per_day" inputmode="decimal" maxlength="10" value="<?= $val('late_fee_per_day') ?>">
        </label><?= field_error($errors, 'late_fee_per_day') ?>
        <p class="hint">Charged for each day items come back late.</p>
      </div>
    </div>
  </section>

  <section class="set-sec">
    <h2>Advance and deposit</h2>
    <div class="row">
      <div>
        <label>Advance (% of total)
          <input type="text" name="advance_percent" inputmode="decimal" maxlength="6" value="<?= $val('advance_percent') ?>">
        </label><?= field_error($errors, 'advance_percent') ?>
        <p class="hint">Paid before a booking is confirmed and stock is locked.</p>
      </div>
      <div>
        <label>Security deposit (% of replacement value)
          <input type="text" name="deposit_percent" inputmode="decimal" maxlength="6" value="<?= $val('deposit_percent') ?>">
        </label><?= field_error($errors, 'deposit_percent') ?>
        <p class="hint">Held separately and refunded after return, minus any damage.</p>
      </div>
    </div>
    <div class="row">
      <div>
        <label>Minimum deposit (PKR)
          <input type="text" name="min_deposit" inputmode="decimal" maxlength="10" value="<?= $val('min_deposit') ?>">
        </label><?= field_error($errors, 'min_deposit') ?>
        <p class="hint">Applies when the percentage comes to less than this amount.</p>
      </div>
    </div>
  </section>

  <section class="set-sec">
    <h2>Contact and payment</h2>
    <label>Shop WhatsApp number
      <input type="text" name="shop_whatsapp" inputmode="tel" maxlength="20" placeholder="03001234567" value="<?= $val('shop_whatsapp') ?>">
    </label><?= field_error($errors, 'shop_whatsapp') ?>
    <p class="hint">Shown on the booking confirmation page. Customers use it to send payment proof.</p>
    <label>Payment instructions
      <textarea name="payment_instructions" rows="6" maxlength="1000"><?= $val('payment_instructions') ?></textarea>
    </label><?= field_error($errors, 'payment_instructions') ?>
    <p class="hint">Accepted methods and account details, for example bank name, account title, IBAN, Easypaisa and JazzCash numbers.</p>
  </section>

  <div class="cart-actions mt">
    <button class="btn" type="submit">Save settings</button>
    <a class="btn ghost" href="<?= e(url('admin/dashboard.php')) ?>">Cancel</a>
  </div>
</form>
<?php require APP_PATH . '/views/footer.php'; ?>

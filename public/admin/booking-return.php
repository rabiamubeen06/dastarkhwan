<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$id = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : (is_string($_POST['booking_id'] ?? null) ? $_POST['booking_id'] : 0));
$b  = $id > 0 ? BookingAdminService::find($id) : null;
if ($b === null) {
    flash('error', 'That booking was not found.');
    redirect('admin/bookings.php');
}
if (!in_array($b['status'], ReturnService::RECEIVABLE, true)) {
    flash('error', 'Items can only be received for a delivered or return-pending booking.');
    redirect('admin/booking.php?id=' . $id);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $res = ReturnService::save($id, $admin['id'], $_POST);
    if ($res['ok']) {
        flash('success', 'Return saved. The booking is now marked as returned.');
        redirect('admin/booking.php?id=' . $id);
    }
    $errors = $res['errors'];
}

$items   = ReturnService::items($id);
$posted  = $_SERVER['REQUEST_METHOD'] === 'POST';
$cell    = function (string $k, int $itemId, string $default) use ($posted): string {
    if ($posted && is_array($_POST[$k] ?? null) && is_string($_POST[$k][$itemId] ?? null)) {
        return e(trim($_POST[$k][$itemId]));
    }
    return e($default);
};
$val     = fn(string $k, string $default = ''): string => $posted && is_string($_POST[$k] ?? null) ? e(trim($_POST[$k])) : e($default);
$nowLocal = date('Y-m-d\TH:i');
$fmt     = fn(string $d): string => date('j M Y', strtotime($d));
$lateFee = setting('late_fee_per_day', '500');

$pageTitle = 'Receive items ' . $b['booking_no'];
$scripts = ['assets/js/return.js'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('admin/booking.php?id=' . $id)) ?>">&larr; Booking <?= e($b['booking_no']) ?></a></p>

<div class="page-head">
  <span class="eyebrow">Orders</span>
  <h1>Receive items</h1>
  <p class="muted"><?= e($b['full_name']) ?> &middot; rental <?= e($fmt($b['start_date'])) ?> to <?= e($fmt($b['end_date'])) ?>. Count every unit as returned, damaged or missing.</p>
</div>

<?php if (isset($errors['form'])): ?><div class="alert error"><?= e($errors['form']) ?></div><?php endif; ?>

<form method="post" novalidate data-confirm="Save this return? It cannot be edited afterwards.">
  <?= Csrf::field() ?>
  <input type="hidden" name="booking_id" value="<?= (int) $id ?>">

  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Item</th><th>Booked</th><th>Returned</th><th>Damaged</th><th>Missing</th><th>Charge (PKR)</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): $iid = (int) $it['booking_item_id']; ?>
          <tr data-cost="<?= e((string) $it['replacement_cost']) ?>">
            <td data-label="Item"><strong><?= e($it['name']) ?></strong>
              <div class="muted small-text"><?= e($it['sku']) ?> &middot; replacement <?= e(money($it['replacement_cost'])) ?> each</div>
              <?= field_error($errors, 'item_' . $iid) ?></td>
            <td data-label="Booked"><?= (int) $it['quantity'] ?></td>
            <td data-label="Returned"><input class="qty" type="text" inputmode="numeric" name="ret[<?= $iid ?>]" value="<?= $cell('ret', $iid, (string) $it['quantity']) ?>" aria-label="Returned"></td>
            <td data-label="Damaged"><input class="qty" type="text" inputmode="numeric" name="dmg[<?= $iid ?>]" value="<?= $cell('dmg', $iid, '0') ?>" aria-label="Damaged" data-dmg></td>
            <td data-label="Missing"><input class="qty" type="text" inputmode="numeric" name="mis[<?= $iid ?>]" value="<?= $cell('mis', $iid, '0') ?>" aria-label="Missing" data-mis></td>
            <td data-label="Charge (PKR)"><input class="qty wide" type="text" inputmode="decimal" name="chg[<?= $iid ?>]" value="<?= $cell('chg', $iid, '') ?>" placeholder="Auto: PKR 0" aria-label="Charge" data-chg></td>
            <td data-label="Remarks"><input class="qty remarks" type="text" name="rem[<?= $iid ?>]" maxlength="255" value="<?= $cell('rem', $iid, '') ?>" aria-label="Remarks"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Charge: leave blank to charge the replacement cost for each damaged or missing unit, or type your own amount.</p>

  <section class="panel mt">
    <h2>Return details</h2>
    <label>Returned on
      <input type="datetime-local" name="returned_at" max="<?= e($nowLocal) ?>" value="<?= $val('returned_at', $nowLocal) ?>">
    </label><?= field_error($errors, 'returned_at') ?>
    <label>Late days (optional)
      <input type="text" name="late_days" inputmode="numeric" maxlength="3" value="<?= $val('late_days') ?>" placeholder="Automatic">
    </label><?= field_error($errors, 'late_days') ?>
    <p class="hint">Items were due back on <?= e($fmt($b['end_date'])) ?>. The late fee is <?= e(money($lateFee)) ?> for each day after that. Leave blank for automatic, or enter 0 to waive it.</p>
    <label>Note (optional)
      <input type="text" name="notes" maxlength="255" value="<?= $val('notes') ?>">
    </label><?= field_error($errors, 'notes') ?>
    <div class="cart-actions mt"><button class="btn" type="submit">Save return</button>
      <a class="btn ghost" href="<?= e(url('admin/booking.php?id=' . $id)) ?>">Back</a></div>
  </section>
</form>
<?php require APP_PATH . '/views/footer.php'; ?>

<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$id = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : 0);
$product = $id > 0 ? ProductService::find($id) : null;
if ($product === null) {
    flash('error', 'That product was not found.');
    redirect('admin/inventory.php');
}

$errors = [];
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    [$errors, $clean] = InventoryService::validate($_POST);
    if (!$errors) {
        $formError = (string) InventoryService::adjust($id, $clean['reason'], $clean['change'], (int) $admin['id']);
        if ($formError === '') {
            flash('success', 'Stock updated.');
            redirect('admin/inventory-product.php?id=' . $id);
        }
    }
}

$s       = InventoryService::summary($id);
$holds   = InventoryService::holds($id);
$history = InventoryService::history($id);
$fmt     = fn(string $d): string => date('j M Y', strtotime($d));
$chosen  = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';

$pageTitle = 'Stock: ' . $product['name'];
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('admin/inventory.php')) ?>">&larr; Inventory calendar</a></p>
<div class="page-head head-row">
  <div>
    <span class="eyebrow">Catalog</span>
    <h1><?= e($product['name']) ?></h1>
    <p><?= e($product['sku']) ?><?= (int) $product['is_active'] === 1 ? '' : ' &middot; hidden from the catalog' ?></p>
  </div>
  <a class="btn ghost" href="<?= e(url('admin/product-form.php?id=' . $id)) ?>">Edit product</a>
</div>

<div class="split">
  <div>
    <section class="panel">
      <h2>Adjust stock</h2>
      <?php if ($formError !== ''): ?><div class="alert error"><?= e($formError) ?></div><?php endif; ?>
      <form method="post" novalidate>
        <?= Csrf::field() ?>
        <label>Reason
          <select name="reason" required>
            <option value="">Choose</option>
            <?php foreach (InventoryService::REASONS as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $chosen === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label><?= field_error($errors, 'reason') ?>
        <label>Units
          <input type="text" name="change" inputmode="numeric" value="<?= e(is_string($_POST['change'] ?? null) ? $_POST['change'] : '') ?>" placeholder="10 or -3" required>
        </label><?= field_error($errors, 'change') ?>
        <p class="hint">Restock adds new units to the units you own. A correction adds or removes units after a stock count (use a minus sign to remove).
          For damaged, lost or repair, a positive number takes units out of stock and a negative number puts them back, for example when a repair is finished.</p>
        <button class="btn mt" type="submit">Save adjustment</button>
      </form>
    </section>

    <h2 class="mt">History</h2>
    <?php if (!$history): ?>
      <p class="muted">No adjustments yet.</p>
    <?php else: ?>
      <div class="cart-table-wrap">
        <table class="cart-table">
          <thead><tr><th>Date</th><th>Reason</th><th>Units</th><th>By</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td data-label="Date"><?= e(date('j M Y, H:i', strtotime((string) $h['created_at']))) ?></td>
                <td data-label="Reason"><?= e(InventoryService::reasonLabel($h['reason'])) ?><?php if ($h['booking_no']): ?>
                  <div class="muted small-text">Return of <a href="<?= e(url('admin/booking.php?id=' . (int) $h['booking_id'])) ?>"><?= e($h['booking_no']) ?></a></div><?php endif; ?></td>
                <td data-label="Units"><?= e(((int) $h['change_qty'] > 0 ? '+' : '') . (int) $h['change_qty']) ?></td>
                <td data-label="By"><?= e((string) ($h['full_name'] ?? 'Staff')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <section class="panel quote-panel">
      <h2>Stock position</h2>
      <dl class="quote">
        <div><dt>Units owned</dt><dd><?= (int) $s['total'] ?></dd></div>
        <div><dt>Damaged</dt><dd><?= max(0, (int) $s['damaged']) ?></dd></div>
        <div><dt>Lost</dt><dd><?= max(0, (int) $s['lost']) ?></dd></div>
        <div><dt>Under repair</dt><dd><?= max(0, (int) $s['repair']) ?></dd></div>
        <div class="total"><dt>In stock</dt><dd><?= (int) $s['usable'] ?></dd></div>
      </dl>
      <p class="muted small-text mt">&ldquo;In stock&rdquo; is before any bookings. The calendar subtracts bookings and buffer days.</p>
    </section>

    <h2 class="mt">Held by bookings</h2>
    <?php if (!$holds): ?>
      <p class="muted">No confirmed bookings hold this product.</p>
    <?php else: ?>
      <ul class="mini-items">
        <?php foreach ($holds as $h): ?>
          <li>
            <span><a href="<?= e(url('admin/booking.php?id=' . (int) $h['booking_id'])) ?>"><?= e($h['booking_no']) ?></a>
              <span class="muted small-text"><?= e($fmt($h['start_date'])) ?> &ndash; <?= e($fmt($h['end_date'])) ?></span></span>
            <span><?= (int) $h['quantity'] ?> units</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

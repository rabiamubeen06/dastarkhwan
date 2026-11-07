<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$id  = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : 0);
$pkg = $id > 0 ? PackageService::findTemplate($id) : null;
if ($id > 0 && $pkg === null) {
    flash('error', 'That package was not found.');
    redirect('admin/packages.php');
}

$products = PackageService::formProducts($pkg ? $id : null);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$errors = [];
if ($posted) {
    Csrf::enforce();
    [$errors, $clean] = PackageService::validate($_POST, $pkg ? $id : null);
    if (!$errors) {
        PackageService::saveTemplate($clean, $pkg ? $id : null, (int) $admin['id']);
        flash('success', $pkg ? 'Package updated.' : 'Package added.');
        redirect('admin/packages.php');
    }
}

$val = function (string $k) use ($posted, $pkg): string {
    if ($posted) {
        return e(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    }
    return e((string) ($pkg[$k] ?? ($k === 'discount_percent' ? '0' : '')));
};
$qtyOf = function (int $pid) use ($posted, $pkg): string {
    if ($posted) {
        $q = $_POST['qty'][$pid] ?? '';
        return e(is_string($q) ? $q : '');
    }
    return isset($pkg['qty'][$pid]) ? (string) (int) $pkg['qty'][$pid] : '';
};
// A hidden product that is already in the package still shows its saved quantity.
if ($pkg && !$posted) {
    foreach ($pkg['items'] as $it) {
        $pkg['qty'][(int) $it['product_id']] = (int) $it['quantity'];
    }
}

$pageTitle = $pkg ? 'Edit package' : 'Add package';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <h1><?= $pkg ? 'Edit package' : 'Add package' ?></h1>
  <p>Set the quantity of each product the package includes. Leave the others blank.</p>
</div>

<form method="post" class="form-narrow" novalidate>
  <?= Csrf::field() ?>
  <label>Package name
    <input type="text" name="name" maxlength="120" value="<?= $val('name') ?>" placeholder="Wedding 100 Guests" required>
  </label><?= field_error($errors, 'name') ?>

  <label>Description (optional)
    <input type="text" name="description" maxlength="255" value="<?= $val('description') ?>">
  </label><?= field_error($errors, 'description') ?>

  <div class="row">
    <label>Guests (optional)
      <input type="text" name="guest_count" inputmode="numeric" value="<?= $val('guest_count') ?>" placeholder="100">
    </label>
    <label>Discount (%)
      <input type="text" name="discount_percent" inputmode="decimal" value="<?= $val('discount_percent') ?>">
    </label>
  </div><?= field_error($errors, 'guest_count') ?><?= field_error($errors, 'discount_percent') ?>
  <p class="muted small-text mt">The discount applies to the rent of the package items only, up to <?= (int) PackageService::MAX_DISCOUNT ?>%.</p>

  <h2 class="mt">Items</h2>
  <?= field_error($errors, 'items') ?>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Product</th><th>Rent / day</th><th>Owned</th><th>Quantity</th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): $pid = (int) $p['product_id']; ?>
          <tr>
            <td data-label="Product"><strong><?= e($p['name']) ?></strong>
              <div class="muted small-text"><?= e($p['sku']) ?> &middot; <?= e($p['category']) ?><?= (int) $p['is_active'] === 1 ? '' : ' &middot; hidden from catalog' ?></div></td>
            <td data-label="Rent / day"><?= e(money($p['rent_per_day'])) ?></td>
            <td data-label="Owned"><?= (int) $p['total_quantity'] ?></td>
            <td data-label="Quantity">
              <input class="qty" type="text" inputmode="numeric" name="qty[<?= $pid ?>]" value="<?= $posted ? $qtyOf($pid) : e((string) ($pkg['qty'][$pid] ?? '')) ?>"
                     aria-label="Quantity of <?= e($p['name']) ?>">
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="cart-actions mt">
    <button class="btn" type="submit"><?= $pkg ? 'Save changes' : 'Add package' ?></button>
    <a class="btn ghost" href="<?= e(url('admin/packages.php')) ?>">Cancel</a>
  </div>
</form>
<?php require APP_PATH . '/views/footer.php'; ?>
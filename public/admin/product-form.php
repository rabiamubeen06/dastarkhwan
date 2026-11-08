<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$id = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : 0);
$product = $id > 0 ? ProductService::find($id) : null;
if ($id > 0 && $product === null) {
    flash('error', 'That product was not found.');
    redirect('admin/products.php');
}
$categories = CategoryService::all();
if (!$categories) {
    flash('warning', 'Add a category first, then you can add products.');
    redirect('admin/categories.php');
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$errors = [];
if ($posted) {
    Csrf::enforce();
    [$errors, $clean] = ProductService::validate($_POST, $product ? $id : null);
    if (!$errors) {
        $res = ProductService::save($clean, $product ? $id : null);
        $errors = $res['errors'];
        if (!$errors) {
            flash('success', $product ? 'Product updated.' : 'Product added.');
            redirect('admin/products.php');
        }
    }
}

// Field values: what was just posted, else the saved product, else sensible defaults for a new one.
$defaults = ['min_order_qty' => '1', 'total_quantity' => '0'];
$val = function (string $k) use ($posted, $product, $defaults): string {
    if ($posted) {
        return e(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    }
    return e((string) ($product[$k] ?? $defaults[$k] ?? ''));
};
$active = $posted ? ($_POST['is_active'] ?? '') === '1' : ($product ? (int) $product['is_active'] === 1 : true);
$chosenCat = $posted ? (int) ($_POST['category_id'] ?? 0) : (int) ($product['category_id'] ?? 0);

$pageTitle = $product ? 'Edit product' : 'Add product';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Catalog</span>
  <h1><?= $product ? 'Edit product' : 'Add product' ?></h1>
  <p><?= $product ? e($product['sku']) : 'Fill in the details customers will see in the catalog.' ?></p>
</div>

<form method="post" class="form-narrow" novalidate>
  <?= Csrf::field() ?>

  <label>Name
    <input type="text" name="name" maxlength="150" value="<?= $val('name') ?>" required>
  </label><?= field_error($errors, 'name') ?>

  <div class="row">
    <label>SKU
      <input type="text" name="sku" maxlength="40" value="<?= $val('sku') ?>" placeholder="PL-004" required>
    </label>
    <label>Category
      <select name="category_id" required>
        <option value="0">Choose</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['category_id'] ?>" <?= $chosenCat === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div><?= field_error($errors, 'sku') ?><?= field_error($errors, 'category_id') ?>

  <label>Description (optional)
    <textarea name="description" rows="3" maxlength="2000"><?= $val('description') ?></textarea>
  </label><?= field_error($errors, 'description') ?>

  <div class="row">
    <label>Material (optional)
      <input type="text" name="material" maxlength="60" value="<?= $val('material') ?>" placeholder="Porcelain">
    </label>
    <label>Image link (optional)
      <input type="text" name="image_url" maxlength="255" value="<?= $val('image_url') ?>" placeholder="https://...">
    </label>
  </div><?= field_error($errors, 'material') ?><?= field_error($errors, 'image_url') ?>

  <div class="row">
    <label>Rent per day (PKR)
      <input type="text" name="rent_per_day" inputmode="decimal" value="<?= $val('rent_per_day') ?>" required>
    </label>
    <label>Replacement cost (PKR)
      <input type="text" name="replacement_cost" inputmode="decimal" value="<?= $val('replacement_cost') ?>" required>
    </label>
  </div><?= field_error($errors, 'rent_per_day') ?><?= field_error($errors, 'replacement_cost') ?>
  <p class="muted small-text mt">Replacement cost sets the security deposit and damage charges.</p>

  <div class="row">
    <label>Total quantity owned
      <input type="text" name="total_quantity" inputmode="numeric" value="<?= $val('total_quantity') ?>" required>
    </label>
    <label>Minimum order quantity
      <input type="text" name="min_order_qty" inputmode="numeric" value="<?= $val('min_order_qty') ?>" required>
    </label>
  </div><?= field_error($errors, 'total_quantity') ?><?= field_error($errors, 'min_order_qty') ?>
  <p class="muted small-text mt">Customers can rent what is left after confirmed bookings. Lowering the total below units already booked can cause shortages.</p>

  <label class="check">
    <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
    Visible in the catalog
  </label>

  <div class="cart-actions mt">
    <button class="btn" type="submit"><?= $product ? 'Save changes' : 'Add product' ?></button>
    <a class="btn ghost" href="<?= e(url('admin/products.php')) ?>">Cancel</a>
  </div>
</form>
<?php require APP_PATH . '/views/footer.php'; ?>

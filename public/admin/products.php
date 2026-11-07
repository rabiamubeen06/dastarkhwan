<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

// Hide or show a product (products are never deleted: old bookings refer to them).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $id = (int) ($_POST['product_id'] ?? 0);
    $on = ($_POST['active'] ?? '') === '1';
    if (ProductService::find($id) !== null) {
        ProductService::setActive($id, $on);
        flash('success', $on ? 'Product is visible in the catalog again.' : 'Product hidden from the catalog.');
    }
    $qs = preg_replace('/[^\w=&%.+\-]/', '', is_string($_POST['return'] ?? null) ? mb_substr($_POST['return'], 0, 300) : '') ?? '';
    redirect('admin/products.php' . ($qs !== '' ? '?' . $qs : ''));
}

$get = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$q      = mb_substr($get('q'), 0, 60);
$cat    = (int) $get('category');
$status = in_array($get('status'), ['active', 'inactive'], true) ? $get('status') : '';
$result = ProductService::search($q, $cat, $status, max(1, (int) $get('page')));
$categories = CategoryService::all();

$link = function (array $over = []) use ($q, $cat, $status): string {
    $p = array_filter(array_merge(['q' => $q, 'category' => $cat, 'status' => $status], $over), fn($v) => $v !== '' && $v !== 0);
    return url('admin/products.php') . ($p ? '?' . http_build_query($p) : '');
};
$returnQs = (string) ($_SERVER['QUERY_STRING'] ?? '');

$pageTitle = 'Products';
require APP_PATH . '/views/header.php';
?>
<div class="page-head head-row">
  <div>
    <h1>Products</h1>
    <p>Rates, replacement cost and stock for everything you rent out.</p>
  </div>
  <a class="btn" href="<?= e(url('admin/product-form.php')) ?>">Add product</a>
</div>

<form method="get" class="toolbar">
  <label class="grow">Search
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Name or SKU">
  </label>
  <label>Category
    <select name="category">
      <option value="0">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['category_id'] ?>" <?= $cat === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="">All</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Visible</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Hidden</option>
    </select>
  </label>
  <button class="btn ghost" type="submit">Filter</button>
</form>

<?php if (!$result['rows']): ?>
  <div class="panel empty">
    <h2>No products found</h2>
    <p><?= ($q !== '' || $cat > 0 || $status !== '') ? 'Try removing some filters.' : 'Add your first product to get started.' ?></p>
  </div>
<?php else: ?>
  <p class="muted result-count"><?= (int) $result['total'] ?> product<?= $result['total'] === 1 ? '' : 's' ?></p>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Product</th><th>Category</th><th>Rent / day</th><th>Stock</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $p): ?>
          <tr>
            <td data-label="Product"><strong><?= e($p['name']) ?></strong><div class="muted small-text"><?= e($p['sku']) ?></div></td>
            <td data-label="Category"><?= e($p['category']) ?></td>
            <td data-label="Rent / day"><?= e(money($p['rent_per_day'])) ?></td>
            <td data-label="Stock"><?= (int) $p['total_quantity'] ?></td>
            <td data-label="Status">
              <?= (int) $p['is_active'] === 1 ? '<span class="status on">Visible</span>' : '<span class="status off">Hidden</span>' ?>
            </td>
            <td class="num">
              <a href="<?= e(url('admin/product-form.php?id=' . (int) $p['product_id'])) ?>">Edit</a>
              &nbsp;<a href="<?= e(url('admin/inventory-product.php?id=' . (int) $p['product_id'])) ?>">Stock</a>
              &nbsp;<form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                <input type="hidden" name="active" value="<?= (int) $p['is_active'] === 1 ? '0' : '1' ?>">
                <input type="hidden" name="return" value="<?= e($returnQs) ?>">
                <button class="linkbtn" type="submit"><?= (int) $p['is_active'] === 1 ? 'Hide' : 'Show' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($result['pages'] > 1): ?>
    <div class="pager">
      <?php if ($result['page'] > 1): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
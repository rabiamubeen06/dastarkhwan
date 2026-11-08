<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$editId = (int) (is_string($_GET['edit'] ?? null) ? $_GET['edit'] : 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = $str('action');
    $id = (int) $str('category_id');

    if ($action === 'delete') {
        $err = CategoryService::delete($id);
        $err === null ? flash('success', 'Category deleted.') : flash('error', $err);
        redirect('admin/categories.php');
    }
    if ($action === 'save') {
        $editId = $id;   // keep the form in edit mode if validation fails
        [$errors, $clean] = CategoryService::validate($_POST, $id > 0 ? $id : null);
        if (!$errors) {
            $res = CategoryService::save($clean, $id > 0 ? $id : null);
            $errors = $res['errors'];
            if (!$errors) {
                flash('success', $id > 0 ? 'Category updated.' : 'Category added.');
                redirect('admin/categories.php');
            }
        }
    }
}

$editing = $editId > 0 ? CategoryService::find($editId) : null;
if ($editId > 0 && $editing === null) {
    flash('error', 'That category was not found.');
    redirect('admin/categories.php');
}
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = fn(string $k): string => e($posted ? $str($k) : (string) ($editing[$k] ?? ($k === 'sort_order' ? '0' : '')));

$categories = CategoryService::all();
$pageTitle = 'Categories';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Catalog</span>
  <h1>Categories</h1>
  <p>Group your products. Customers see these as tabs in the catalog.</p>
</div>

<div class="split">
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Category</th><th>Order</th><th>Products</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td data-label="Category">
              <strong><?= e($c['name']) ?></strong>
              <?php if ($c['description']): ?><div class="muted small-text"><?= e($c['description']) ?></div><?php endif; ?>
            </td>
            <td data-label="Order"><?= (int) $c['sort_order'] ?></td>
            <td data-label="Products"><?= (int) $c['products'] ?></td>
            <td class="num">
              <a href="<?= e(url('admin/categories.php?edit=' . (int) $c['category_id'])) ?>">Edit</a>
              <?php if ((int) $c['products'] === 0): ?>
                &nbsp;<form method="post" class="inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="category_id" value="<?= (int) $c['category_id'] ?>">
                  <button class="linkbtn" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?><tr><td colspan="4" class="muted">No categories yet. Add the first one.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <section class="panel">
    <h2><?= $editing ? 'Edit category' : 'Add category' ?></h2>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="category_id" value="<?= $editing ? (int) $editing['category_id'] : 0 ?>">
      <label>Name
        <input type="text" name="name" maxlength="80" value="<?= $val('name') ?>" required>
      </label><?= field_error($errors, 'name') ?>
      <label>Description (optional)
        <input type="text" name="description" maxlength="255" value="<?= $val('description') ?>">
      </label><?= field_error($errors, 'description') ?>
      <label>Display order
        <input type="text" name="sort_order" inputmode="numeric" maxlength="5" value="<?= $val('sort_order') ?>">
      </label><?= field_error($errors, 'sort_order') ?>
      <p class="muted small-text mt">Lower numbers appear first.</p>
      <div class="cart-actions mt">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add category' ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="<?= e(url('admin/categories.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

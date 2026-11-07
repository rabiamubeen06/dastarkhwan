<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

// Delete a template (past bookings keep their prices; their package link is cleared).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $id = is_string($_POST['package_id'] ?? null) ? (int) $_POST['package_id'] : 0;
    PackageService::deleteTemplate($id) ? flash('success', 'Package deleted.') : flash('error', 'That package was not found.');
    redirect('admin/packages.php');
}

$packages = PackageService::templates();
$pct = fn($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

$pageTitle = 'Packages';
$scripts   = ['assets/js/return.js'];
require APP_PATH . '/views/header.php';
?>
<div class="page-head head-row">
  <div>
    <h1>Packages</h1>
    <p>Ready-made template packages customers can add to their cart, with an optional discount.</p>
  </div>
  <a class="btn" href="<?= e(url('admin/package-form.php')) ?>">Add package</a>
</div>

<?php if (!$packages): ?>
  <div class="panel empty"><h2>No template packages</h2><p>Add one, for example &ldquo;Wedding 100 Guests&rdquo;.</p></div>
<?php else: ?>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Package</th><th>Guests</th><th>Items</th><th>Rent / day</th><th>Discount</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($packages as $p): ?>
          <tr>
            <td data-label="Package"><strong><?= e($p['name']) ?></strong>
              <?php if ($p['description']): ?><div class="muted small-text"><?= e($p['description']) ?></div><?php endif; ?></td>
            <td data-label="Guests"><?= $p['guest_count'] ? (int) $p['guest_count'] : '&ndash;' ?></td>
            <td data-label="Items"><?= count($p['items']) ?> product<?= count($p['items']) === 1 ? '' : 's' ?> (<?= (int) $p['units'] ?> pieces)</td>
            <td data-label="Rent / day"><?= e(money($p['per_day'])) ?></td>
            <td data-label="Discount"><?= (float) $p['discount_percent'] > 0 ? '<span class="pill ok">' . e($pct($p['discount_percent'])) . '%</span>' : '&ndash;' ?></td>
            <td class="num">
              <a href="<?= e(url('admin/package-form.php?id=' . (int) $p['package_id'])) ?>">Edit</a>
              &nbsp;<form method="post" class="inline" data-confirm="Delete this package? Past bookings are not affected.">
                <?= Csrf::field() ?>
                <input type="hidden" name="package_id" value="<?= (int) $p['package_id'] ?>">
                <button class="linkbtn" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
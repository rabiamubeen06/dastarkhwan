<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('admin');
$pageTitle = 'Admin dashboard';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <p>Signed in as <?= e($user['name']) ?>.</p>
</div>
<div class="panel">
  <h2>Manage your shop</h2>
  <p>Deliveries, returns and pending requests will appear here as features are added.</p>
  <p class="mt">
    <a class="btn" href="<?= e(url('admin/bookings.php')) ?>">Bookings</a>
    <a class="btn ghost" href="<?= e(url('admin/products.php')) ?>">Products</a>
    <a class="btn ghost" href="<?= e(url('admin/categories.php')) ?>">Categories</a>
    <a class="btn ghost" href="<?= e(url('admin/delivery-zones.php')) ?>">Delivery zones</a>
    <a class="btn ghost" href="<?= e(url('admin/settings.php')) ?>">Settings</a>
  </p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
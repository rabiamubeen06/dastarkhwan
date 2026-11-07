<?php
$u = Auth::user();
$layout = $layout ?? 'app';
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$onDash = str_ends_with($script, 'dashboard.php');
$onCatalog = str_ends_with($script, 'catalog.php');
$onCart = str_ends_with($script, 'cart.php');
$cartCount = CartService::count();
$isAdmin = ($u['role'] ?? '') === 'admin';
$onProducts = str_contains($script, '/admin/product');
$onCategories = str_contains($script, '/admin/categories');
$onPackages = str_ends_with($script, '/packages.php') && !str_contains($script, '/admin/');
$onAdminPackages = str_contains($script, '/admin/package');
$onZones = str_contains($script, '/admin/delivery-zones');
$onSettings = str_contains($script, '/admin/settings');
$onBookings = str_contains($script, '/admin/booking');
$onMyBookings = str_contains($script, '/customer/booking') || str_contains($script, '/customer/invoice') || str_ends_with($script, 'booking-confirmation.php');
$pendingCount = $isAdmin ? BookingAdminService::pendingCount() : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Welcome') ?> | دسترخوان</title>
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/favicon.svg')) ?>">
</head>
<body>
<?php if ($layout === 'auth'): ?>
<div class="auth-shell">
  <aside class="auth-aside">
    <a class="brand" href="<?= e(url('/')) ?>"><?= logo_svg(28) ?><?= brand_ur() ?></a>
    <div class="plate on-dark" aria-hidden="true"></div>
    <p class="aside-text">Crockery for weddings, daawats and every gathering.</p>
  </aside>
  <main class="auth-main"><div class="auth-card">
<?php else: ?>
<header class="top"><div class="top-in">
  <a class="brand" href="<?= e(url('/')) ?>"><?= logo_svg(26) ?><?= brand_ur() ?></a>
  <nav>
    <a href="<?= e(url('catalog.php')) ?>" class="<?= $onCatalog ? 'active' : '' ?>">Catalog</a>
    <?php if ($isAdmin): ?>
      <a href="<?= e(url('admin/bookings.php')) ?>" class="<?= $onBookings ? 'active' : '' ?>">Bookings<?php if ($pendingCount > 0): ?> <span class="badge"><?= $pendingCount ?></span><?php endif; ?></a>
      <a href="<?= e(url('admin/products.php')) ?>" class="<?= $onProducts ? 'active' : '' ?>">Products</a>
      <a href="<?= e(url('admin/packages.php')) ?>" class="<?= $onAdminPackages ? 'active' : '' ?>">Packages</a>
      <a href="<?= e(url('admin/categories.php')) ?>" class="<?= $onCategories ? 'active' : '' ?>">Categories</a>
      <a href="<?= e(url('admin/delivery-zones.php')) ?>" class="<?= $onZones ? 'active' : '' ?>">Delivery</a>
      <a href="<?= e(url('admin/settings.php')) ?>" class="<?= $onSettings ? 'active' : '' ?>">Settings</a>
    <?php else: ?>
      <a href="<?= e(url('packages.php')) ?>" class="<?= $onPackages ? 'active' : '' ?>">Packages</a>
      <?php if ($u): ?><a href="<?= e(url('customer/bookings.php')) ?>" class="<?= $onMyBookings ? 'active' : '' ?>">Bookings</a><?php endif; ?>
      <a href="<?= e(url('cart.php')) ?>" class="<?= $onCart ? 'active' : '' ?>">Cart<?php if ($cartCount > 0): ?> <span class="badge"><?= $cartCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <?php if ($u): ?>
      <a href="<?= e(url(Auth::homeUrl())) ?>" class="<?= $onDash ? 'active' : '' ?>">Dashboard</a>
      <div class="user">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
        <span class="name"><?= e($u['name']) ?></span>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="inline">
          <?= Csrf::field() ?>
          <button type="submit" class="linkbtn">Log out</button>
        </form>
      </div>
    <?php else: ?>
      <a href="<?= e(url('login.php')) ?>">Log in</a>
      <a class="btn small" href="<?= e(url('register.php')) ?>">Create account</a>
    <?php endif; ?>
  </nav>
</div></header>
<main class="wrap">
<?php endif; ?>
<?php foreach (pull_flash() as $f): ?>
  <div class="alert <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
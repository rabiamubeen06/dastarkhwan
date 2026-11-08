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
$onInventory = str_contains($script, '/admin/inventory');
$onCustomers = str_contains($script, '/admin/customer') || str_contains($script, '/admin/admins');
$onReports = str_contains($script, '/admin/reports');
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
    <img class="aside-photo" src="https://images.pexels.com/photos/12876507/pexels-photo-12876507.jpeg?auto=compress&cs=tinysrgb&w=1400" alt="">
    <p class="aside-text">Crockery for weddings, daawats and every gathering.</p>
  </aside>
  <main class="auth-main"><div class="auth-card">
<?php elseif ($isAdmin): ?>
<?php
$adminGroups = [
  'Overview' => [
    ['admin/dashboard.php', 'Dashboard', $onDash],
  ],
  'Orders' => [
    ['admin/bookings.php', 'Bookings', $onBookings, $pendingCount],
  ],
  'Catalog' => [
    ['admin/products.php', 'Products', $onProducts],
    ['admin/categories.php', 'Categories', $onCategories],
    ['admin/packages.php', 'Packages', $onAdminPackages],
    ['admin/inventory.php', 'Inventory', $onInventory],
  ],
  'Operations' => [
    ['admin/delivery-zones.php', 'Delivery zones', $onZones],
    ['admin/reports.php', 'Reports', $onReports],
  ],
  'People' => [
    ['admin/customers.php', 'Customers & admins', $onCustomers],
  ],
  'System' => [
    ['admin/settings.php', 'Settings', $onSettings],
    ['catalog.php', 'View storefront', $onCatalog],
  ],
];
?>
<div class="admin-shell">
  <input type="checkbox" id="side-toggle" class="side-toggle" hidden>
  <aside class="sidebar">
    <div class="side-brand">
      <a class="brand" href="<?= e(url('admin/dashboard.php')) ?>"><?= logo_svg(26) ?><?= brand_ur() ?></a>
      <span class="side-role">Admin panel</span>
    </div>
    <nav class="side-nav" aria-label="Admin">
      <?php foreach ($adminGroups as $group => $links): ?>
        <div class="side-group">
          <div class="side-title"><?= e($group) ?></div>
          <?php foreach ($links as $l): ?>
            <a href="<?= e(url($l[0])) ?>" class="<?= $l[2] ? 'active' : '' ?>"><?= e($l[1]) ?><?php if (!empty($l[3])): ?> <span class="badge"><?= (int) $l[3] ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
      <span class="name"><?= e($u['name']) ?></span>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline">
        <?= Csrf::field() ?>
        <button type="submit" class="linkbtn">Log out</button>
      </form>
    </div>
  </aside>
  <label for="side-toggle" class="side-scrim" aria-hidden="true"></label>
  <div class="admin-main">
    <div class="admin-bar">
      <label for="side-toggle" class="menu-btn" role="button">&#9776; Menu</label>
      <span class="admin-bar-title"><?= e($pageTitle ?? 'Admin') ?></span>
    </div>
    <main class="wrap">
<?php else: ?>
<header class="top"><div class="top-in">
  <a class="brand" href="<?= e(url('/')) ?>"><?= logo_svg(26) ?><?= brand_ur() ?></a>
  <nav>
    <a href="<?= e(url('catalog.php')) ?>" class="<?= $onCatalog ? 'active' : '' ?>">Catalog</a>
      <a href="<?= e(url('packages.php')) ?>" class="<?= $onPackages ? 'active' : '' ?>">Packages</a>
      <?php if ($u): ?><a href="<?= e(url('customer/bookings.php')) ?>" class="<?= $onMyBookings ? 'active' : '' ?>">Bookings</a><?php endif; ?>
      <a href="<?= e(url('cart.php')) ?>" class="<?= $onCart ? 'active' : '' ?>">Cart<?php if ($cartCount > 0): ?> <span class="badge"><?= $cartCount ?></span><?php endif; ?></a>
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

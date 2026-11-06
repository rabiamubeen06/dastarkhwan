<?php
$u = Auth::user();
$layout = $layout ?? 'app';
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$onDash = str_ends_with($script, 'dashboard.php');
$onCatalog = str_ends_with($script, 'catalog.php');
$onCart = str_ends_with($script, 'cart.php');
$cartCount = CartService::count();
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
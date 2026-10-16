<?php $u = Auth::user(); ?>
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
<header class="top">
  <a class="brand" href="<?= e(url('/')) ?>"><?= logo_svg(26) ?><?= brand_ur() ?></a>
  <nav>
    <?php if ($u): ?>
      <span class="hello">Hello, <?= e($u['name']) ?></span>
      <a href="<?= e(url(Auth::homeUrl())) ?>">Dashboard</a>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline">
        <?= Csrf::field() ?>
        <button type="submit" class="linkbtn">Log out</button>
      </form>
    <?php else: ?>
      <a href="<?= e(url('login.php')) ?>">Log in</a>
      <a class="btn small" href="<?= e(url('register.php')) ?>">Create account</a>
    <?php endif; ?>
  </nav>
</header>
<main class="wrap">
<?php foreach (pull_flash() as $f): ?>
  <div class="alert <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>

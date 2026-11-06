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
  <h2>Nothing to show yet</h2>
  <p>Deliveries, returns and pending requests will appear here as features are added.</p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
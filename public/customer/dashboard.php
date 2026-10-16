<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('customer');
$pageTitle = 'My dashboard';
require APP_PATH . '/views/header.php';
?>
<div class="card">
  <h1>Welcome, <?= e($user['name']) ?></h1>
  <p>This is the customer dashboard. Catalog, package builder and bookings will appear here in the next steps.</p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('admin');
$pageTitle = 'Admin dashboard';
require APP_PATH . '/views/header.php';
?>
<div class="card">
  <h1>Admin dashboard</h1>
  <p>Signed in as <?= e($user['name']) ?>. Products, bookings, payments and reports will be added here in the next steps.</p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

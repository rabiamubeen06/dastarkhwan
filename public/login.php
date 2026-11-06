<?php
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect(Auth::homeUrl());

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $result = Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($result['ok']) {
        // A guest who was sent here from the cart goes straight back to checkout.
        $next = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        redirect($result['role'] === 'customer' && $next === 'checkout.php' ? 'checkout.php' : Auth::homeUrl());
    }
    $error = $result['error'];
}
$pageTitle = 'Log in';
$layout = 'auth';
require APP_PATH . '/views/header.php';
?>
<h1>Log in</h1>
<p class="sub">Sign in to manage your bookings.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" novalidate>
  <?= Csrf::field() ?>
  <label>Email
    <input type="email" name="email" value="<?= old('email') ?>" required autocomplete="email">
  </label>
  <label>Password
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button class="btn block" type="submit">Log in</button>
</form>
<p class="muted foot-link">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
<?php require APP_PATH . '/views/footer.php'; ?>
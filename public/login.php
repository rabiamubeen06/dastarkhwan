<?php
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect(Auth::homeUrl());

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $result = Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect(Auth::homeUrl());
    }
    $error = $result['error'];
}
$pageTitle = 'Log in';
require APP_PATH . '/views/header.php';
?>
<div class="auth-brand" style="display:flex;flex-direction:column;align-items:center;text-align:center">
  <?= logo_svg(56) ?>
  <div class="ur-big" style="text-align:center"><?= brand_ur() ?></div>
</div>
  <h1>Log in</h1>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <label>Email
      <input type="email" name="email" value="<?= old('email') ?>" required autocomplete="email">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn" type="submit">Log in</button>
  </form>

  <p class="muted">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

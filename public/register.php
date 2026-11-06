<?php
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect(Auth::homeUrl());

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $result = Auth::register($_POST);
    if ($result['ok']) {
        flash('success', 'Account created. Please log in.');
        redirect('login.php');
    }
    $errors = $result['errors'];
}
$pageTitle = 'Create account';
$layout = 'auth';
require APP_PATH . '/views/header.php';
?>
<h1>Create your account</h1>
<p class="sub">Book crockery for your next event in a few minutes.</p>
<form method="post" novalidate>
  <?= Csrf::field() ?>
  <label>Full name
    <input type="text" name="full_name" value="<?= old('full_name') ?>" maxlength="120" required autocomplete="name">
  </label><?= field_error($errors, 'full_name') ?>
  <label>Email
    <input type="email" name="email" value="<?= old('email') ?>" maxlength="150" required autocomplete="email">
  </label><?= field_error($errors, 'email') ?>
  <label>Mobile number
    <input type="tel" name="phone" value="<?= old('phone') ?>" placeholder="03001234567" required autocomplete="tel">
  </label><?= field_error($errors, 'phone') ?>
  <div class="row">
    <label>Password
      <input type="password" name="password" minlength="8" required autocomplete="new-password">
    </label>
    <label>Confirm password
      <input type="password" name="password_confirm" minlength="8" required autocomplete="new-password">
    </label>
  </div><?= field_error($errors, 'password') ?>
  <button class="btn block" type="submit">Create account</button>
</form>
<p class="muted foot-link">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
<?php require APP_PATH . '/views/footer.php'; ?>
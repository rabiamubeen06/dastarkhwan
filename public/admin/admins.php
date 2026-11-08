<?php
require __DIR__ . '/../../app/bootstrap.php';
$admin = Auth::requireRole('admin');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'create') {
        $res = Auth::createAdmin($_POST);
        if ($res['ok']) {
            flash('success', 'Admin account created. Share the password with them in person or by a private message.');
            redirect('admin/admins.php');
        }
        $errors = $res['errors'];
    } elseif (in_array($action, ['block', 'unblock'], true)) {
        $uid = (int) (is_string($_POST['user_id'] ?? null) ? $_POST['user_id'] : 0);
        $err = UserAdminService::setStatus($uid, $action === 'block' ? 'blocked' : 'active', $admin['id'], 'admin');
        $err === null ? flash('success', $action === 'block' ? 'Admin blocked. They are signed out at once.' : 'Admin unblocked.') : flash('error', $err);
        redirect('admin/admins.php');
    }
}

$admins = UserAdminService::allAdmins();
$pageTitle = 'Admin accounts';
require APP_PATH . '/views/header.php';
?>
<p class="small-text"><a href="<?= e(url('admin/customers.php')) ?>">&larr; Customers</a></p>
<div class="page-head">
  <span class="eyebrow">People</span>
  <h1>Admin accounts</h1>
  <p>Everyone listed here has full access to the shop: bookings, payments, stock, settings and customers.</p>
</div>

<div class="split">
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Admin</th><th>Phone</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): $me = (int) $a['user_id'] === $admin['id']; ?>
          <tr>
            <td data-label="Admin"><strong><?= e($a['full_name']) ?></strong><?= $me ? ' <span class="muted small-text">(you)</span>' : '' ?><div class="muted small-text"><?= e($a['email']) ?></div></td>
            <td data-label="Phone"><?= e($a['phone']) ?></td>
            <td data-label="Status"><span class="status <?= $a['status'] === 'active' ? 'on' : 'bad' ?>"><?= $a['status'] === 'active' ? 'Active' : 'Blocked' ?></span></td>
            <td class="num">
              <?php if (!$me): ?>
                <form method="post" class="inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
                  <input type="hidden" name="action" value="<?= $a['status'] === 'active' ? 'block' : 'unblock' ?>">
                  <button class="btn ghost small" type="submit"><?= $a['status'] === 'active' ? 'Block' : 'Unblock' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <section class="panel">
    <h2>Add an admin</h2>
    <p class="small-text">They log in with this email and password. Choose a password for them and share it privately.</p>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">
      <label>Full name
        <input type="text" name="full_name" value="<?= old('full_name') ?>" maxlength="120" required>
      </label><?= field_error($errors, 'full_name') ?>
      <label>Email
        <input type="email" name="email" value="<?= old('email') ?>" maxlength="150" required autocomplete="off">
      </label><?= field_error($errors, 'email') ?>
      <label>Phone
        <input type="text" name="phone" value="<?= old('phone') ?>" placeholder="03001234567" required>
      </label><?= field_error($errors, 'phone') ?>
      <label>Password
        <input type="password" name="password" required autocomplete="new-password">
      </label>
      <label>Confirm password
        <input type="password" name="password_confirm" required autocomplete="new-password">
      </label><?= field_error($errors, 'password') ?>
      <p class="hint">At least 8 characters, with a letter and a number.</p>
      <div class="cart-actions mt"><button class="btn" type="submit">Create admin</button></div>
    </form>
  </section>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

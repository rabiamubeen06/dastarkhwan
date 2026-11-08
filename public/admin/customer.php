<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$get = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$status = in_array($get('status'), ['active', 'blocked'], true) ? $get('status') : '';
$q      = mb_substr($get('q'), 0, 60);
$result = UserAdminService::search('customer', $status, $q, max(1, (int) $get('page')));
$counts = UserAdminService::counts('customer');

$link = function (array $over = []) use ($status, $q): string {
    $p = array_filter(array_merge(['status' => $status, 'q' => $q], $over), fn($v) => $v !== '' && $v !== 0);
    return url('admin/customers.php') . ($p ? '?' . http_build_query($p) : '');
};

$pageTitle = 'Customers';
require APP_PATH . '/views/header.php';
?>
<div class="page-head head-row">
  <div>
    <span class="eyebrow">People</span>
    <h1>Customers</h1>
    <p>Everyone who has registered, with their booking history. Blocked customers cannot log in or send requests.</p>
  </div>
  <a class="btn ghost" href="<?= e(url('admin/admins.php')) ?>">Admin accounts</a>
</div>

<div class="cats">
  <a href="<?= e($link(['status' => '', 'page' => 0])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All (<?= (int) $counts['all'] ?>)</a>
  <a href="<?= e($link(['status' => 'active', 'page' => 0])) ?>" class="<?= $status === 'active' ? 'active' : '' ?>">Active (<?= (int) $counts['active'] ?>)</a>
  <a href="<?= e($link(['status' => 'blocked', 'page' => 0])) ?>" class="<?= $status === 'blocked' ? 'active' : '' ?>">Blocked (<?= (int) $counts['blocked'] ?>)</a>
</div>

<form method="get" class="toolbar mt">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <label class="grow">Search
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Name, email or phone">
  </label>
  <button class="btn ghost" type="submit">Search</button>
</form>

<?php if (!$result['rows']): ?>
  <div class="panel empty">
    <h2>No customers found</h2>
    <p><?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Customers appear here when they register.' ?></p>
  </div>
<?php else: ?>
  <p class="muted result-count"><?= (int) $result['total'] ?> customer<?= $result['total'] === 1 ? '' : 's' ?></p>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Customer</th><th>Phone</th><th>Bookings</th><th>Joined</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $c): ?>
          <tr>
            <td data-label="Customer"><strong><?= e($c['full_name']) ?></strong><div class="muted small-text"><?= e($c['email']) ?></div></td>
            <td data-label="Phone"><?= e($c['phone']) ?></td>
            <td data-label="Bookings"><?= (int) $c['bookings'] ?></td>
            <td data-label="Joined"><?= e(date('j M Y', strtotime($c['created_at']))) ?></td>
            <td data-label="Status"><span class="status <?= $c['status'] === 'active' ? 'on' : 'bad' ?>"><?= $c['status'] === 'active' ? 'Active' : 'Blocked' ?></span></td>
            <td class="num"><a href="<?= e(url('admin/customer.php?id=' . (int) $c['user_id'])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($result['pages'] > 1): ?>
    <div class="pager">
      <?php if ($result['page'] > 1): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

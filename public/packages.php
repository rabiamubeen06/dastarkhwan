<?php
require __DIR__ . '/../app/bootstrap.php';   // public page: guests can look at the template packages

$user = Auth::user();
$isCustomer = $user !== null && $user['role'] === 'customer';

// Delete one of my saved packages.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    if (!$isCustomer) {
        redirect('login.php');
    }
    $id = is_string($_POST['package_id'] ?? null) ? (int) $_POST['package_id'] : 0;
    PackageService::deleteMine((int) $user['id'], $id) ? flash('success', 'Package deleted.') : flash('error', 'That package was not found.');
    redirect('packages.php');
}

$templates = PackageService::templates();
$mine      = $isCustomer ? PackageService::mine((int) $user['id']) : [];
$dates     = CartService::dates();
$days      = $dates ? AvailabilityService::rentalDays($dates['start'], $dates['end']) : 0;
$hasCart   = CartService::count() > 0;
$pct       = fn($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

// One card. The "Use this package" button belongs to the dates form at the top (form="apply-form").
$card = function (array $p, bool $own) use ($pct, $days) {
    ?>
    <article class="product">
      <div class="body">
        <h2><?= e($p['name']) ?></h2>
        <div class="meta">
          <?= $p['guest_count'] ? 'For about ' . (int) $p['guest_count'] . ' guests' : ($own ? 'Saved by you' : 'Event package') ?>
          <?php if ((float) $p['discount_percent'] > 0): ?> &middot; <span class="pill ok"><?= e($pct($p['discount_percent'])) ?>% off</span><?php endif; ?>
        </div>
        <?php if (!empty($p['description'])): ?><p class="muted small-text mt"><?= e($p['description']) ?></p><?php endif; ?>
        <ul class="pkg-list">
          <?php foreach ($p['items'] as $it): if ((int) $it['is_active'] !== 1) { continue; } ?>
            <li><span><?= e($it['name']) ?></span><span class="muted">&times; <?= (int) $it['quantity'] ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="pkg-foot">
          <div class="price"><?= e(money($p['per_day'])) ?> <span>per day, before discount</span></div>
          <?php if ($p['qty']): ?>
            <button class="btn small" type="submit" form="apply-form" name="package_id" value="<?= (int) $p['package_id'] ?>">Use this package</button>
          <?php endif; ?>
          <?php if ($own): ?>
            <form method="post" class="inline" data-confirm="Delete this saved package?">
              <?= Csrf::field() ?>
              <input type="hidden" name="package_id" value="<?= (int) $p['package_id'] ?>">
              <button class="linkbtn" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </article><?php
};

$pageTitle = 'Packages';
$scripts   = ['assets/js/return.js'];
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Ready-made sets</span>
  <h1>Event packages</h1>
  <p>Start from a ready-made setting, then adjust the quantities in your cart.</p>
</div>

<form method="post" action="<?= e(url('cart.php')) ?>" id="apply-form" class="cart-dates"
      <?= $hasCart ? 'data-confirm="Using a package replaces the items now in your cart. Continue?"' : '' ?>>
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="apply_package">
  <label>Start date
    <input type="date" name="start" value="<?= e($dates['start'] ?? '') ?>" min="<?= e(date('Y-m-d')) ?>" required>
  </label>
  <label>End date
    <input type="date" name="end" value="<?= e($dates['end'] ?? '') ?>" min="<?= e(date('Y-m-d')) ?>" required>
  </label>
  <span class="muted">Choose your event dates, then pick a package.</span>
</form>

<?php if (!$templates): ?>
  <div class="panel empty"><h2>No packages yet</h2><p>Browse the catalog to build your own order.</p>
    <p class="mt"><a class="btn" href="<?= e(url('catalog.php')) ?>">Browse catalog</a></p></div>
<?php else: ?>
  <div class="grid pkgs">
    <?php foreach ($templates as $p) { $card($p, false); } ?>
  </div>
<?php endif; ?>

<div class="page-head mt">
  <h2>Your saved packages</h2>
  <?php if (!$isCustomer): ?>
    <p><a href="<?= e(url('login.php')) ?>">Log in</a> to see the packages you saved. You can save any cart as a package from the cart page.</p>
  <?php elseif (!$mine): ?>
    <p>Nothing saved yet. Fill your cart, then use &ldquo;Save this cart as a package&rdquo; on the cart page.</p>
  <?php endif; ?>
</div>
<?php if ($mine): ?>
  <div class="grid pkgs">
    <?php foreach ($mine as $p) { $card($p, true); } ?>
  </div>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

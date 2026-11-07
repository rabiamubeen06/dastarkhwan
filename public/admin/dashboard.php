<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = Auth::requireRole('admin');

$today = date('Y-m-d');
$d = DashboardService::overview($today);
$fmt = fn(string $s): string => date('j M', strtotime($s));
$more = fn(array $block): int => max(0, $block['total'] - count($block['rows']));

$pageTitle = 'Admin dashboard';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <p><?= e(date('l, j F Y')) ?> &middot; Signed in as <?= e($user['name']) ?></p>
</div>

<div class="stats">
  <a class="stat <?= $d['pending']['total'] > 0 ? 'attn' : '' ?>" href="<?= e(url('admin/bookings.php?status=pending')) ?>">
    <span class="stat-n"><?= (int) $d['pending']['total'] ?></span><span class="stat-l">Pending requests</span>
  </a>
  <a class="stat <?= $d['handovers']['total'] > 0 ? 'attn' : '' ?>" href="<?= e(url('admin/bookings.php?status=confirmed')) ?>">
    <span class="stat-n"><?= (int) $d['handovers']['total'] ?></span><span class="stat-l">Handovers today</span>
  </a>
  <a class="stat <?= $d['returns']['total'] > 0 ? 'attn' : '' ?>" href="<?= e(url('admin/bookings.php?status=delivered')) ?>">
    <span class="stat-n"><?= (int) $d['returns']['total'] ?></span><span class="stat-l">Returns due</span>
  </a>
  <a class="stat <?= $d['low']['total'] > 0 ? 'attn' : '' ?>" href="<?= e(url('admin/inventory.php')) ?>">
    <span class="stat-n"><?= (int) $d['low']['total'] ?></span><span class="stat-l">Low stock items</span>
  </a>
</div>

<div class="dash">
  <div class="dash-main">
    <section class="panel">
      <h2>Handovers today</h2>
      <p class="small-text">Confirmed bookings that start today, and any that started earlier but were not marked delivered.</p>
      <?php if (!$d['handovers']['rows']): ?>
        <p class="dash-empty">No deliveries or pickups due today.</p>
      <?php else: ?>
        <ul class="dlist">
          <?php foreach ($d['handovers']['rows'] as $r): ?>
            <li>
              <div>
                <a href="<?= e(url('admin/booking.php?id=' . (int) $r['booking_id'])) ?>"><strong><?= e($r['booking_no']) ?></strong></a> &middot; <?= e($r['full_name']) ?>
                <div class="muted small-text"><?= $r['delivery_method'] === 'delivery'
                    ? 'Delivery to ' . e((string) $r['delivery_address']) . ($r['zone_city'] ? ' (' . e($r['zone_city'] . ', ' . $r['zone_area']) . ')' : '')
                    : 'Pickup from the shop' ?> &middot; <?= e($r['phone']) ?></div>
              </div>
              <?php if ($r['overdue']): ?><span class="status bad">Since <?= e($fmt($r['start_date'])) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($more($d['handovers']) > 0): ?><p class="small-text mt"><a href="<?= e(url('admin/bookings.php?status=confirmed')) ?>"><?= $more($d['handovers']) ?> more confirmed bookings</a></p><?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Returns due</h2>
      <p class="small-text">Items out with customers whose rental ends today or earlier.</p>
      <?php if (!$d['returns']['rows']): ?>
        <p class="dash-empty">No returns are due.</p>
      <?php else: ?>
        <ul class="dlist">
          <?php foreach ($d['returns']['rows'] as $r): ?>
            <li>
              <div>
                <a href="<?= e(url('admin/booking.php?id=' . (int) $r['booking_id'])) ?>"><strong><?= e($r['booking_no']) ?></strong></a> &middot; <?= e($r['full_name']) ?>
                <div class="muted small-text">Due back <?= e($fmt($r['end_date'])) ?> &middot; <?= e($r['phone']) ?></div>
              </div>
              <?php if ($r['days_over'] > 0): ?>
                <span class="status bad"><?= (int) $r['days_over'] ?> day<?= $r['days_over'] === 1 ? '' : 's' ?> late</span>
              <?php else: ?>
                <span class="status">Due today</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($more($d['returns']) > 0): ?><p class="small-text mt"><a href="<?= e(url('admin/bookings.php?status=delivered')) ?>"><?= $more($d['returns']) ?> more returns due</a></p><?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Pending requests</h2>
      <p class="small-text">Waiting for your review, oldest first.</p>
      <?php if (!$d['pending']['rows']): ?>
        <p class="dash-empty">No requests are waiting.</p>
      <?php else: ?>
        <ul class="dlist">
          <?php foreach ($d['pending']['rows'] as $r): ?>
            <li>
              <div>
                <a href="<?= e(url('admin/booking.php?id=' . (int) $r['booking_id'])) ?>"><strong><?= e($r['booking_no']) ?></strong></a> &middot; <?= e($r['full_name']) ?>
                <div class="muted small-text">Event <?= e($fmt($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? ' to ' . e($fmt($r['end_date'])) : '' ?> &middot; <?= e(money($r['total_amount'])) ?> &middot; requested <?= e($fmt(substr($r['created_at'], 0, 10))) ?></div>
              </div>
              <a class="btn ghost small" href="<?= e(url('admin/booking.php?id=' . (int) $r['booking_id'])) ?>">Review</a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($more($d['pending']) > 0): ?><p class="small-text mt"><a href="<?= e(url('admin/bookings.php?status=pending')) ?>"><?= $more($d['pending']) ?> more pending requests</a></p><?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <aside class="dash-side">
    <section class="panel quote-panel">
      <h2>Revenue</h2>
      <p class="small-text"><?= e(date('F Y')) ?></p>
      <dl class="quote">
        <div class="total"><dt>This month</dt><dd><?= e(money($d['month']['total'])) ?></dd></div>
        <div class="after"><dt>Rental payments</dt><dd><?= e(money($d['month']['rental'])) ?></dd></div>
        <div class="after"><dt>Late fees and damage charges</dt><dd><?= e(money($d['month']['charges'])) ?></dd></div>
        <div class="after"><dt>Deposits kept for charges</dt><dd><?= e(money($d['month']['deposit_kept'])) ?></dd></div>
      </dl>
      <dl class="quote">
        <div class="after"><dt>Today</dt><dd><?= e(money($d['today']['total'])) ?></dd></div>
      </dl>
      <p class="hint">Money received, before any refunds. Security deposits held for customers are not income.</p>
    </section>

    <section class="panel">
      <h2>Low stock</h2>
      <p class="small-text">Units free to rent today, for items with 20% or less left.</p>
      <?php if (!$d['low']['rows']): ?>
        <p class="dash-empty">Every item has healthy stock today.</p>
      <?php else: ?>
        <ul class="dlist">
          <?php foreach ($d['low']['rows'] as $p): ?>
            <li>
              <div>
                <a href="<?= e(url('admin/inventory-product.php?id=' . (int) $p['product_id'])) ?>"><strong><?= e($p['name']) ?></strong></a>
                <div class="muted small-text"><?= e($p['sku']) ?></div>
              </div>
              <span class="status <?= $p['free'] === 0 ? 'bad' : '' ?>"><?= $p['free'] === 0 ? 'None free' : (int) $p['free'] . ' of ' . (int) $p['total'] . ' free' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($more($d['low']) > 0): ?><p class="small-text mt"><a href="<?= e(url('admin/inventory.php')) ?>"><?= $more($d['low']) ?> more in the inventory calendar</a></p><?php endif; ?>
      <?php endif; ?>
    </section>
  </aside>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
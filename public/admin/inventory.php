<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$get = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$q     = mb_substr($get('q'), 0, 60);
$cat   = (int) $get('category');
$start = InventoryService::startDate($get('start'));
$today = date('Y-m-d');

$result     = ProductService::search($q, $cat, 'active', max(1, (int) $get('page')));
$categories = CategoryService::all();
$dates      = InventoryService::dates($start);
$ids        = array_map(fn($r) => (int) $r['product_id'], $result['rows']);
$cal        = $ids ? InventoryService::calendar($ids, $start) : [];

$link = function (array $over = []) use ($q, $cat, $start, $today): string {
    $p = array_merge(['q' => $q, 'category' => $cat, 'start' => $start === $today ? '' : $start], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return url('admin/inventory.php') . ($p ? '?' . http_build_query($p) : '');
};
$shift = fn(int $days): string => (new DateTimeImmutable($start))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

$pageTitle = 'Inventory';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Catalog</span>
  <h1>Inventory calendar</h1>
  <p>Units a customer could rent on each date, after bookings, buffer days and damaged, lost or repaired stock.</p>
</div>

<form method="get" class="toolbar">
  <label class="grow">Search
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Name or SKU">
  </label>
  <label>Category
    <select name="category">
      <option value="0">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['category_id'] ?>" <?= $cat === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>From date
    <input type="date" name="start" value="<?= e($start) ?>">
  </label>
  <button class="btn ghost" type="submit">Show</button>
</form>

<div class="cart-actions">
  <a class="btn ghost small" href="<?= e($link(['start' => $shift(-InventoryService::DAYS)])) ?>">&larr; Earlier</a>
  <a class="btn ghost small" href="<?= e($link(['start' => ''])) ?>">Today</a>
  <a class="btn ghost small" href="<?= e($link(['start' => $shift(InventoryService::DAYS)])) ?>">Later &rarr;</a>
  <span class="muted small-text"><span class="cal-key low"></span> 20% or less left <span class="cal-key zero"></span> none left</span>
</div>

<?php if (!$result['rows']): ?>
  <div class="panel empty"><h2>No products found</h2><p>Try removing some filters.</p></div>
<?php else: ?>
  <div class="cal-wrap mt">
    <table class="cal-table">
      <thead>
        <tr>
          <th class="cal-name">Product</th>
          <?php foreach ($dates as $d): $ts = strtotime($d); ?>
            <th class="<?= $d === $today ? 'today' : '' ?>"><?= e(date('D', $ts)) ?><br><?= e(date('j M', $ts)) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $p): $pid = (int) $p['product_id']; $total = (int) $p['total_quantity']; ?>
          <tr>
            <td class="cal-name">
              <a href="<?= e(url('admin/inventory-product.php?id=' . $pid)) ?>"><strong><?= e($p['name']) ?></strong></a>
              <div class="muted small-text"><?= e($p['sku']) ?> &middot; <?= $total ?> owned</div>
            </td>
            <?php foreach ($dates as $d): $n = (int) ($cal[$pid][$d] ?? 0); ?>
              <td class="n <?= $n === 0 ? 'zero' : ($n * 5 <= $total ? 'low' : '') ?>"><?= $n ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small-text mt">Open a product to record restocks, damaged, lost or repaired units, and to see which bookings hold it.</p>

  <?php if ($result['pages'] > 1): ?>
    <div class="pager">
      <?php if ($result['page'] > 1): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn ghost small" href="<?= e($link(['page' => $result['page'] + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

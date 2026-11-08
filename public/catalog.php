<?php
require __DIR__ . '/../app/bootstrap.php';   // public page: guests can browse too
$pdo = Database::pdo();

$get = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$q        = mb_substr($get('q'), 0, 60);
$cat      = (int) $get('category');
$material = mb_substr($get('material'), 0, 60);
$min      = is_numeric($get('min')) && (float) $get('min') > 0 ? (float) $get('min') : null;
$max      = is_numeric($get('max')) && (float) $get('max') > 0 ? (float) $get('max') : null;
$start    = $get('start');
$end      = $get('end');
$page     = max(1, (int) $get('page'));
$perPage  = 12;

$filters = ['q' => $q, 'category' => $cat, 'material' => $material, 'min' => $min, 'max' => $max, 'start' => $start, 'end' => $end];
$link = function (array $over = []) use ($filters): string {
    $p = array_filter(array_merge($filters, $over), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return url('catalog.php') . ($p ? '?' . http_build_query($p) : '');
};

// Build WHERE with prepared-statement parameters.
$where = ['p.is_active = 1'];
$params = [];
if ($cat > 0)        { $where[] = 'p.category_id = ?';  $params[] = $cat; }
if ($q !== '') {
    // Also match the singular form ("plates" finds "plate"), the category name and the SKU.
    $term = preg_replace('/(es|s)$/i', '', $q);
    if (mb_strlen($term) < 3) { $term = $q; }
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ? OR c.name LIKE ?)';
    array_push($params, "%$term%", "%$term%", "%$term%", "%$term%");
}
if ($material !== ''){ $where[] = 'p.material = ?';     $params[] = $material; }
if ($min !== null)   { $where[] = 'p.rent_per_day >= ?'; $params[] = $min; }
if ($max !== null)   { $where[] = 'p.rent_per_day <= ?'; $params[] = $max; }
$whereSql = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN categories c ON c.category_id = p.category_id WHERE $whereSql");
$st->execute($params);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;   // integers only, safe to inline

$st = $pdo->prepare(
    "SELECT p.product_id, p.name, p.material, p.min_order_qty, p.image_url, p.rent_per_day, c.name AS category
     FROM products p JOIN categories c ON c.category_id = p.category_id
     WHERE $whereSql ORDER BY p.name LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$products = $st->fetchAll();

// Availability for the chosen dates (optional)
$datesGiven = $start !== '' || $end !== '';
$dateError  = $datesGiven ? AvailabilityService::dateError($start, $end) : null;
$datesOk    = $datesGiven && $dateError === null;
$avail      = $datesOk ? AvailabilityService::forProducts(array_column($products, 'product_id'), $start, $end) : [];

$categories = $pdo->query('SELECT category_id, name FROM categories ORDER BY sort_order, name')->fetchAll();
$materials  = $pdo->query("SELECT DISTINCT material FROM products WHERE is_active = 1 AND material IS NOT NULL AND material <> '' ORDER BY material")->fetchAll(PDO::FETCH_COLUMN);
$hasFilters = $datesGiven || $q !== '' || $cat > 0 || $material !== '' || $min !== null || $max !== null;

$pageTitle = 'Catalog';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Browse crockery</span>
  <h1>Catalog</h1>
  <p>Crockery, cutlery and serving ware for weddings, daawats and parties.</p>
</div>

<?php if ($dateError): ?><div class="alert error"><?= e($dateError) ?></div><?php endif; ?>

<form method="get" class="filters">
  <?php if ($cat > 0): ?><input type="hidden" name="category" value="<?= $cat ?>"><?php endif; ?>
  <label class="f-start">Start date
    <input type="date" name="start" value="<?= e($start) ?>" min="<?= e(date('Y-m-d')) ?>">
  </label>
  <label class="f-end">End date
    <input type="date" name="end" value="<?= e($end) ?>" min="<?= e(date('Y-m-d')) ?>">
  </label>
  <label class="f-search">Search
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Plates, forks, teapot" maxlength="60">
  </label>
  <label class="f-material">Material
    <select name="material">
      <option value="">All materials</option>
      <?php foreach ($materials as $m): ?>
        <option value="<?= e($m) ?>" <?= $m === $material ? 'selected' : '' ?>><?= e($m) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="f-min">Min rent/day
    <input type="number" name="min" value="<?= $min !== null ? e((string) $min) : '' ?>" min="0" step="1" placeholder="0">
  </label>
  <label class="f-max">Max rent/day
    <input type="number" name="max" value="<?= $max !== null ? e((string) $max) : '' ?>" min="0" step="1" placeholder="Any">
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<nav class="cats" aria-label="Categories">
  <a href="<?= e($link(['category' => 0, 'page' => 1])) ?>" class="<?= $cat === 0 ? 'active' : '' ?>">All</a>
  <?php foreach ($categories as $c): ?>
    <a href="<?= e($link(['category' => (int) $c['category_id'], 'page' => 1])) ?>"
       class="<?= $cat === (int) $c['category_id'] ? 'active' : '' ?>"><?= e($c['name']) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$products): ?>
  <div class="panel empty">
    <h2>No products found</h2>
    <p>Try a different search or remove some filters.</p>
    <?php if ($hasFilters): ?><p class="mt"><a class="btn ghost" href="<?= e(url('catalog.php')) ?>">Clear filters</a></p><?php endif; ?>
  </div>
<?php else: ?>
  <p class="muted result-count">
    <?= $total ?> product<?= $total === 1 ? '' : 's' ?>
    <?php if ($datesOk): ?>
      &middot; availability for <?= e(date('j M Y', strtotime($start))) ?> to <?= e(date('j M Y', strtotime($end))) ?>
      (<?= AvailabilityService::rentalDays($start, $end) ?> day<?= AvailabilityService::rentalDays($start, $end) === 1 ? '' : 's' ?>)
    <?php endif; ?>
  </p>
  <?php if (!$datesOk): ?><p class="muted">Choose a start and end date above to see availability and add items to your cart.</p><?php endif; ?>
  <div class="grid">
    <?php foreach ($products as $p): ?>
      <article class="product">
        <div class="thumb">
          <?php if ($p['image_url'] && (str_starts_with($p['image_url'], 'https://') || str_starts_with($p['image_url'], '/'))): ?>
            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
          <?php else: ?>
            <?= logo_svg(40) ?>
          <?php endif; ?>
        </div>
        <div class="body">
          <h2><?= e($p['name']) ?></h2>
          <div class="meta"><?= e($p['category']) ?><?= $p['material'] ? ', ' . e($p['material']) : '' ?></div>
          <div class="price"><?= e(money($p['rent_per_day'])) ?> <span>per day</span></div>
          <?php if ($datesOk): $n = $avail[(int) $p['product_id']] ?? 0; ?>
            <div class="stock <?= $n > 0 ? 'in' : 'out' ?>"><?= $n > 0 ? $n . ' available' : 'Not available for these dates' ?></div>
            <?php $min = max(1, (int) $p['min_order_qty']); if ($n >= $min): ?>
              <form method="post" action="<?= e(url('cart.php')) ?>" class="addform">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                <input type="hidden" name="start" value="<?= e($start) ?>">
                <input type="hidden" name="end" value="<?= e($end) ?>">
                <input type="hidden" name="return" value="<?= e((string) ($_SERVER['QUERY_STRING'] ?? '')) ?>">
                <input class="qty" type="number" name="qty" value="<?= $min ?>" min="<?= $min ?>" max="<?= $n ?>" aria-label="Quantity of <?= e($p['name']) ?>">
                <button class="btn small" type="submit">Add to cart</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn ghost small" href="<?= e($link(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn ghost small" href="<?= e($link(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>

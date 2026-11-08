<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$get  = fn(string $k): string => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$type = isset(ReportService::TYPES[$get('type')]) ? $get('type') : 'revenue';
$dated = !in_array($type, ReportService::UNDATED, true);
$group = isset(ReportService::GROUPS[$get('group')]) ? $get('group') : 'month';
$range = ReportService::range($get('from'), $get('to'));
$report = ReportService::run($type, $range['from'], $range['to'], $group);

if ($get('format') === 'csv') {
    $name = 'dastarkhwan-' . $type . '-' . ($dated ? $range['from'] . '_to_' . $range['to'] : date('Y-m-d')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo ReportService::toCsv($report);
    exit;
}

$query = fn(array $over = []): string => '?' . http_build_query(array_filter(
    array_merge(['type' => $type, 'from' => $dated ? $range['from'] : '', 'to' => $dated ? $range['to'] : '', 'group' => $type === 'revenue' ? $group : ''], $over),
    fn($v) => $v !== ''
));
$show = function (mixed $v, array $col): string {
    return match ($col['type']) {
        'money'   => e(money($v)),
        'int'     => e(number_format((int) $v)),
        'percent' => e(number_format((float) $v, 1) . '%'),
        default   => e($col['key'] === 'start_date' && $v ? date('j M Y', strtotime((string) $v)) : (string) $v),
    };
};
$isNum = fn(array $col): bool => $col['type'] !== 'text';
$table = function (array $columns, array $rows, ?array $totals) use ($show, $isNum): void { ?>
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><?php foreach ($columns as $c): ?><th<?= $isNum($c) ? ' class="num"' : '' ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><?php foreach ($columns as $c): ?><td data-label="<?= e($c['label']) ?>"<?= $isNum($c) ? ' class="num"' : '' ?>><?= $show($r[$c['key']] ?? '', $c) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if ($totals): ?>
          <tr class="total-row">
            <?php foreach ($columns as $i => $c): ?>
              <td<?= $isNum($c) ? ' class="num"' : '' ?>><?= $i === 0 ? 'Total' : (array_key_exists($c['key'], $totals) ? $show($totals[$c['key']], $c) : '') ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php };

$pageTitle = 'Reports';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Operations</span>
  <h1>Reports</h1>
  <p>Income, stock performance, unpaid balances and losses. Every report can be downloaded as a CSV file for Excel.</p>
</div>

<div class="cats">
  <?php foreach (ReportService::TYPES as $key => $label): ?>
    <a href="<?= e($query(['type' => $key])) ?>" class="<?= $type === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($dated): ?>
  <form method="get" class="toolbar mt">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label>From <input type="date" name="from" value="<?= e($range['from']) ?>" required></label>
    <label>To <input type="date" name="to" value="<?= e($range['to']) ?>" required></label>
    <?php if ($type === 'revenue'): ?>
      <label>Group by
        <select name="group">
          <?php foreach (ReportService::GROUPS as $k => $label): ?><option value="<?= e($k) ?>" <?= $group === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <button class="btn ghost" type="submit">Show report</button>
  </form>
  <p class="small-text mt">
    <?php foreach (ReportService::presets() as $p): ?>
      <a href="<?= e($query(['from' => $p['from'], 'to' => $p['to']])) ?>"><?= e($p['label']) ?></a> &middot;
    <?php endforeach; ?>
    <span class="muted">Shown: <?= e(date('j M Y', strtotime($range['from']))) ?> to <?= e(date('j M Y', strtotime($range['to']))) ?></span>
  </p>
  <?php if ($range['error']): ?><div class="alert warning"><?= e($range['error']) ?> Showing this month instead.</div><?php endif; ?>
<?php endif; ?>

<div class="head-row mt">
  <div>
    <h2><?= e($report['title']) ?></h2>
    <p class="muted"><?= e($report['intro']) ?></p>
  </div>
  <a class="btn" href="<?= e($query(['format' => 'csv'])) ?>">Download CSV</a>
</div>

<?php if (!$report['rows']): ?>
  <div class="panel empty"><h2>Nothing to show</h2><p>No data was found<?= $dated ? ' for this period' : '' ?>.</p></div>
<?php else: ?>
  <?php $table($report['columns'], $report['rows'], $report['totals']); ?>
  <?php if (!empty($report['by_customer']) && $report['by_customer']['rows']): ?>
    <h2 class="mt"><?= e($report['by_customer']['title']) ?></h2>
    <?php $table($report['by_customer']['columns'], $report['by_customer']['rows'], null); ?>
  <?php endif; ?>
<?php endif; ?>
<p class="hint mt"><?= e($report['note']) ?></p>
<?php require APP_PATH . '/views/footer.php'; ?>

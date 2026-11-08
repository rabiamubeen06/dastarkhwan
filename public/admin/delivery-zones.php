<?php
require __DIR__ . '/../../app/bootstrap.php';
Auth::requireRole('admin');

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$editId = (int) (is_string($_GET['edit'] ?? null) ? $_GET['edit'] : 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::enforce();
    $action = $str('action');
    $id = (int) $str('zone_id');

    if ($action === 'delete') {
        $err = DeliveryZoneService::delete($id);
        $err === null ? flash('success', 'Delivery zone deleted.') : flash('error', $err);
        redirect('admin/delivery-zones.php');
    }
    if ($action === 'toggle') {
        if (DeliveryZoneService::find($id) !== null) {
            $on = $str('active') === '1';
            DeliveryZoneService::setActive($id, $on);
            flash('success', $on ? 'Zone is available to customers again.' : 'Zone hidden from customers.');
        }
        redirect('admin/delivery-zones.php');
    }
    if ($action === 'save') {
        $editId = $id;   // keep the form in edit mode if validation fails
        [$errors, $clean] = DeliveryZoneService::validate($_POST, $id > 0 ? $id : null);
        if (!$errors) {
            $res = DeliveryZoneService::save($clean, $id > 0 ? $id : null);
            $errors = $res['errors'];
            if (!$errors) {
                flash('success', $id > 0 ? 'Delivery zone updated.' : 'Delivery zone added.');
                redirect('admin/delivery-zones.php');
            }
        }
    }
}

$editing = $editId > 0 ? DeliveryZoneService::find($editId) : null;
if ($editId > 0 && $editing === null) {
    flash('error', 'That delivery zone was not found.');
    redirect('admin/delivery-zones.php');
}
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = fn(string $k): string => e($posted ? $str($k) : (string) ($editing[$k] ?? ($k === 'city' ? 'Lahore' : '')));

$zones = DeliveryZoneService::all();
$pageTitle = 'Delivery zones';
require APP_PATH . '/views/header.php';
?>
<div class="page-head">
  <span class="eyebrow">Operations</span>
  <h1>Delivery zones</h1>
  <p>Areas you deliver to and the fee for each. Customers pick one at checkout when they choose delivery.</p>
</div>

<div class="split">
  <div class="cart-table-wrap">
    <table class="cart-table">
      <thead><tr><th>Area</th><th>Fee</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($zones as $z): $active = (int) $z['is_active'] === 1; ?>
          <tr>
            <td data-label="Area">
              <strong><?= e($z['area']) ?></strong>
              <div class="muted small-text"><?= e($z['city']) ?><?= (int) $z['bookings'] > 0 ? ' &middot; used in ' . (int) $z['bookings'] . ' booking' . ((int) $z['bookings'] === 1 ? '' : 's') : '' ?></div>
            </td>
            <td data-label="Fee"><?= e(money($z['fee'])) ?></td>
            <td data-label="Status"><span class="pill <?= $active ? 'ok' : 'off' ?>"><?= $active ? 'Active' : 'Hidden' ?></span></td>
            <td class="num">
              <a href="<?= e(url('admin/delivery-zones.php?edit=' . (int) $z['zone_id'])) ?>">Edit</a>
              &nbsp;<form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="zone_id" value="<?= (int) $z['zone_id'] ?>">
                <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                <button class="linkbtn" type="submit"><?= $active ? 'Hide' : 'Show' ?></button>
              </form>
              <?php if ((int) $z['bookings'] === 0): ?>
                &nbsp;<form method="post" class="inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="zone_id" value="<?= (int) $z['zone_id'] ?>">
                  <button class="linkbtn" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$zones): ?><tr><td colspan="4" class="muted">No delivery zones yet. Customers can only choose pickup until you add one.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <section class="panel">
    <h2><?= $editing ? 'Edit zone' : 'Add zone' ?></h2>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="zone_id" value="<?= $editing ? (int) $editing['zone_id'] : 0 ?>">
      <label>City
        <input type="text" name="city" maxlength="80" value="<?= $val('city') ?>" required>
      </label><?= field_error($errors, 'city') ?>
      <label>Area
        <input type="text" name="area" maxlength="100" value="<?= $val('area') ?>" required>
      </label><?= field_error($errors, 'area') ?>
      <label>Delivery fee (PKR)
        <input type="text" name="fee" inputmode="decimal" maxlength="10" value="<?= $val('fee') ?>" required>
      </label><?= field_error($errors, 'fee') ?>
      <p class="muted small-text mt">Hiding a zone keeps old bookings intact. Only unused zones can be deleted.</p>
      <div class="cart-actions mt">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add zone' ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="<?= e(url('admin/delivery-zones.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

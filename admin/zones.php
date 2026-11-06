<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_zone') {
            $id = (int)($_POST['zone_id'] ?? 0);
            $name = clean_text($_POST['zone_name'] ?? '');
            $feeIn = trim((string)($_POST['fee'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new UserFacingException('Enter a zone name (up to 80 characters).');
            if (!is_numeric($feeIn) || (float)$feeIn < 0 || (float)$feeIn > 100000) throw new UserFacingException('Enter a delivery fee of 0 or more.');
            $fee = round((float)$feeIn, 2); $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id) {
                $pdo->prepare('UPDATE delivery_zones SET zone_name=?,fee=?,is_active=? WHERE zone_id=?')->execute([$name, $fee, $active, $id]);
                log_activity($pdo, 'zone_update', 'zone', $id, $name . ' ₱' . $fee . ($active ? '' : ' (inactive)'));
                flash('Zone updated. New fees apply to orders placed from now on.');
            } else {
                $pdo->prepare('INSERT INTO delivery_zones(zone_name,fee,is_active) VALUES(?,?,?)')->execute([$name, $fee, $active]);
                log_activity($pdo, 'zone_create', 'zone', (int)$pdo->lastInsertId(), $name . ' ₱' . $fee);
                flash('Zone added. Assign cities to it below.');
            }
        } elseif ($action === 'delete_zone') {
            $id = (int)($_POST['zone_id'] ?? 0);
            $pdo->prepare('DELETE FROM delivery_zones WHERE zone_id=?')->execute([$id]); // cities fall back to the standard fee
            log_activity($pdo, 'zone_delete', 'zone', $id, '');
            flash('Zone deleted. Its cities now use the standard delivery fee.');
        } elseif ($action === 'save_city_zones') {
            $valid = array_map('intval', $pdo->query('SELECT zone_id FROM delivery_zones')->fetchAll(PDO::FETCH_COLUMN));
            $set = $pdo->prepare('UPDATE cities SET zone_id=? WHERE city_id=?');
            foreach ((array)($_POST['city_zone'] ?? []) as $cityId => $zoneId) {
                $zoneId = (int)$zoneId;
                $set->execute([in_array($zoneId, $valid, true) ? $zoneId : null, (int)$cityId]);
            }
            log_activity($pdo, 'zone_cities', 'zone', null, 'City zones updated');
            flash('City zones saved.');
        } elseif ($action === 'add_barangay') {
            $cityId = (int)($_POST['city_id'] ?? 0);
            $name = strip_brgy_prefix(clean_text($_POST['barangay'] ?? ''));
            if ($name === '' || mb_strlen($name) > BARANGAY_MAX) throw new UserFacingException('Enter a barangay name (up to ' . BARANGAY_MAX . ' characters).');
            $c = $pdo->prepare('SELECT city FROM cities WHERE city_id=?'); $c->execute([$cityId]);
            $city = $c->fetchColumn();
            if ($city === false) throw new UserFacingException('Choose a city.');
            $ins = $pdo->prepare('INSERT IGNORE INTO barangays(city_id,barangay) VALUES(?,?)'); $ins->execute([$cityId, $name]);
            if ($ins->rowCount() === 0) throw new UserFacingException($name . ' is already in the list for ' . $city . '.');
            log_activity($pdo, 'barangay_add', 'barangay', (int)$pdo->lastInsertId(), $name . ', ' . $city);
            flash($name . ' added to ' . $city . '. Customers can now pick it.');
        } else {
            throw new UserFacingException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash(user_error_message($e, 'zones'), 'error');
    }
    redirect('/admin/zones.php');
}

$zones = $pdo->query('SELECT z.*,(SELECT COUNT(*) FROM cities c WHERE c.zone_id=z.zone_id) city_count FROM delivery_zones z ORDER BY z.zone_name')->fetchAll();
$cities = $pdo->query('SELECT c.*,(SELECT COUNT(*) FROM barangays b WHERE b.city_id=c.city_id) brgy_count FROM cities c ORDER BY c.province,c.city')->fetchAll();
$std = $pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='delivery_fee'")->fetchColumn();

$pageTitle = 'Delivery zones';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>Delivery zones</h1><p class="muted">Set a delivery fee per zone, then choose which zone each city belongs to. A city with no zone pays the standard fee (<?= money($std !== false ? $std : 100) ?>, set in Settings). Free delivery above the minimum order still applies everywhere.</p></div><a class="btn btn-outline" href="deliveries.php">← Delivery Management</a></div>

<div class="card u-mb-18"><h2>Zones</h2>
  <div class="table-wrap"><table><thead><tr><th>Zone</th><th>Fee (₱)</th><th>Cities</th><th>Active</th><th></th></tr></thead><tbody>
  <?php foreach ($zones as $z): ?>
  <?php $fid = 'zf-' . (int)$z['zone_id']; ?>
  <tr>
    <td><input form="<?= $fid ?>" name="zone_name" value="<?= e($z['zone_name']) ?>" maxlength="80" required aria-label="Zone name"></td>
    <td><input form="<?= $fid ?>" type="number" name="fee" min="0" step="0.01" value="<?= e(number_format((float)$z['fee'], 2, '.', '')) ?>" required aria-label="Fee"></td>
    <td><?= (int)$z['city_count'] ?></td>
    <td><label class="u-check-row"><input form="<?= $fid ?>" class="u-check-input" type="checkbox" name="is_active" value="1" <?= $z['is_active'] ? 'checked' : '' ?>> <span class="small">Active</span></label></td>
    <td>
      <form id="<?= $fid ?>" method="post" class="u-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_zone"><input type="hidden" name="zone_id" value="<?= (int)$z['zone_id'] ?>"><button class="btn btn-primary btn-sm">Save</button></form>
      <form method="post" class="u-inline" onsubmit="return confirm('Delete this zone? Its cities will use the standard delivery fee.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_zone"><input type="hidden" name="zone_id" value="<?= (int)$z['zone_id'] ?>"><button class="btn btn-outline btn-sm">Delete</button></form>
    </td></tr>
  <?php endforeach; ?>
  <?php if (!$zones): ?><tr><td colspan="6" class="muted">No zones yet. Every city pays the standard fee.</td></tr><?php endif; ?>
  </tbody></table></div>
  <form method="post" class="u-flex-end-wrap u-mt-14"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_zone"><input type="hidden" name="is_active" value="1">
    <div class="u-grow-220"><label for="nz-name">New zone name</label><input id="nz-name" name="zone_name" maxlength="80" required placeholder="e.g. South Cebu"></div>
    <div><label for="nz-fee">Fee (₱)</label><input id="nz-fee" type="number" name="fee" min="0" step="0.01" required></div>
    <button class="btn btn-primary">Add zone</button></form>
</div>

<div class="card u-mb-18"><h2>Which zone is each city in?</h2>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_city_zones">
  <div class="table-wrap"><table><thead><tr><th>Province</th><th>City / municipality</th><th>Barangays listed</th><th>Zone</th></tr></thead><tbody>
  <?php foreach ($cities as $c): ?>
  <tr><td><?= e($c['province']) ?></td><td><?= e($c['city']) ?></td><td><?= (int)$c['brgy_count'] ?: '<span class="muted small">none (free typing)</span>' ?></td>
    <td><select name="city_zone[<?= (int)$c['city_id'] ?>]" aria-label="Zone for <?= e($c['city']) ?>"><option value="0">Standard fee</option>
      <?php foreach ($zones as $z): ?><option value="<?= (int)$z['zone_id'] ?>" <?= (int)$c['zone_id'] === (int)$z['zone_id'] ? 'selected' : '' ?>><?= e($z['zone_name']) ?> (<?= money($z['fee']) ?>)<?= $z['is_active'] ? '' : ' – inactive' ?></option><?php endforeach; ?>
    </select></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <button class="btn btn-primary u-mt-14">Save city zones</button></form>
</div>

<div class="card"><h2>Add a barangay</h2>
  <p class="muted small">Customers can only pick barangays from the list for cities that have one. Add any that are missing. A city with no barangays listed accepts free typing until you add its first one.</p>
  <form method="post" class="u-flex-end-wrap"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_barangay">
    <div class="u-grow-220"><label for="ab-city">City / municipality</label><select id="ab-city" name="city_id" required><?php foreach ($cities as $c): ?><option value="<?= (int)$c['city_id'] ?>"><?= e($c['city']) ?>, <?= e($c['province']) ?></option><?php endforeach; ?></select></div>
    <div class="u-grow-220"><label for="ab-name">Barangay</label><input id="ab-name" name="barangay" maxlength="<?= BARANGAY_MAX ?>" required></div>
    <button class="btn btn-primary">Add barangay</button></form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id      = (int)($_POST['driver_id'] ?? 0);
            $name    = trim($_POST['driver_name'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $vehicle = trim($_POST['vehicle'] ?? '');
            if ($name === '') throw new UserFacingException('Enter the driver\'s name.');
            if ($id) {
                $pdo->prepare('UPDATE drivers SET driver_name=?,phone=?,vehicle=? WHERE driver_id=?')->execute([$name, $phone ?: null, $vehicle ?: null, $id]);
                log_activity($pdo, 'driver_update', 'driver', $id, $name);
                flash('Driver updated.');
            } else {
                $pdo->prepare('INSERT INTO drivers(driver_name,phone,vehicle) VALUES(?,?,?)')->execute([$name, $phone ?: null, $vehicle ?: null]);
                log_activity($pdo, 'driver_create', 'driver', (int)$pdo->lastInsertId(), $name);
                flash('Driver added.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['driver_id'] ?? 0);
            $open = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE driver_id=? AND status IN ('Assigned','Out for Delivery')");
            $open->execute([$id]);
            $s = $pdo->prepare('SELECT driver_name,is_active FROM drivers WHERE driver_id=?');
            $s->execute([$id]);
            $d = $s->fetch();
            if (!$d) throw new UserFacingException('Driver not found.');
            if ((int)$d['is_active'] === 1 && (int)$open->fetchColumn() > 0) {
                throw new UserFacingException('This driver still has open deliveries. Reassign or finish them before deactivating.');
            }
            $new = (int)$d['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE drivers SET is_active=? WHERE driver_id=?')->execute([$new, $id]);
            log_activity($pdo, $new ? 'driver_activate' : 'driver_deactivate', 'driver', $id, $d['driver_name']);
            flash($new ? 'Driver activated.' : 'Driver deactivated.');
        }
    } catch (Throwable $e) {
        // Our own UserFacingException messages are shown; anything else is logged and replaced by a generic line.
        flash(user_error_message($e, 'driver action'), 'error');
    }
    redirect('/admin/drivers.php');
}

$drivers = $pdo->query("SELECT dr.*,
    (SELECT COUNT(*) FROM deliveries d WHERE d.driver_id=dr.driver_id AND d.status IN ('Assigned','Out for Delivery')) open_count,
    (SELECT COUNT(*) FROM deliveries d WHERE d.driver_id=dr.driver_id AND d.status='Delivered') done_count
    FROM drivers dr ORDER BY dr.is_active DESC, dr.driver_name")->fetchAll();

$pageTitle = 'Drivers';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>Drivers</h1><p class="muted">People who deliver customer orders.</p></div><a class="btn btn-outline" href="deliveries.php">← Delivery Management</a></div>

<div class="card u-mb-18"><h2>Add a driver</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
    <div class="form-row">
      <div><label for="d-name">Name</label><input id="d-name" name="driver_name" maxlength="120" required></div>
      <div><label for="d-phone">Phone</label><input id="d-phone" name="phone" maxlength="40"></div>
    </div>
    <label for="d-vehicle">Vehicle (optional)</label><input id="d-vehicle" name="vehicle" maxlength="80" placeholder="e.g. Isuzu truck, plate ABC 123">
    <button class="btn btn-primary u-mt-14">Add driver</button>
  </form>
</div>

<div class="card"><div class="table-wrap"><table>
<thead><tr><th>Driver</th><th>Phone</th><th>Vehicle</th><th>Open</th><th>Delivered</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($drivers as $d): ?>
<tr>
  <td><b><?= e($d['driver_name']) ?></b></td><td><?= e($d['phone'] ?? '—') ?></td><td><?= e($d['vehicle'] ?? '—') ?></td>
  <td><?= (int)$d['open_count'] ?></td><td><?= (int)$d['done_count'] ?></td>
  <td><span class="pill <?= $d['is_active'] ? 's-completed' : 's-cancelled' ?>"><?= $d['is_active'] ? 'Active' : 'Inactive' ?></span></td>
  <td>
    <button class="btn btn-outline btn-sm" type="button" onclick='openDriver(<?= (int)$d['driver_id'] ?>,<?= json_encode($d['driver_name']) ?>,<?= json_encode($d['phone'] ?? '') ?>,<?= json_encode($d['vehicle'] ?? '') ?>)'>Edit</button>
    <form class="u-inline" method="post"<?= $d['is_active'] ? ' onsubmit="return confirm('.e(json_encode('Deactivate '.$d['driver_name'].'? They will no longer appear when assigning deliveries. Existing completed deliveries are not affected. You can activate them again later.')).')"' : '' ?>><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="driver_id" value="<?= (int)$d['driver_id'] ?>"><button class="btn btn-outline btn-sm"><?= $d['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$drivers): ?><tr><td colspan="7" class="muted">No drivers yet. Add one above.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal-backdrop" id="driver-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="driver-title">
  <button class="modal-close" type="button" onclick="closeDriver()">×</button>
  <h2 id="driver-title">Edit driver</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="driver_id" id="edit-id">
    <label for="edit-name">Name</label><input id="edit-name" name="driver_name" maxlength="120" required>
    <label for="edit-phone">Phone</label><input id="edit-phone" name="phone" maxlength="40">
    <label for="edit-vehicle">Vehicle</label><input id="edit-vehicle" name="vehicle" maxlength="80">
    <div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeDriver()">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</div></div>
<script>
function openDriver(id,name,phone,vehicle){document.getElementById('edit-id').value=id;document.getElementById('edit-name').value=name;document.getElementById('edit-phone').value=phone;document.getElementById('edit-vehicle').value=vehicle;document.getElementById('driver-modal').hidden=false}
function closeDriver(){document.getElementById('driver-modal').hidden=true}
document.getElementById('driver-modal').addEventListener('click',function(e){if(e.target.id==='driver-modal')closeDriver()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDriver()});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

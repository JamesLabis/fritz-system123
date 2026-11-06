<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/functions.php';
require_admin();
$defaultThreshold=(int)($pdo->query("SELECT setting_value FROM buildsmart_settings WHERE setting_key='default_low_stock_threshold'")->fetchColumn() ?: 5);

function save_product_image(string $field, string $viewLabel): array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'The '.$viewLabel.' image could not be uploaded. Please try again.'];
    if (!is_uploaded_file($f['tmp_name']) || $f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) return [null, 'Each image must be a valid file under 2 MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'][$mime] ?? null;
    if (!$ext || @getimagesize($f['tmp_name']) === false) return [null, 'Only valid JPG, PNG and WebP images are allowed.'];
    $dir = __DIR__.'/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return [null, 'The uploads folder could not be created.'];
    $filename = date('YmdHis').'-'.bin2hex(random_bytes(10)).'.'.$ext;
    if (!move_uploaded_file($f['tmp_name'], $dir.'/'.$filename)) return [null, 'The image could not be saved. Check uploads folder permissions.'];
    return [$filename, null];
}
function remove_product_image(?string $filename): void {
    if ($filename) { $path = __DIR__.'/../uploads/'.basename($filename); if (is_file($path)) @unlink($path); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['product_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $price = round((float)($_POST['price'] ?? 0), 2);
        $costRaw = trim((string)($_POST['cost_price'] ?? '')); $cost = $costRaw === '' ? null : round((float)$costRaw, 2);
        $stock = max(0, (int)($_POST['stock_quantity'] ?? 0));
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $threshold = max(0, (int)($_POST['low_stock_threshold'] ?? 5));
        $unit = trim($_POST['unit'] ?? 'piece') ?: 'piece';
        $cat = (int)($_POST['category_id'] ?? 0) ?: null;
        [$img, $err1] = save_product_image('image', 'front');
        [$detailImg, $err2] = save_product_image('detail_image', 'second-angle');
        $err = $err1 ?: $err2;
        $frontAlt = trim($_POST['image_alt'] ?? '');
        $detailAlt = trim($_POST['detail_image_alt'] ?? '');
        if ($err) {
            remove_product_image($img); remove_product_image($detailImg); flash($err, 'error');
        } elseif ($name === '') {
            remove_product_image($img); remove_product_image($detailImg); flash('Product name is required.', 'error');
        } elseif ($cost !== null && ($cost < 0 || $cost > MAX_PRICE)) {
            remove_product_image($img); remove_product_image($detailImg); flash('Cost price must be between '.money(0).' and '.money(MAX_PRICE).', or left blank.', 'error');
        } elseif ($price < MIN_PRICE || $price > MAX_PRICE) {
            remove_product_image($img); remove_product_image($detailImg); flash('Price must be between '.money(MIN_PRICE).' and '.money(MAX_PRICE).'.', 'error');
        } else {
            if ($frontAlt === '') $frontAlt = $name.', front view';
            if ($detailAlt === '') $detailAlt = $name.', second view';
            if ($id) {
                $q = $pdo->prepare('SELECT image, detail_image FROM products WHERE product_id=?'); $q->execute([$id]); $old = $q->fetch() ?: [];
                $sql = 'UPDATE products SET product_name=?,sku=?,description=?,price=?,cost_price=?,stock_quantity=?,low_stock_threshold=?,unit=?,category_id=?,image_alt=?,detail_image_alt=?';
                $args = [$name,($sku!==''?$sku:null),$desc,$price,$cost,$stock,$threshold,$unit,$cat,$frontAlt,$detailAlt];
                if ($img) { $sql .= ',image=?'; $args[] = $img; }
                if ($detailImg) { $sql .= ',detail_image=?'; $args[] = $detailImg; }
                $sql .= ' WHERE product_id=?'; $args[] = $id;
                $pdo->prepare($sql)->execute($args);
                if ($img) remove_product_image($old['image'] ?? null);
                if ($detailImg) remove_product_image($old['detail_image'] ?? null);
            } else {
                $q = $pdo->prepare('INSERT INTO products(admin_id,category_id,product_name,sku,description,price,cost_price,stock_quantity,low_stock_threshold,unit,image,image_alt,detail_image,detail_image_alt) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$_SESSION['admin_id'],$cat,$name,($sku!==''?$sku:null),$desc,$price,$cost,$stock,$threshold,$unit,$img,$frontAlt,$detailImg,$detailAlt]);
            }
            $loggedId = $id ?: (int)$pdo->lastInsertId();
            if ($sku==='') {
                $lastId = $id ?: (int)$pdo->lastInsertId();
                $generated = 'BS-'.str_pad((string)$lastId,6,'0',STR_PAD_LEFT);
                $pdo->prepare("UPDATE products SET sku=? WHERE product_id=? AND (sku IS NULL OR sku='')")->execute([$generated,$lastId]);
            }
            log_activity($pdo, $id ? 'product_update' : 'product_create', 'product', $loggedId, $name.' - price '.$price.', stock '.$stock);
            flash('Product saved successfully.');
        }
    }
    if ($action === 'delete') {
        $did = (int)($_POST['product_id'] ?? 0);
        $q = $pdo->prepare('SELECT product_name,image,detail_image FROM products WHERE product_id=?'); $q->execute([$did]); $old = $q->fetch() ?: [];
        try { $pdo->prepare('DELETE FROM products WHERE product_id=?')->execute([$did]); log_activity($pdo,'product_delete','product',$did,(string)($old['product_name']??'')); remove_product_image($old['image'] ?? null); remove_product_image($old['detail_image'] ?? null); flash('Product deleted.'); }
        catch (PDOException $e) { flash('Cannot delete a product already used in an order or cart.', 'error'); }
    }
    redirect('/admin/products.php');
}
$edit = null;
if (isset($_GET['edit'])) { $s=$pdo->prepare('SELECT * FROM products WHERE product_id=?'); $s->execute([(int)$_GET['edit']]); $edit=$s->fetch(); }
$cats=$pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
$products=$pdo->query('SELECT p.*,c.category_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id ORDER BY p.product_name')->fetchAll();
$pageTitle='Manage Products'; require __DIR__.'/../includes/header.php'; require __DIR__.'/nav.php';
?>
<div class="page-head"><div><h1>Product Management</h1><p class="muted">Add, edit, and maintain hardware products.</p></div></div>
<div class="card u-mb-18"><h2><?= $edit?'Edit Product':'Add Product' ?></h2>
<form method="post" enctype="multipart/form-data" id="product-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="<?= (int)($edit['product_id']??0) ?>">
<div class="form-row">
<div><label>Product name</label><input id="product-name" name="product_name" required value="<?= e($edit['product_name']??'') ?>"></div>
<div><label>SKU / Barcode code</label><input name="sku" maxlength="50" value="<?= e($edit['sku']??'') ?>" placeholder="Leave blank to auto-generate"></div>
<div><label>Low-stock alert threshold</label><input type="number" min="0" name="low_stock_threshold" value="<?= e((string)($edit['low_stock_threshold']??$defaultThreshold)) ?>"></div>
<div><label>Category</label><select name="category_id"><option value="">Uncategorized</option><?php foreach($cats as $c): ?><option value="<?= $c['category_id'] ?>" <?= (int)($edit['category_id']??0)===(int)$c['category_id']?'selected':'' ?>><?= e($c['category_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Price (₱)</label><input type="number" step="0.01" min="<?= MIN_PRICE ?>" max="<?= MAX_PRICE ?>" name="price" required value="<?= e((string)($edit['price']??'')) ?>"></div>
<div><label>Cost price (₱, optional, for profit reports)</label><input type="number" step="0.01" min="0" max="<?= MAX_PRICE ?>" name="cost_price" value="<?= e((string)($edit['cost_price']??'')) ?>"></div>
<div><label>Stock quantity</label><input type="number" min="0" name="stock_quantity" required value="<?= e((string)($edit['stock_quantity']??'0')) ?>"></div>
<div><label>Unit</label><input name="unit" value="<?= e($edit['unit']??'piece') ?>"></div>
<div><label>Description</label><input name="description" value="<?= e($edit['description']??'') ?>"></div>
</div>
<div class="image-upload-grid">
 <section class="image-upload-box"><h3>Front photo</h3><p class="muted small">Main product photo · JPG, PNG or WebP · max 2 MB</p><input type="file" name="image" id="front-image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><label for="front-alt">Image description (alt text)</label><input id="front-alt" name="image_alt" maxlength="255" placeholder="e.g. HP laptop, front view" value="<?= e($edit['image_alt']??'') ?>"><div class="image-preview" id="front-preview"><?php if(!empty($edit['image'])): ?><img src="<?= BASE_URL ?>/uploads/<?= rawurlencode(basename($edit['image'])) ?>" alt="Current front photo preview"><?php else: ?><span>No image selected</span><?php endif; ?></div></section>
 <section class="image-upload-box"><h3>Second-angle photo <span class="muted small">(optional)</span></h3><p class="muted small">Side or back view · JPG, PNG or WebP · max 2 MB</p><input type="file" name="detail_image" id="detail-image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><label for="detail-alt">Image description (alt text)</label><input id="detail-alt" name="detail_image_alt" maxlength="255" placeholder="e.g. HP laptop, side view" value="<?= e($edit['detail_image_alt']??'') ?>"><div class="image-preview" id="detail-preview"><?php if(!empty($edit['detail_image'])): ?><img src="<?= BASE_URL ?>/uploads/<?= rawurlencode(basename($edit['detail_image'])) ?>" alt="Current second-angle photo preview"><?php else: ?><span>No image selected</span><?php endif; ?></div></section>
</div>
<button class="btn btn-primary u-mt-15">Save Product</button> <?php if($edit): ?><a class="btn btn-outline" href="products.php">Cancel</a><?php endif; ?></form></div>
<div class="card product-admin-tools"><input id="product-search" type="search" placeholder="Search product name or category"><select id="product-category"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?= e(strtolower($c['category_name'])) ?>"><?= e($c['category_name']) ?></option><?php endforeach; ?></select><span class="muted small" id="product-count"><?= count($products) ?> products</span></div>
<div class="table-wrap"><table id="admin-products-table"><thead><tr><th>Product / SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Actions</th></tr></thead><tbody><?php if(!$products): ?><tr><td colspan="5" class="muted">No products yet. Use the form above to add the first one.</td></tr><?php endif; ?><?php foreach($products as $p): ?><tr class="<?= (int)$p['stock_quantity']<=(int)($p['low_stock_threshold']??5)?'low-stock-row':'' ?>" data-name="<?= e(strtolower($p['product_name'])) ?>" data-category="<?= e(strtolower($p['category_name']??'')) ?>"><td><div class="cart-prod"><div class="thumb"><?= product_visual($p) ?></div><strong><?= e($p['product_name']) ?></strong><div class="small muted"><?= e($p['sku']??'') ?></div></div></td><td><?= e($p['category_name']??'—') ?></td><td><?= money($p['price']) ?>/<?= e($p['unit']) ?></td><td><?= stock_badge((int)$p['stock_quantity']) ?><?php if((int)$p['stock_quantity']<=(int)($p['low_stock_threshold']??5)): ?><span class="small text-danger"> Low stock</span><?php endif; ?></td><td><a href="?edit=<?= $p['product_id'] ?>">Edit</a> <button type="button" class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= (int)$p['product_id'] ?>,<?= htmlspecialchars(json_encode($p['product_name']),ENT_QUOTES) ?>)">Delete</button></td></tr><?php endforeach; ?><tr id="product-no-results" hidden><td colspan="5" class="muted">No products match your filters.</td></tr></tbody></table></div>
<div class="modal-backdrop" id="delete-modal" hidden><div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="delete-title"><button class="modal-close" type="button" onclick="closeDeleteModal()">×</button><div class="delete-modal-icon">!</div><h2 id="delete-title">Delete product?</h2><p>Are you sure you want to delete <strong id="delete-product-name"></strong>? This action cannot be undone.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" id="delete-product-id"><div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Keep product</button><button class="btn btn-danger">Delete product</button></div></form></div></div>
<script>
(()=>{
 const pairs=[['front-image','front-preview'],['detail-image','detail-preview']];
 pairs.forEach(([inputId,previewId])=>{const input=document.getElementById(inputId),preview=document.getElementById(previewId);input.addEventListener('change',()=>{const file=input.files[0];if(!file)return;if(!['image/jpeg','image/png','image/webp'].includes(file.type)){alert('Choose a JPG, PNG or WebP image.');input.value='';return;}if(file.size>2*1024*1024){alert('Each image must be under 2 MB.');input.value='';return;}const img=document.createElement('img');img.alt='Selected image preview';img.src=URL.createObjectURL(file);preview.replaceChildren(img);});});
 const search=document.getElementById('product-search'),category=document.getElementById('product-category'),rows=[...document.querySelectorAll('#admin-products-table tbody tr[data-name]')],count=document.getElementById('product-count'),empty=document.getElementById('product-no-results');function filter(){const q=search.value.trim().toLowerCase(),c=category.value;let n=0;rows.forEach(r=>{const ok=(!q||r.dataset.name.includes(q)||r.dataset.category.includes(q))&&(!c||r.dataset.category===c);r.hidden=!ok;if(ok)n++;});empty.hidden=n!==0;count.textContent=n+' products';}search.addEventListener('input',filter);category.addEventListener('change',filter);
})();
function openDeleteModal(id,name){document.getElementById('delete-product-id').value=id;document.getElementById('delete-product-name').textContent=name;document.getElementById('delete-modal').hidden=false;document.querySelector('#delete-modal .modal-close')?.focus()}function closeDeleteModal(){document.getElementById('delete-modal').hidden=true}document.getElementById('delete-modal').addEventListener('click',e=>{if(e.target.id==='delete-modal')closeDeleteModal()});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDeleteModal()});
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>

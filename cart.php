<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/functions.php';
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

// Return JSON for asynchronous cart requests instead of redirecting to an HTML login page.
if (!current_user_id()) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Please sign in again before adding products to your cart.']);
        exit;
    }
    redirect('/login.php');
}

$cartId = get_or_create_cart($pdo, current_user_id());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF failures must also be valid JSON for the AJAX add-to-cart flow.
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals(csrf_token(), $postedToken)) {
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
            exit;
        }
        verify_csrf();
    }

    if ($action === 'buy_now') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 1);
        $check = $pdo->prepare('SELECT stock_quantity FROM products WHERE product_id=?');
        $check->execute([$pid]);
        $available = $check->fetchColumn();
        if ($pid < 1 || $qty < 1 || $qty > MAX_LINE_QTY || $available === false || $qty > (int)$available) {
            flash('The selected product does not have enough stock for that quantity.', 'error');
            redirect('/product.php?id='.$pid);
        }
        // Buy Now is a separate, one-product checkout. Existing cart items are untouched.
        $_SESSION['buildsmart_buy_now'] = ['product_id'=>$pid, 'quantity'=>$qty, 'created_at'=>time()]; // checkout.php accepts it for 15 minutes
        redirect('/checkout.php');
    }
    if ($action === 'add') {
        $buyNow = false;
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 1);
        if ($pid < 1 || $qty < 1 || $qty > MAX_LINE_QTY) {
            $message = 'Please choose a quantity between 1 and '.MAX_LINE_QTY.'.';
            $ok = false;
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $message, 'count' => cart_count($pdo)]);
                exit;
            }
            flash($message, 'error');
            redirect('/cart.php');
        }
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT stock_quantity FROM products WHERE product_id=? FOR UPDATE');
            $s->execute([$pid]);
            $stock = $s->fetchColumn();
            $s = $pdo->prepare('SELECT COALESCE(quantity,0) FROM cart_items WHERE cart_id=? AND product_id=? FOR UPDATE');
            $s->execute([$cartId, $pid]);
            $inCart = (int)$s->fetchColumn();

            if ($stock !== false && $qty + $inCart > MAX_LINE_QTY) {
                $pdo->rollBack();
                $message = 'You can order at most '.MAX_LINE_QTY.' of one product at a time.';
                $ok = false;
            } elseif ($stock === false || (int)$stock <= 0 || (int)$stock < $qty + $inCart) {
                $pdo->rollBack();
                $message = 'This product is unavailable or there is not enough stock.';
                $ok = false;
            } else {
                $s = $pdo->prepare('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)');
                $s->execute([$cartId, $pid, $qty]);
                $pdo->commit();
                $message = $buyNow ? 'Product added. Continue to checkout to place your order.' : 'Added to your cart.';
                $ok = true;
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('BuildSmart add-to-cart error: ' . $error->getMessage());
            $message = 'We could not add this product right now. Please refresh and try again.';
            $ok = false;
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'message' => $message, 'count' => cart_count($pdo)]);
            exit;
        }
        flash($message, $ok ? 'success' : 'error');
        redirect($ok && $buyNow ? '/checkout.php' : '/cart.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['cart_item_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        if ($qty < 1) {
            $s = $pdo->prepare('DELETE FROM cart_items WHERE cart_item_id=? AND cart_id=?');
            $s->execute([$id, $cartId]);
        } else {
            $check = $pdo->prepare('SELECT p.stock_quantity FROM cart_items ci JOIN products p ON p.product_id=ci.product_id WHERE ci.cart_item_id=? AND ci.cart_id=?');
            $check->execute([$id, $cartId]);
            $stock = $check->fetchColumn();
            if ($stock === false) {
                flash('That cart item could not be found.', 'error');
            } elseif ((int)$stock <= 0) {
                flash('This product is out of stock. Remove it from your cart or try again when stock is available.', 'error');
            } elseif ($qty > MAX_LINE_QTY) {
                flash('You can order at most '.MAX_LINE_QTY.' of one product at a time.', 'error');
            } elseif ($qty > (int)$stock) {
                flash('Only '.(int)$stock.' unit(s) are currently available. Your cart quantity was not changed.', 'error');
            } else {
                $s = $pdo->prepare('UPDATE cart_items SET quantity=? WHERE cart_item_id=? AND cart_id=?');
                $s->execute([$qty, $id, $cartId]);
                flash('Cart updated.');
            }
        }
    }
    if ($action === 'remove') {
        $s = $pdo->prepare('DELETE FROM cart_items WHERE cart_item_id=? AND cart_id=?');
        $s->execute([(int)($_POST['cart_item_id'] ?? 0), $cartId]);
        flash('Item removed.');
    }
    redirect('/cart.php');
}
unset($_SESSION['buildsmart_buy_now']); // a stale buy-now must not hijack checkout from the cart
$s=$pdo->prepare('SELECT ci.*,p.product_name,p.price,p.stock_quantity,p.unit,p.image,c.category_name,(ci.quantity*p.price) subtotal FROM cart_items ci JOIN products p ON p.product_id=ci.product_id LEFT JOIN categories c ON c.category_id=p.category_id WHERE ci.cart_id=?');$s->execute([$cartId]);$items=$s->fetchAll();$total=array_sum(array_column($items,'subtotal'));
$pageTitle='Shopping Cart';require __DIR__.'/includes/header.php';
?>
<div class="checkout-steps"><span class="step active">1 <b>Cart</b></span><i></i><span class="step">2 <b>Details</b></span><i></i><span class="step">3 <b>Receipt</b></span></div>
<div class="page-head"><div><h1>Shopping Cart</h1><p class="muted">Review your selected hardware products.</p></div><a href="<?=BASE_URL?>/" class="btn btn-outline">Continue Shopping</a></div>
<?php if(!$items):?><div class="card empty state-empty"><?=empty_art('Your cart is empty')?><h2>Your cart is waiting</h2><p>Add products to get your project moving.</p><a href="<?=BASE_URL?>/" class="btn btn-primary">Browse products</a></div><?php else:?><div class="cart-layout"><div class="table-wrap"><table><thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th></th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><div class="cart-prod"><div class="thumb"><?=product_visual($it)?></div><div><a href="<?=BASE_URL?>/product.php?id=<?=(int)$it['product_id']?>"><?=e($it['product_name'])?></a><div class="small muted"><?=(int)$it['stock_quantity']?> available</div></div></div></td><td><?=money($it['price'])?></td><td><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="cart_item_id" value="<?=$it['cart_item_id']?>"><input type="number" name="quantity" min="1" max="<?=$it['stock_quantity']?>" value="<?=$it['quantity']?>" class="u-w-75"><button class="btn btn-outline btn-sm">Update</button></form></td><td><?=money($it['subtotal'])?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="cart_item_id" value="<?=$it['cart_item_id']?>"><button class="btn btn-danger btn-sm">Remove</button></form></td></tr><?php endforeach;?></tbody></table></div><aside class="card cart-summary"><h2>Order Summary</h2><p><?=count($items)?> line item<?=count($items)===1?'':'s'?> <strong><?=cart_count($pdo)?> pcs</strong></p><p>Subtotal <strong><?=money($total)?></strong></p><p class="summary-total">Total <strong><?=money($total)?></strong></p><small class="muted">Delivery fees, if applicable, will be confirmed during order processing.</small><a class="btn btn-primary btn-block" href="<?=BASE_URL?>/checkout.php">Proceed to Checkout</a></aside></div><?php endif;?>
<?php require __DIR__.'/includes/footer.php'; ?>

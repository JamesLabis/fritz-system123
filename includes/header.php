<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? 'BuildSmart';
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cur = basename($_SERVER['SCRIPT_NAME']);
// A Buy Now selection only lives for the checkout it started: opening any other page cancels it.
if ($cur !== 'checkout.php' && isset($_SESSION['buildsmart_buy_now'])) unset($_SESSION['buildsmart_buy_now']);
$on = fn(array $f) => in_array($cur, $f, true) ? ' class="on"' : '';
$isAdminArea = is_admin() && str_contains($_SERVER['SCRIPT_NAME'], '/admin/');
$isCustomerArea = current_user_id() && !is_admin() && !in_array($cur, ['login.php','register.php'], true);

/* ---------- Breadcrumbs: a page may set $breadcrumbs itself; otherwise built here ---------- */
if (!isset($breadcrumbs)) {
    $qid = (int)($_GET['id'] ?? 0);
    $oid = (int)($_GET['order_id'] ?? 0);
    if ($isAdminArea) {
        $labels = ['dashboard.php'=>'Dashboard','products.php'=>'Products','orders.php'=>'Orders','inventory.php'=>'Inventory','reports.php'=>'Sales Reports','users.php'=>'Users','reviews.php'=>'Review Moderation','settings.php'=>'Profile','search.php'=>'Search results'];
        $label = $labels[$cur] ?? $pageTitle;
        $breadcrumbs = [['Admin', BASE_URL . '/admin/dashboard.php']];
        if ($cur === 'orders.php' && $qid) { $breadcrumbs[] = ['Orders', BASE_URL . '/admin/orders.php']; $breadcrumbs[] = ['Order #' . $qid, null]; }
        elseif ($cur === 'products.php' && isset($_GET['edit'])) { $breadcrumbs[] = ['Products', BASE_URL . '/admin/products.php']; $breadcrumbs[] = ['Edit product', null]; }
        else $breadcrumbs[] = [$label, null];
    } else {
        $home = ['Home', BASE_URL . '/'];
        switch ($cur) {
            case 'index.php':        $breadcrumbs = []; break;
            case 'cart.php':         $breadcrumbs = [$home, ['Cart', null]]; break;
            case 'checkout.php':     $breadcrumbs = [$home, ['Cart', BASE_URL . '/cart.php'], ['Checkout', null]]; break;
            case 'my_orders.php':    $breadcrumbs = [$home, ['My Orders', null]]; break;
            case 'order_detail.php': $breadcrumbs = [$home, ['My Orders', BASE_URL . '/my_orders.php'], ['Order #' . $qid, null]]; break;
            case 'receipt.php':
                $root = is_admin() ? [['Admin', BASE_URL . '/admin/dashboard.php'], ['Orders', BASE_URL . '/admin/orders.php']] : [$home, ['My Orders', BASE_URL . '/my_orders.php']];
                $ordUrl = is_admin() ? BASE_URL . '/admin/orders.php?id=' . $oid : BASE_URL . '/order_detail.php?id=' . $oid;
                $breadcrumbs = array_merge($root, [['Order #' . $oid, $ordUrl], ['Receipt', null]]); break;
            case 'account.php':      $breadcrumbs = [$home, ['My Profile', null]]; break;
            case 'login.php':        $breadcrumbs = [$home, ['Login', null]]; break;
            case 'register.php':     $breadcrumbs = [$home, ['Register', null]]; break;
            default:                 $breadcrumbs = [$home, [$pageTitle, null]];
        }
    }
}

/* ---------- Admin top-bar data: notifications ---------- */
if ($isCustomerArea) { $cartN = cart_count($pdo); $catActive = $cur === 'index.php' && !empty($_GET['category']); $unreadStmt=$pdo->prepare('SELECT COUNT(*) FROM customer_notifications WHERE user_id=? AND is_read=0'); $unreadStmt->execute([current_user_id()]); $unreadCustomerNotifications=(int)$unreadStmt->fetchColumn(); }

if ($isAdminArea) {
    $notifPending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status='Pending'")->fetchColumn();
    $notifLow = $pdo->query('SELECT product_id,product_name,stock_quantity,unit FROM products WHERE stock_quantity<=low_stock_threshold ORDER BY stock_quantity,product_name LIMIT 5')->fetchAll();
    $notifLowCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity<=low_stock_threshold')->fetchColumn();
    $notifTotal = $notifPending + $notifLowCount;
    $adminName = $_SESSION['display_name'] ?? 'Admin';
    $initial = strtoupper(mb_substr(trim($adminName) ?: 'A', 0, 1));
    // Admin navigation follows the recommended BuildSmart management workflow.
    $side = [
        ['dashboard.php', 'Dashboard', 'dashboard', ['dashboard.php']],
        ['products.php', 'Product Management', 'box', ['products.php']],
        ['inventory.php', 'Inventory Management', 'inventory', ['inventory.php']],
        ['orders.php', 'Order Management', 'orders', ['orders.php']],
        ['deliveries.php', 'Delivery Management', 'truck', ['deliveries.php', 'drivers.php', 'zones.php', 'supplier_delivery.php']],
        ['users.php', 'Customer Management', 'users', ['users.php']],
        ['reviews.php', 'Review Moderation', 'clipboard-list', ['reviews.php']],
        ['dashboard.php#sales-analytics', 'Sales Analytics', 'trend', []],
        ['reports.php', 'Reports', 'report', ['reports.php']],
        ['activity_logs.php', 'Activity Logs', 'clipboard-list', ['activity_logs.php']],
        ['feature_settings.php', 'Settings', 'settings', ['feature_settings.php', 'settings.php']],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | BuildSmart</title>
<script>try{var t=localStorage.getItem("bs-theme");if(t!=="light"&&t!=="dark")t=matchMedia("(prefers-color-scheme:dark)").matches?"dark":"light";document.documentElement.setAttribute("data-theme",t)}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body<?= ($isAdminArea || $isCustomerArea) ? ' class="admin-body customer-portal-body"' : '' ?>>
<a class="skip-link" href="#main-content">Skip to main content</a>
<noscript><div class="noscript-banner" role="alert">JavaScript is turned off. Browsing works, but adding to cart, checkout confirmation and some filters need JavaScript. Please enable it in your browser.</div></noscript>
<?php $themeBtn = '<button type="button" class="icon-btn theme-toggle" data-theme-toggle aria-label="Switch to dark mode" aria-pressed="false"><span class="ti-sun">' . icon('sun') . '</span><span class="ti-moon">' . icon('moon') . '</span></button>'; ?>
<?php $logo = '<svg viewBox="0 0 40 40"><path d="M20 2l16 9v18l-16 9L4 29V11z" fill="#ffc20e"/><path d="M20 10l9 5v10l-9 5-9-5V15z" fill="#14181f"/><circle cx="20" cy="20" r="4" fill="#ffc20e"/></svg>'; ?>
<?php if ($isAdminArea): ?>
<div class="admin-shell">
  <aside class="admin-side" id="adminSide" aria-label="Admin navigation">
    <div class="side-head">
      <a class="brand" href="<?= BASE_URL ?>/admin/dashboard.php"><?= $logo ?>Build<span>Smart</span></a>
      <button type="button" class="icon-btn side-close" data-side-close aria-label="Close menu"><?= icon('close') ?></button>
    </div>
    <nav class="side-nav">
      <?php $sysFiles = ['reports.php','activity_logs.php','feature_settings.php','settings.php']; $sideBadge = ['orders.php' => [$notifPending, 'pending'], 'inventory.php' => [$notifLowCount, 'low stock']]; ?>
      <?php foreach ($side as $sideIndex => [$file, $label, $ic, $match]): ?>
        <?php if (in_array($sideIndex, [0,1,4], true)): ?><div class="side-group-label"><?= [0=>'Overview',1=>'Sales',4=>'Operations'][$sideIndex] ?></div><?php endif; ?>
        <?php if ($sideIndex === 7): ?><details class="side-more"<?= in_array($cur, $sysFiles, true) ? ' open' : '' ?>><summary class="side-group-label">System <?= icon('chevron-down') ?></summary><?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/<?= $file ?>"<?= in_array($cur, $match, true) ? ' class="on" aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span><?php $bd = $sideBadge[$file] ?? null; if ($bd && $bd[0] > 0): ?><small class="side-count" aria-label="<?= (int)$bd[0] ?> <?= $bd[1] ?>"><?= $bd[0] > 99 ? '99+' : (int)$bd[0] ?></small><?php endif; ?></a>
      <?php endforeach; ?>
      </details>
    </nav>
    <div class="side-foot">
      <a href="<?= BASE_URL ?>/"><?= icon('store') ?><span>View store</span></a>
      <a href="<?= BASE_URL ?>/logout.php?t=<?= e(csrf_token()) ?>" data-logout-trigger><?= icon('logout') ?><span>Log out</span></a>
    </div>
  </aside>
  <div class="admin-overlay" data-side-close></div>
  <div class="admin-wrap">
    <header class="admin-top">
      <button type="button" class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="adminSide" aria-expanded="false"><?= icon('menu') ?></button>
      <form class="admin-gsearch" action="<?= BASE_URL ?>/admin/search.php" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($cur === 'search.php' ? ($_GET['q'] ?? '') : '') ?>" placeholder="Search products, orders, customers…" aria-label="Search the admin area">
      </form>
      <div class="top-actions">
        <?= $themeBtn ?>
        <div class="dd" data-dd>
          <button type="button" class="icon-btn" data-dd-btn aria-haspopup="true" aria-expanded="false" aria-label="Notifications (<?= $notifTotal ?>)">
            <?= icon('bell') ?><?php if ($notifTotal): ?><span class="dot-badge"><?= $notifTotal > 99 ? '99+' : $notifTotal ?></span><?php endif; ?>
          </button>
          <div class="dd-panel notif-panel">
            <div class="dd-title">Notifications</div>
            <?php if (!$notifTotal): ?>
              <p class="dd-empty">You’re all caught up.</p>
            <?php else: ?>
              <?php if ($notifPending): ?><a class="dd-item" href="<?= BASE_URL ?>/admin/orders.php?status=Pending"><b><?= $notifPending ?> pending order<?= $notifPending === 1 ? '' : 's' ?></b><small>Waiting for confirmation</small></a><?php endif; ?>
              <?php foreach ($notifLow as $lp): ?><a class="dd-item" href="<?= BASE_URL ?>/admin/inventory.php"><b><?= e($lp['product_name']) ?></b><small class="<?= (int)$lp['stock_quantity'] === 0 ? 'text-danger' : '' ?>"><?= (int)$lp['stock_quantity'] === 0 ? 'Out of stock' : 'Low stock: ' . (int)$lp['stock_quantity'] . ' ' . e($lp['unit']) . ' left' ?></small></a><?php endforeach; ?>
              <?php if ($notifLowCount > count($notifLow)): ?><a class="dd-item dd-more" href="<?= BASE_URL ?>/admin/inventory.php">+<?= $notifLowCount - count($notifLow) ?> more low-stock items</a><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="dd" data-dd>
          <button type="button" class="profile-btn" data-dd-btn aria-haspopup="true" aria-expanded="false">
            <span class="avatar"><?= e($initial) ?></span><span class="profile-name"><?= e($adminName) ?></span>
          </button>
          <div class="dd-panel profile-panel">
            <div class="dd-title"><?= e($adminName) ?><small>Administrator</small></div>
            <a class="dd-item" href="<?= BASE_URL ?>/admin/settings.php">Profile</a>
            <a class="dd-item" href="<?= BASE_URL ?>/">View store</a>
            <a class="dd-item" href="<?= BASE_URL ?>/logout.php?t=<?= e(csrf_token()) ?>" data-logout-trigger>Log out</a>
          </div>
        </div>
      </div>
    </header>
    <main class="admin-main" id="main-content" tabindex="-1">
<?php elseif ($isCustomerArea): ?>
<div class="admin-shell customer-shell">
  <aside class="admin-side customer-side" id="adminSide" aria-label="Customer navigation">
    <div class="side-head"><a class="brand" href="<?= BASE_URL ?>/"><?= $logo ?>Build<span>Smart</span></a><button type="button" class="icon-btn side-close" data-side-close aria-label="Close menu"><?= icon('close') ?></button></div>
    <div class="customer-side-label">CUSTOMER PORTAL</div>
    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/"<?= (in_array($cur, ['index.php','product.php'], true) && !$catActive) ? ' class="on" aria-current="page"' : '' ?>><?= icon('store') ?><span>Shop</span></a>
      <a href="<?= BASE_URL ?>/#categories"<?= $catActive ? ' class="on" aria-current="page"' : '' ?>><?= icon('inventory') ?><span>Categories</span></a>
      <a href="<?= BASE_URL ?>/cart.php"<?= in_array($cur, ['cart.php','checkout.php'], true) ? ' class="on" aria-current="page"' : '' ?>><?= icon('cart') ?><span>My Cart</span><small class="customer-nav-count cart-badge"<?= $cartN ? '' : ' hidden' ?>><?= $cartN ?></small></a>
      <a href="<?= BASE_URL ?>/my_orders.php"<?= in_array($cur, ['my_orders.php','order_detail.php','receipt.php'], true) ? ' class="on" aria-current="page"' : '' ?>><?= icon('receipt') ?><span>My Orders</span></a>
      <a href="<?= BASE_URL ?>/wishlist.php"<?= $cur === 'wishlist.php' ? ' class="on" aria-current="page"' : '' ?>><?= icon('heart') ?><span>Wishlist</span></a>
      <a href="<?= BASE_URL ?>/notifications.php"<?= $cur === 'notifications.php' ? ' class="on" aria-current="page"' : '' ?>><?= icon('bell') ?><span>Notifications</span><?php if($unreadCustomerNotifications): ?><small class="customer-nav-count"><?= $unreadCustomerNotifications>99?'99+':$unreadCustomerNotifications ?></small><?php endif; ?></a>
      <a href="<?= BASE_URL ?>/account.php"<?= $cur === 'account.php' ? ' class="on" aria-current="page"' : '' ?>><?= icon('users') ?><span>My Profile</span></a>
    </nav>
    <div class="side-foot"><a href="<?= BASE_URL ?>/logout.php?t=<?= e(csrf_token()) ?>" data-logout-trigger><?= icon('logout') ?><span>Log out</span></a></div>
  </aside>
  <div class="admin-overlay" data-side-close></div>
  <div class="admin-wrap">
    <header class="admin-top customer-top" id="customerTop">
      <button type="button" class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="adminSide" aria-expanded="false"><?= icon('menu') ?></button>
      <div class="customer-welcome"><strong>Welcome back, <?= e($_SESSION['display_name'] ?? 'Customer') ?>!</strong><small>Customer portal</small></div>
      <form class="admin-gsearch customer-gsearch" id="customerSearch" action="<?= BASE_URL ?>/" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($cur === 'index.php' ? ($_GET['q'] ?? '') : '') ?>" placeholder="Search hardware…" aria-label="Search hardware products">
      </form>
      <div class="top-actions">
        <button type="button" class="icon-btn search-toggle" data-search-toggle="#customerSearch" aria-label="Search" aria-expanded="false"><?= icon('search') ?></button>
        <?= $themeBtn ?>
        <a class="icon-btn cart-btn" href="<?= BASE_URL ?>/cart.php" aria-label="Cart"><?= icon('cart') ?><span class="dot-badge cart-badge"<?= $cartN ? '' : ' hidden' ?>><?= $cartN ?></span></a>
        <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr(trim($_SESSION['display_name'] ?? 'C') ?: 'C', 0, 1))) ?></span>
      </div>
    </header>
    <main class="admin-main customer-main" id="main-content" tabindex="-1">
<?php else: ?>
<header class="topbar">
  <div class="container nav-wrap">
    <a class="brand" href="<?= BASE_URL ?>/"><?= $logo ?>Build<span>Smart</span></a>
    <button class="customer-menu-toggle" id="customerMenuBtn" type="button" aria-label="Open navigation menu" aria-controls="customerNav" aria-expanded="false"><span></span><span></span><span></span></button>
    <nav id="customerNav" aria-label="Main navigation">
      <a href="<?= BASE_URL ?>/"<?= $on(['index.php','product.php']) ?>>Browse Products</a>
      <?php if (current_user_id()): ?>
        <a href="<?= BASE_URL ?>/cart.php"<?= $on(['cart.php','checkout.php']) ?>>Cart <span class="cart-badge"><?= cart_count($pdo) ?></span></a>
        <a href="<?= BASE_URL ?>/my_orders.php"<?= $on(['my_orders.php','order_detail.php','receipt.php']) ?>>My Orders</a>
        <a href="<?= BASE_URL ?>/wishlist.php"<?= $on(['wishlist.php']) ?>>Wishlist</a>
        <a href="<?= BASE_URL ?>/notifications.php"<?= $on(['notifications.php']) ?>>Notifications</a>
        <a href="<?= BASE_URL ?>/account.php"<?= $on(['account.php']) ?>>My Account</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <a href="<?= BASE_URL ?>/admin/dashboard.php">Admin</a>
      <?php endif; ?>
    </nav>
    <form class="nav-search" id="navSearch" action="<?= BASE_URL ?>/" method="get" role="search">
      <input type="search" name="q" placeholder="Search hardware…" aria-label="Search hardware products">
      <button type="submit" aria-label="Search"><?= icon('search') ?></button>
    </form>
    <div class="nav-tools">
      <button type="button" class="icon-btn nav-search-toggle" data-search-toggle="#navSearch" aria-label="Search" aria-expanded="false"><?= icon('search') ?></button>
      <?= $themeBtn ?>
    </div>
    <div class="nav-account">
      <?php if (is_logged_in()): ?>
        <span class="muted small">Hello, <?= e($_SESSION['display_name'] ?? 'User') ?></span>
        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/logout.php?t=<?= e(csrf_token()) ?>" data-logout-trigger>Logout</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php">Login</a>
        <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/register.php">Register</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="container main" id="main-content" tabindex="-1">
<?php endif; ?>
<?= breadcrumbs($breadcrumbs) ?>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<?php if ($flashMessage): ?>
  <?php if (($flashMessage['type'] ?? '') === 'success'): ?><div class="flash-toast-source" data-flash-message="<?= e($flashMessage['message']) ?>" hidden></div><?php else: ?><div class="alert alert-error" role="alert"><?= e($flashMessage['message']) ?></div><?php endif; ?>
<?php endif; ?>

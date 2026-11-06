<?php
// Styled error page. Used three ways:
//  - Apache ErrorDocument (see .htaccess):          /error.php?code=404
//  - other pages:  $errorCode = 404; require __DIR__.'/error.php'; exit;
//  - the uncaught-exception handler in config/config.php (500)
require_once __DIR__.'/config/config.php'; require_once __DIR__.'/includes/functions.php';
$errorCode = (int)($errorCode ?? ($_GET['code'] ?? 404));
$errorPages = [
    403 => ['Not allowed', 'You do not have permission to open this page.'],
    404 => ['Page not found', 'We could not find what you were looking for. It may have moved or no longer exist.'],
    500 => ['Something went wrong', 'Something went wrong on our side and it has been logged. Please try again in a moment.'],
];
if (!isset($errorPages[$errorCode])) $errorCode = 404;
[$errorTitle, $errorText] = $errorPages[$errorCode];
if (!headers_sent()) http_response_code($errorCode);
$pageTitle = $errorTitle; $breadcrumbs = [];
require __DIR__.'/includes/header.php';
?>
<section class="card error-page" role="alert">
  <span class="eyebrow">Error <?= (int)$errorCode ?></span>
  <h1 class="u-mt-10"><?= e($errorTitle) ?></h1>
  <p class="lead"><?= e($errorText) ?></p>
  <div class="error-actions">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/">Back to products</a>
    <?php if (is_admin()): ?><a class="btn btn-outline" href="<?= BASE_URL ?>/admin/dashboard.php">Admin dashboard</a>
    <?php elseif (current_user_id()): ?><a class="btn btn-outline" href="<?= BASE_URL ?>/my_orders.php">My orders</a>
    <?php else: ?><a class="btn btn-outline" href="<?= BASE_URL ?>/login.php">Sign in</a><?php endif; ?>
    <?php if ($errorCode === 500): ?><a class="btn btn-outline" href="" onclick="location.reload();return false;">Try again</a><?php endif; ?>
  </div>
</section>
<?php require __DIR__.'/includes/footer.php'; ?>

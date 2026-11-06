<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'buildsmart');
define('DB_USER', 'root');
define('DB_PASS', '');
define('MSG_SESSION_EXPIRED', 'Your session expired, please sign in again.');
define('BUILDSMART_DEBUG', false); // set true on a dev machine to show PHP errors on screen; keep false for customers
// BASE_URL is detected automatically from the folder location inside htdocs,
// so the CSS and links work whatever the project folder is named.
$__root = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$__dir  = rtrim(str_replace('\\', '/', (string)realpath(__DIR__ . '/..')), '/');
$__base = '/BuildSmart';
if ($__root !== '' && stripos($__dir, $__root) === 0) {
    $__rel = substr($__dir, strlen($__root));
    $__base = implode('/', array_map('rawurlencode', explode('/', $__rel)));
}
define('BASE_URL', $__base);

date_default_timezone_set('Asia/Manila');

$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($__https) header('Strict-Transport-Security: max-age=15552000');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
// Idle timeout: 30 minutes for administrators, 8 hours for customers.
$__idle = isset($_SESSION['admin_id']) ? 1800 : 28800;
if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $__idle) {
    $__wasSignedIn = isset($_SESSION['user_id']) || isset($_SESSION['admin_id']);
    $_SESSION = [];
    session_regenerate_id(true);
    // Say why they are being asked to sign in again (the login page shows this).
    if ($__wasSignedIn) {
        $_SESSION['flash'] = ['message' => MSG_SESSION_EXPIRED, 'type' => 'error'];
    }
}
$_SESSION['last_seen'] = time();

// Safety net: an uncaught exception is logged in full and the visitor sees the styled 500 page,
// never a stack trace or file paths. Set BUILDSMART_DEBUG to true on a dev machine to see PHP errors on screen.
ini_set('log_errors', '1');
if (!BUILDSMART_DEBUG) {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e): void {
        error_log('BuildSmart uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        try {
            global $pdo;
            if (!function_exists('e')) require_once dirname(__DIR__) . '/includes/functions.php';
            $errorCode = 500;
            require dirname(__DIR__) . '/error.php';
        } catch (Throwable $inner) {
            // The error page itself failed (for example the database is down): fall back to plain text.
            error_log('BuildSmart error page failed: ' . $inner->getMessage());
            echo '<p style="font-family:sans-serif;padding:2rem">Something went wrong, please try again in a moment.</p>';
        }
    });
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    // Delivery workflow, refunds and audit log tables (runs once, then is skipped).
    require_once __DIR__ . '/migrate_delivery.php';
    buildsmart_migrate_delivery($pdo);
    // Core upgrades run once (see config/migrate_core.php); afterwards this is a single tiny SELECT.
    require_once __DIR__ . '/migrate_core.php';
    buildsmart_migrate_core($pdo);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('BuildSmart database error: ' . $e->getMessage());
    // Setup hints are for whoever installs the system; customers only get a plain message.
    exit(BUILDSMART_DEBUG
        ? 'The system is temporarily unavailable. Check config/config.php and that database/buildsmart.sql is imported (details are in the PHP error log).'
        : 'The system is temporarily unavailable. Please try again in a few minutes.');
}

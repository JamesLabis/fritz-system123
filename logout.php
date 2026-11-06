<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/functions.php';
// Logging out requires the CSRF token so another site cannot sign users out.
$t = $_GET['t'] ?? '';
if (is_string($t) && $t !== '' && hash_equals(csrf_token(), $t)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
header('Location: ' . BASE_URL . '/'); exit;

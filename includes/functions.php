<?php
require_once __DIR__ . '/art.php';
require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/maintenance.php';

/* ---------- Errors: only messages we wrote on purpose may reach the screen ---------- */
/** Thrown for problems the customer/admin can act on ("Insufficient stock", "Enter a name"). Its message is shown as-is. */
class UserFacingException extends Exception {}
/** A stock shortage; carries the numbers so the page can offer a way forward. */
class InsufficientStockException extends UserFacingException {
    public int $productId; public int $available; public int $requested;
    public function __construct(string $productName, int $productId, int $available, int $requested) {
        $this->productId = $productId; $this->available = $available; $this->requested = $requested;
        parent::__construct($available > 0
            ? "Only $available of $productName " . ($available === 1 ? 'is' : 'are') . " left in stock (you asked for $requested)."
            : "$productName is out of stock.");
    }
}
/** Sanity limits for prices and cart quantities. */
const MIN_PRICE = 0.01; const MAX_PRICE = 1000000; const MAX_LINE_QTY = 999;
const GENERIC_ERROR = 'Something went wrong, please try again.';
/** One set of wording for the common outcomes, so every page says the same thing. */
function msg(string $kind, string $what = ''): string {
    switch ($kind) {
        case 'saved':   return ($what !== '' ? $what : 'Changes') . ' saved.';
        case 'failed':  return GENERIC_ERROR;
        case 'denied':  return 'You are not allowed to do that.';
        case 'expired': return MSG_SESSION_EXPIRED;
        case 'stale':   return 'That page had expired, so nothing was saved. Please try again.';
    }
    return '';
}
/** Attributes that mark the field an error is about (red outline, announced by screen readers, focused). */
function field_attrs(string $field, string $errField, string $errId = 'form-error'): string {
    return ($field !== '' && $field === $errField) ? ' class="is-invalid" aria-invalid="true" aria-describedby="' . $errId . '" autofocus' : '';
}
/** Message that is safe to show. Anything not a UserFacingException is logged in full and replaced by a generic line. */
function user_error_message(Throwable $e, string $context = ''): string {
    if ($e instanceof UserFacingException) return $e->getMessage();
    error_log('BuildSmart ' . ($context !== '' ? $context . ' ' : '') . 'failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    return GENERIC_ERROR;
}
function safe_rollback(PDO $pdo): void {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) { error_log('BuildSmart rollback failed: ' . $e->getMessage()); }
}

/* ---------- Shared field limits and validation (match the column sizes in buildsmart.sql) ---------- */
const STREET_MAX = 150; const BARANGAY_MAX = 100; const CITY_MAX = 100;
const NAME_MAX = 120; const EMAIL_MAX = 150; const PHONE_MAX = 20; const ADDRESS_MAX = 500;
const REFERENCE_MAX = 150; const REMARKS_MAX = 255; const PHONE_PATTERN = '/^[0-9+()\-\s]{7,20}$/';
/** Returns ['field'=>..., 'message'=>...] for the first problem with name or phone, or null. The delivery address is checked only at checkout and in Saved addresses. */
function validate_customer_profile(string $name, string $phone): ?array {
    if ($name === '') return ['field' => 'full_name', 'message' => 'Please enter your name.'];
    if (mb_strlen($name) > NAME_MAX) return ['field' => 'full_name', 'message' => 'Your name can be at most ' . NAME_MAX . ' characters.'];
    if (!preg_match(PHONE_PATTERN, $phone)) return ['field' => 'phone', 'message' => 'Enter a phone number we can reach (7 to ' . PHONE_MAX . ' characters; numbers, spaces, + ( ) - only).'];
    return null;
}
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function redirect(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit;
}
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) || isset($_SESSION['admin_id']);
}
function is_admin(): bool {
    return isset($_SESSION['admin_id']);
}
function require_login(): void {
    if (!is_logged_in()) redirect('/login.php' . login_next_query());
}
function require_admin(): void {
    if (!is_admin()) redirect('/login.php' . login_next_query());
    // Admins still on the default password may only use the profile page (to change it).
    if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'settings.php') {
        redirect('/admin/settings.php');
    }
}
/** The page being requested, relative to BASE_URL (e.g. /checkout.php?x=1), or '' if it can't be worked out safely. */
function current_app_path(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (BASE_URL !== '' && strpos($uri, BASE_URL) === 0) $uri = substr($uri, strlen(BASE_URL));
    return safe_next($uri) ?? '';
}
/** Accepts only a same-site path ("/x.php?y=1"). Rejects absolute URLs, //host, backslashes, control characters and auth pages. */
function safe_next($next): ?string {
    if (!is_string($next) || $next === '' || strlen($next) > 300) return null;
    if ($next[0] !== '/' || strpos($next, '//') === 0 || preg_match('/[\\\\\x00-\x1f]/', $next)) return null;
    if (preg_match('~^/(login|register|logout)\.php~', $next)) return null;
    return $next;
}
function login_next_query(): string {
    $n = current_app_path();
    return $n === '' ? '' : '?next=' . rawurlencode($n);
}
/** Where to send someone after signing in: their original page if it suits their account type, else $default. */
function post_login_target($next, bool $isAdmin, string $default): string {
    $n = safe_next($next);
    if ($n === null) return $default;
    $inAdmin = strpos($n, '/admin/') === 0;
    return ($isAdmin === $inAdmin) ? $n : $default;
}
function current_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
/** True when the posted CSRF token is valid. Pages that can re-render their own form use this and keep the person's input. */
function csrf_ok(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && hash_equals(csrf_token(), $token);
}
/**
 * Fallback for pages that cannot re-render their form. Never leaves a bare-text dead end:
 * a signed-out visitor is sent to sign in (and brought back here), a signed-in one is sent back to the page.
 */
function verify_csrf(): void {
    if (csrf_ok()) return;
    if (!is_logged_in()) {
        flash(msg('expired'), 'error');
        redirect('/login.php' . login_next_query());
    }
    flash(msg('stale'), 'error');
    $back = current_app_path();
    redirect($back !== '' ? $back : '/');
}
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function money($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
function cart_count(PDO $pdo): int {
    if (!current_user_id()) return 0;
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(ci.quantity),0) FROM carts c JOIN cart_items ci ON ci.cart_id=c.cart_id WHERE c.user_id=?');
    $stmt->execute([current_user_id()]);
    return (int)$stmt->fetchColumn();
}
function get_or_create_cart(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    try {
        $stmt = $pdo->prepare('INSERT INTO carts(user_id) VALUES(?)');
        $stmt->execute([$userId]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Another simultaneous request may have created the unique user cart.
        if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
        $stmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if (!$id) throw $e;
        return (int)$id;
    }
}

/* ---------- Layout helpers (sidebar icons + breadcrumbs) ---------- */
function icon(string $name): string {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
        'box'       => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'orders'    => '<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 01-8 0"/>',
        'inventory' => '<path d="M4 7h16v13H4z"/><path d="M2 3h20v4H2z"/><path d="M10 12h4"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
        'report'    => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>',
        'bell'      => '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close'     => '<path d="M18 6L6 18M6 6l12 12"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'truck'     => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'clipboard-list' => '<path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 12h6M9 16h6"/>',
        'heart'     => '<path d="M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 00-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 000-7.8z"/>',
        'cart'      => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L23 6H6"/>',
        'receipt'   => '<path d="M4 2v20l3-2 2.5 2L12 20l2.5 2L17 20l3 2V2l-3 2-2.5-2L12 4 9.5 2 7 4z"/><path d="M8 9h8M8 13h8"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
        'card'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'cash'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
        'alert'     => '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'trend'     => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'store'     => '<path d="M3 9l1-5h16l1 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
/** $items = [[label, url|null], ...]; the last item is the current page. */
function breadcrumbs(array $items): string {
    if (!$items) return '';
    $out = '<nav class="breadcrumb" aria-label="Breadcrumb"><ol>';
    $last = count($items) - 1;
    foreach ($items as $i => [$label, $url]) {
        if ($i === $last || !$url) $out .= '<li aria-current="page">' . e($label) . '</li>';
        else $out .= '<li><a href="' . e($url) . '">' . e($label) . '</a></li>';
    }
    return $out . '</ol></nav>';
}

// Automatic fallback for the stale-order cleanup (see includes/maintenance.php); the scheduled job is still preferred.
if (PHP_SAPI !== 'cli' && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) buildsmart_maybe_run_cleanup($GLOBALS['pdo']);

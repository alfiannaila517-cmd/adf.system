<?php
if (!defined('APP_ACCESS')) define('APP_ACCESS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/business_helper.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pwf-login.php');
    exit;
}

if (function_exists('setActiveBusinessId') && (getActiveBusinessId() ?? '') !== 'pwf-furniture') {
    @setActiveBusinessId('pwf-furniture');
}

$currentUser = $auth->getCurrentUser();

// ─── CSRF protection ──────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['pwf_csrf_token'])) {
    $_SESSION['pwf_csrf_token'] = bin2hex(random_bytes(32));
}
if (!function_exists('pwfCsrfToken')) {
    function pwfCsrfToken(): string
    {
        return $_SESSION['pwf_csrf_token'] ?? '';
    }
}
if (!function_exists('pwfCsrfField')) {
    function pwfCsrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(pwfCsrfToken(), ENT_QUOTES) . '">';
    }
}
if (!function_exists('pwfCsrfVerify')) {
    function pwfCsrfVerify(): void
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = $_SESSION['pwf_csrf_token'] ?? '';
        if (empty($stored) || empty($sent) || !hash_equals($stored, $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch. Refresh halaman dan coba lagi.');
        }
    }
}

// Verify CSRF on every POST that reaches this bootstrap (centralized).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pwfCsrfVerify();
}

// Auto-inject CSRF hidden input into every <form method="post">.
ob_start(function ($html) {
    if (stripos($html, '<form') === false) return $html;
    $token = htmlspecialchars(pwfCsrfToken(), ENT_QUOTES);
    return preg_replace_callback(
        '/<form\b([^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*)>/i',
        function ($m) use ($token) {
            $tag = $m[0];
            // Skip if a _csrf field is already present right after.
            return $tag . "\n<input type=\"hidden\" name=\"_csrf\" value=\"{$token}\">";
        },
        $html
    );
});

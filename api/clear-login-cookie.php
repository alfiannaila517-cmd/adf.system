<?php

/**
 * Clear Login Cookies and Saved Credentials
 */

// Allow CORS for login page
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine cookie path
    $cookiePath = parse_url(isset($_ENV['BASE_URL']) ? $_ENV['BASE_URL'] : '/', PHP_URL_PATH) ?: '/';
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Clear all login-related cookies
    setcookie('adf_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
    setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    setcookie('adf_remember', '', time() - 3600, $cookiePath, '', $isSecure, true);
    setcookie('adf_saved_cred', '', time() - 3600, $cookiePath, '', $isSecure, true);

    // Destroy session if exists
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => 'Kredensial tersimpan berhasil dihapus'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;

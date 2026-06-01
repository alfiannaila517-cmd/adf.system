<?php
/**
 * Helper Functions
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Round overtime hours to multiples of 45 minutes (rounded DOWN).
 * Business rule: lembur < 45 menit tidak terhitung; selebihnya
 * dibulatkan ke bawah ke kelipatan 45 menit.
 *
 *   30 menit  -> 0
 *   44 menit  -> 0
 *   45 menit  -> 0.75 jam (45 menit)
 *   60 menit  -> 0.75 jam (45 menit)
 *   89 menit  -> 0.75 jam (45 menit)
 *   90 menit  -> 1.50 jam (90 menit)
 *   135 menit -> 2.25 jam (135 menit)
 *
 * @param float|int|string|null $hours Jumlah jam lembur mentah (boleh desimal)
 * @return float Jam lembur setelah dibulatkan ke kelipatan 45 menit
 */
function roundOT45($hours) {
    $h = (float)$hours;
    if ($h <= 0) return 0.0;
    $minutes = (int)floor($h * 60 + 0.5); // round to nearest minute first
    if ($minutes < 45) return 0.0;
    $rounded = intdiv($minutes, 45) * 45;
    return round($rounded / 60, 4);
}

function sanitize($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize($value);
        }
        return $data;
    }
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function getRequestMethod() {
    return $_SERVER['REQUEST_METHOD'];
}

function isPost() {
    return getRequestMethod() === 'POST';
}

function isGet() {
    return getRequestMethod() === 'GET';
}

function getPost($key = null, $default = null) {
    if ($key === null) {
        return $_POST;
    }
    return $_POST[$key] ?? $default;
}

function getGet($key = null, $default = null) {
    if ($key === null) {
        return $_GET;
    }
    return $_GET[$key] ?? $default;
}

function setFlash($key, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$key] = $message;
}

function getFlash($key) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

// Alias untuk setFlash (compatibility)
function setFlashMessage($key, $message) {
    setFlash($key, $message);
}

function formatCurrency($amount) {
    // Hardcode Rp untuk menghindari encoding issue di hosting
    $symbol = 'Rp';
    $decimal = 0;
    
    // Use constants if they are valid (not corrupted)
    if (defined('CURRENCY_SYMBOL') && is_string(CURRENCY_SYMBOL) && strlen(CURRENCY_SYMBOL) <= 5) {
        $symbol = CURRENCY_SYMBOL;
    }
    if (defined('CURRENCY_DECIMAL') && is_numeric(CURRENCY_DECIMAL) && CURRENCY_DECIMAL >= 0 && CURRENCY_DECIMAL <= 4) {
        $decimal = (int)CURRENCY_DECIMAL;
    }
    
    return $symbol . ' ' . number_format((float)$amount, $decimal, ',', '.');
}

function formatDate($date, $format = DATE_FORMAT) {
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    return date($format, strtotime($datetime));
}

function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

function isToday($date) {
    return date('Y-m-d', strtotime($date)) === date('Y-m-d');
}

function getDateRange($period = 'today') {
    $start = '';
    $end = date('Y-m-d');
    
    switch ($period) {
        case 'today':
            $start = date('Y-m-d');
            break;
        case 'yesterday':
            $start = $end = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $start = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $start = date('Y-m-01');
            break;
        case 'year':
            $start = date('Y-01-01');
            break;
    }
    
    return ['start' => $start, 'end' => $end];
}

function dd($data, $die = true) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    if ($die) {
        die();
    }
}

function activeMenu($page) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    $currentPath = $_SERVER['PHP_SELF'];
    
    // For dashboard - only match if in root directory
    if ($page === 'index.php') {
        // Check if we're in root directory (not in modules folder)
        if ($currentPage === 'index.php' && strpos($currentPath, '/modules/') === false) {
            return 'active';
        }
        return '';
    }
    
    // For settings index page
    if ($page === 'settings-index') {
        if (strpos($currentPath, '/settings/') !== false && $currentPage === 'index.php') {
            return 'active';
        }
        return '';
    }
    
    // For reports menu - mark active if in any reports page
    if ($page === 'reports') {
        $reportPages = ['daily.php', 'monthly.php', 'yearly.php', 'detailed.php', 'by-division.php', 'index.php'];
        if (strpos($currentPath, '/reports/') !== false && in_array($currentPage, $reportPages)) {
            return 'active';
        }
        return '';
    }
    
    // Exact match for specific file names (daily.php, monthly.php, etc)
    if ($currentPage === $page) {
        return 'active';
    }
    
    // For module folders - check if path contains the module name
    if (strpos($currentPath, '/' . $page . '/') !== false) {
        return 'active';
    }
    
    return '';
}

?>
<?php

/**
 * ADF SYSTEM - Multi Business Management
 * Login Page
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    unset($testConn);
} catch (PDOException $e) {
    header('Location: setup-required.html');
    exit;
}

require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'config/database.php';

$auth = new Auth();
$db = Database::getInstance();

// Get custom login background from settings (with error handling)
$customBg = null;
$bgUrl = null;
$loginLogo = null;
$loginLogoUrl = null;
$faviconUrl = null;
try {
    require_once __DIR__ . '/includes/CloudinaryHelper.php';
    $cl = CloudinaryHelper::getInstance();

    $loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
    $customBg = $loginBgSetting['setting_value'] ?? null;
    $bgUrl = $customBg ? $cl->getDisplayUrl($customBg, 'uploads/backgrounds/') : null;

    // Fallback: use Cloudinary hero background if no custom background set
    if (!$bgUrl) {
        $bgUrl = 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739188/adf_system/website/hero/ombs61riq165vcwenxy1.png';
    }

    $loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
    $loginLogo = $loginLogoSetting['setting_value'] ?? null;
    $loginLogoUrl = $loginLogo ? $cl->getDisplayUrl($loginLogo, 'uploads/logos/') : null;

    $faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
    $faviconFile = $faviconSetting['setting_value'] ?? null;
    $faviconUrl = $faviconFile ? $cl->getDisplayUrl($faviconFile, 'uploads/icons/') : null;

    // Get demo credentials from settings
    $demoUsernameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_username'");
    $demoUsername = $demoUsernameSetting['setting_value'] ?? 'admin';

    $demoPasswordSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_password'");
    $demoPassword = $demoPasswordSetting['setting_value'] ?? 'admin';
} catch (Exception $e) {
    // Settings table might not exist yet, continue without background
}

// ============================================
// REMEMBER ME - Auto-login via HMAC token
// ============================================
$cookiePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$rememberSecret = hash('sha256', DB_PASS . DB_NAME . '__adf_remember_salt__');

// Safety switch: disable cookie-based auto-login while troubleshooting hosting/session issues.
// Manual login (username/password) stays active.
$allowRememberTokenAutoLogin = false;

function generateRememberToken($userId, $secret)
{
    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
    $payload = $userId . ':' . $expiry;
    $hmac = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . ':' . $hmac);
}

function validateRememberToken($token, $secret)
{
    $decoded = base64_decode($token, true);
    if (!$decoded) return false;
    $parts = explode(':', $decoded);
    if (count($parts) !== 3) return false;
    [$userId, $expiry, $hmac] = $parts;
    if (!is_numeric($userId) || !is_numeric($expiry)) return false;
    if (time() > (int)$expiry) return false;
    $expected = hash_hmac('sha256', $userId . ':' . $expiry, $secret);
    if (!hash_equals($expected, $hmac)) return false;
    return (int)$userId;
}

// Check auto-login token BEFORE showing login form
$savedUser = '';
$isRemembered = false;
if ($allowRememberTokenAutoLogin && !empty($_COOKIE['adf_remember_token']) && !$auth->isLoggedIn() && !isPost()) {
    $tokenUserId = validateRememberToken($_COOKIE['adf_remember_token'], $rememberSecret);
    if ($tokenUserId) {
        // Valid token - auto-login this user
        try {
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $masterPdo->prepare("SELECT id, username, full_name, role_id, business_access, is_active FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$tokenUserId]);
            $tokenUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tokenUser) {
                // Get role code
                $roleCode = 'staff';
                try {
                    $roleStmt = $masterPdo->prepare("SELECT role_code FROM roles WHERE id = ?");
                    $roleStmt->execute([$tokenUser['role_id']]);
                    $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    $roleCode = $roleData['role_code'] ?? 'staff';
                } catch (Exception $e) {
                }

                // Set session
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['user_id'] = $tokenUser['id'];
                $_SESSION['username'] = $tokenUser['username'];
                $_SESSION['full_name'] = $tokenUser['full_name'];
                $_SESSION['role'] = $roleCode;
                $_SESSION['business_access'] = $tokenUser['business_access'] ?? 'all';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['user_theme'] = 'dark';
                $_SESSION['user_language'] = 'id';

                // Refresh token (extend expiry)
                $newToken = generateRememberToken($tokenUser['id'], $rememberSecret);
                setcookie('adf_remember_token', $newToken, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);

                // Set business and redirect
                require_once 'includes/business_helper.php';
                require_once __DIR__ . '/includes/business_access.php';

                if (in_array($roleCode, ['owner', 'admin', 'developer'])) {
                    $ownerBizList = getUserAvailableBusinesses();
                    if (!empty($ownerBizList)) {
                        setActiveBusinessId(getPreferredDefaultBusiness($ownerBizList));
                    }
                    header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                    exit;
                } else {
                    // Normal user - set first business
                    try {
                        $bizStmt = $masterPdo->prepare("
                            SELECT DISTINCT b.id, b.business_code 
                            FROM businesses b
                            LEFT JOIN user_business_assignment uba ON b.id = uba.business_id AND uba.user_id = ?
                            WHERE b.is_active = 1
                            ORDER BY uba.user_id DESC, b.business_name
                            LIMIT 1
                        ");
                        $bizStmt->execute([$tokenUser['id']]);
                        $firstBiz = $bizStmt->fetch(PDO::FETCH_ASSOC);
                        if ($firstBiz) {
                            $_SESSION['business_id'] = (int)$firstBiz['id'];
                            $slug = strtolower(str_replace('_', '-', $firstBiz['business_code']));
                            setActiveBusinessId($slug);
                        }
                    } catch (Exception $e) {
                    }
                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (Exception $e) {
            // Token valid but DB error - clear token
            error_log("Remember token auto-login failed: " . $e->getMessage());
        }
        // If we get here, token was invalid or user not found - clear cookie
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    } else {
        // Invalid/expired token - clear cookie
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }
}

// If auto-login is disabled, force-clear old remember token cookie to stop password-less login.
if (!$allowRememberTokenAutoLogin && !empty($_COOKIE['adf_remember_token'])) {
    setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
}

// Pre-fill username from cookie (for display only)
if (!empty($_COOKIE['adf_saved_user'])) {
    $savedUser = base64_decode($_COOKIE['adf_saved_user']);
    $isRemembered = true;
}

// If already logged in, redirect to dashboard
// But allow POST login_type=owner to re-login as owner
if ($auth->isLoggedIn() && !isPost()) {
    // If user role is owner/admin/developer, go to owner dashboard
    $currentRole = $_SESSION['role'] ?? '';
    if (in_array($currentRole, ['owner', 'admin', 'developer'])) {
        redirect(BASE_URL . '/modules/owner/dashboard-2028.php');
    } else {
        redirect(BASE_URL . '/index.php');
    }
}

// Handle login form submission
if (isPost()) {
    $username = sanitize(getPost('username'));
    $password = getPost('password');
    $rememberMe = isset($_POST['remember_me']);
    $loginType = getPost('login_type') ?? 'normal'; // owner or normal

    // Handle remember me - save username cookie (token set after successful login)
    if ($rememberMe && $username) {
        $cookieExpiry = time() + (30 * 24 * 60 * 60); // 30 days
        setcookie('adf_saved_user', base64_encode($username), $cookieExpiry, $cookiePath, '', $isSecure, true);
    } else {
        // Clear all remember cookies
        setcookie('adf_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_remember', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_saved_cred', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }

    // Check if business specified via URL parameter
    $forcedBusiness = isset($_GET['biz']) ? sanitize($_GET['biz']) : null;

    if ($auth->login($username, $password)) {
        $currentUser = $auth->getCurrentUser();

        // Set remember-me auto-login token cookie
        if ($rememberMe) {
            $userId = $currentUser['id'] ?? $_SESSION['user_id'] ?? 0;
            if ($userId) {
                $token = generateRememberToken($userId, $rememberSecret);
                setcookie('adf_remember_token', $token, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);
            }
        }

        // Auto-detect user's accessible businesses
        require_once 'includes/business_helper.php';

        try {
            // Connect to master database (DB_NAME is correct for current environment)
            $masterDbName = DB_NAME;
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get user ID and role from master
            $userStmt = $masterPdo->prepare("SELECT u.id, u.role_id, r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
            $userStmt->execute([$username]);
            $masterUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$masterUser) {
                $error = 'User is not registered in the system. Contact developer to set access.';
                $auth->logout();
            } else {
                $masterId = $masterUser['id'];
                $roleCode = $masterUser['role_code'];

                // Build dynamic business code <-> slug mappings from DB
                // Auto-add slug column if missing
                try {
                    $colCheck = $masterPdo->query("SHOW COLUMNS FROM businesses LIKE 'slug'")->fetchAll();
                    if (empty($colCheck)) {
                        $masterPdo->exec("ALTER TABLE businesses ADD COLUMN slug VARCHAR(100) AFTER business_code");
                    }
                } catch (Exception $e) {
                }

                $allBizRows = $masterPdo->query("SELECT id, business_code, slug, database_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                $codeToSlugMap = []; // BENSCAFE => bens-cafe
                $slugToCodeMap = []; // bens-cafe => BENSCAFE
                $bizIdToSlugMap = []; // 4 => eat-meet

                // Known overrides first
                $knownSlugs = ['BENSCAFE' => 'bens-cafe', 'NARAYANAHOTEL' => 'narayana-hotel', 'DEMO' => 'demo'];

                foreach ($allBizRows as $br) {
                    // Determine slug: use DB slug column if set, then known overrides, then derive
                    if (!empty($br['slug'])) {
                        $slug = $br['slug'];
                    } elseif (isset($knownSlugs[$br['business_code']])) {
                        $slug = $knownSlugs[$br['business_code']];
                    } else {
                        $slug = strtolower(str_replace('_', '-', $br['business_code']));
                    }

                    // Auto-populate slug in DB if empty
                    if (empty($br['slug'])) {
                        try {
                            $masterPdo->prepare("UPDATE businesses SET slug = ? WHERE id = ?")->execute([$slug, $br['id']]);
                        } catch (Exception $e) {
                        }
                    }

                    $codeToSlugMap[$br['business_code']] = $slug;
                    $slugToCodeMap[$slug] = $br['business_code'];
                    $bizIdToSlugMap[$br['id']] = $slug;
                }

                // Check if owner login requested
                if ($loginType === 'owner') {
                    // Only owner, admin, developer can access owner dashboard
                    if (in_array($roleCode, ['owner', 'admin', 'developer'])) {
                        $_SESSION['role'] = $roleCode;
                        // Set active business to user's first assigned business
                        require_once __DIR__ . '/includes/business_access.php';
                        $ownerBizList = getUserAvailableBusinesses();
                        if (!empty($ownerBizList)) {
                            $firstOwnerBiz = getPreferredDefaultBusiness($ownerBizList);
                            setActiveBusinessId($firstOwnerBiz);
                        }
                        setFlash('success', 'Owner login successful!');
                        header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                        exit;
                    } else {
                        $error = 'Access denied. Only Owner role can access the Owner Dashboard.';
                        $auth->logout();
                    }
                }

                // Developer role has full access to all businesses
                if ($roleCode === 'developer') {
                    if ($forcedBusiness) {
                        setActiveBusinessId($forcedBusiness);
                    } else {
                        // Default to narayana-hotel if available
                        $allBiz = getAvailableBusinesses();
                        $firstBiz = getPreferredDefaultBusiness($allBiz);
                        setActiveBusinessId($firstBiz);
                    }
                    setFlash('success', 'Login successful. Developer mode is active.');
                    redirect(BASE_URL . '/index.php');
                }

                // Get businesses user has access to (check both user_menu_permissions and user_business_assignment)
                $userBusinesses = [];

                // Try user_business_assignment first (newer system)
                try {
                    $bizStmt = $masterPdo->prepare("
                        SELECT DISTINCT b.id, b.business_code, b.business_name
                        FROM businesses b
                        JOIN user_business_assignment uba ON b.id = uba.business_id
                        WHERE uba.user_id = ? AND b.is_active = 1
                        ORDER BY b.business_name
                    ");
                    $bizStmt->execute([$masterId]);
                    $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                }

                // Fallback: try user_menu_permissions (legacy)
                if (empty($userBusinesses)) {
                    try {
                        $bizStmt = $masterPdo->prepare("
                            SELECT DISTINCT b.id, b.business_code, b.business_name
                            FROM businesses b
                            JOIN user_menu_permissions p ON b.id = p.business_id
                            WHERE p.user_id = ? AND b.is_active = 1
                            ORDER BY b.business_name
                        ");
                        $bizStmt->execute([$masterId]);
                        $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                    }
                }

                // Final fallback: if user has no assignments, get all active businesses
                if (empty($userBusinesses)) {
                    try {
                        $bizStmt = $masterPdo->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1 ORDER BY business_name");
                        $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                    }
                }

                if (empty($userBusinesses)) {
                    $error = 'You do not have access to any business. Contact developer.';
                    $auth->logout();
                } elseif ($forcedBusiness) {
                    // Direct link with business parameter - validate access
                    $forcedBizCode = isset($slugToCodeMap[$forcedBusiness]) ? $slugToCodeMap[$forcedBusiness] : strtoupper(str_replace('-', '_', $forcedBusiness));
                    $hasAccess = false;

                    foreach ($userBusinesses as $biz) {
                        if ($biz['business_code'] === $forcedBizCode) {
                            $hasAccess = true;
                            break;
                        }
                    }

                    if ($hasAccess) {
                        // Find the numeric business ID from the matched business
                        foreach ($userBusinesses as $biz) {
                            if ($biz['business_code'] === $forcedBizCode) {
                                $_SESSION['business_id'] = (int)$biz['id']; // Set numeric business_id
                                break;
                            }
                        }
                        setActiveBusinessId($forcedBusiness);
                        setFlash('success', 'Login successful!');
                        redirect(BASE_URL . '/index.php');
                    } else {
                        $error = 'You do not have access to this business.';
                        $auth->logout();
                    }
                } else {
                    // One or multiple businesses - auto login to first business
                    $bizCode = $userBusinesses[0]['business_code'];
                    $businessId = isset($codeToSlugMap[$bizCode]) ? $codeToSlugMap[$bizCode] : strtolower(str_replace('_', '-', $bizCode));
                    $_SESSION['business_id'] = (int)$userBusinesses[0]['id']; // Set numeric business_id
                    setActiveBusinessId($businessId);

                    if (count($userBusinesses) === 1) {
                        setFlash('success', 'Login successful! Welcome to ' . $userBusinesses[0]['business_name']);
                    } else {
                        setFlash('success', 'Login successful! You can switch business using the sidebar dropdown.');
                    }

                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Login business check error: ' . $e->getMessage());
            $error = 'A system error occurred. Please try again.';
            $auth->logout();
        }
    } else {
        $error = 'Invalid username or password.';
    }
}

// Check if redirected from account removal
if (isset($_GET['error']) && $_GET['error'] === 'account_removed') {
    $error = 'Your account has been removed or disabled. Contact developer.';
}

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Get business-specific information for display
$displayInfo = [
    'icon' => '🏢',
    'name' => 'ADF System',
    'subtitle' => 'Business Management System',
    'db_name' => 'Multi-Business Platform'
];

if (isset($_GET['biz'])) {
    $bizParam = strtolower(sanitize($_GET['biz']));

    // Map business codes to display info
    $businessMap = [
        'narayana-hotel' => [
            'icon' => '🏨',
            'name' => 'Narayana Hotel',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_narayana_hotel'
        ],
        'bens-cafe' => [
            'icon' => '☕',
            'name' => 'Ben\'s Cafe',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_benscafe'
        ],
        'demo' => [
            'icon' => '🏢',
            'name' => 'Demo Business',
            'subtitle' => 'Demo System',
            'db_name' => 'adf_demo'
        ]
    ];

    if (isset($businessMap[$bizParam])) {
        $displayInfo = $businessMap[$bizParam];
    } else {
        // Dynamic: try to load from businesses table
        try {
            $bizSlugCode = strtoupper(str_replace('-', '_', $bizParam));
            $dynBiz = $db->fetchOne("SELECT business_name, business_type FROM businesses WHERE business_code = :code AND is_active = 1", ['code' => $bizSlugCode]);
            if ($dynBiz) {
                $typeIcons = ['hotel' => '🏨', 'restaurant' => '🍽️', 'cafe' => '☕', 'retail' => '🏪', 'manufacture' => '🏭', 'tourism' => '🏝️'];
                $displayInfo = [
                    'icon' => $typeIcons[$dynBiz['business_type']] ?? '🏢',
                    'name' => $dynBiz['business_name'],
                    'subtitle' => ucfirst($dynBiz['business_type'] ?? 'Business'),
                    'db_name' => $bizParam
                ];
            }
        } catch (Exception $e) {
        }
    }
}
?>
<!DOCTYPE html>
    <html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - <?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <?php if ($faviconUrl): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
        <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">

    <style>
        :root {
            --ink-900: #0f172a;
            --ink-700: #334155;
            --ink-600: #475569;
            --paper: #ffffff;
            --line: #d6deea;
            --brand: #0f766e;
            --brand-2: #0d9488;
            --accent: #f59e0b;
            --danger: #dc2626;
            --soft: #f8fafc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Sans', sans-serif;
            color: var(--ink-900);
            background: #e2e8f0;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            <?php if ($bgUrl): ?>background-image: linear-gradient(125deg, rgba(12, 26, 53, 0.72), rgba(13, 31, 38, 0.67)), url('<?php echo $bgUrl; ?>?v=<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            <?php else: ?>background: linear-gradient(125deg, #0f1f38, #16324d);
            <?php endif; ?>
        }

        .login-container::before,
        .login-container::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            filter: blur(2px);
            pointer-events: none;
        }

        .login-container::before {
            width: 340px;
            height: 340px;
            top: -120px;
            right: -80px;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.34), rgba(251, 191, 36, 0));
            animation: drift 8s ease-in-out infinite;
        }

        .login-container::after {
            width: 300px;
            height: 300px;
            bottom: -130px;
            left: -100px;
            background: radial-gradient(circle, rgba(20, 184, 166, 0.38), rgba(20, 184, 166, 0));
            animation: drift 10s ease-in-out infinite reverse;
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.99);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-shell {
            display: grid;
            grid-template-columns: 1.14fr 0.86fr;
            width: min(980px, 94vw);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 22px 60px rgba(2, 6, 23, 0.38);
            border: 1px solid rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(7px);
            position: relative;
            z-index: 1;
            animation: riseIn 0.42s ease;
        }

        .login-hero {
            background: linear-gradient(160deg, rgba(15, 23, 42, 0.83), rgba(30, 41, 59, 0.72));
            color: #f8fafc;
            padding: 1.7rem 1.45rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 1.25rem;
        }

        .hero-head {
            display: flex;
            flex-direction: column;
            gap: 0.95rem;
        }

        .business-logo-icon {
            width: 58px;
            height: 58px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.85rem;
            background: linear-gradient(135deg, rgba(20, 184, 166, 0.45), rgba(245, 158, 11, 0.34));
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .business-logo-img {
            width: 58px;
            height: 58px;
            object-fit: contain;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.32);
            background: rgba(255, 255, 255, 0.92);
            padding: 6px;
        }

        .hero-badge {
            width: fit-content;
            display: inline-flex;
            gap: 0.45rem;
            align-items: center;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.11);
            border: 1px solid rgba(255, 255, 255, 0.22);
            font-size: 0.74rem;
            color: #e2e8f0;
            letter-spacing: 0.25px;
        }

        .hero-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.3rem, 2vw, 1.6rem);
            line-height: 1.3;
            font-weight: 700;
            letter-spacing: -0.4px;
        }

        .hero-subtitle {
            color: #cbd5e1;
            font-size: 0.9rem;
            max-width: 34ch;
            line-height: 1.45;
        }

        .hero-list {
            display: grid;
            gap: 0.5rem;
            margin-top: 0.2rem;
        }

        .hero-list-item {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 0.82rem;
            color: #e2e8f0;
        }

        .hero-list-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2dd4bf, #f59e0b);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
        }

        .hero-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.17);
            padding-top: 0.8rem;
            color: #cbd5e1;
            font-size: 0.75rem;
        }

        .login-box {
            background: rgba(255, 255, 255, 0.84);
            backdrop-filter: blur(9px);
            padding: 1.08rem 1.02rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 100%;
            color: #0f172a !important;
        }

        .login-box .login-logo {
            color: #0f172a !important;
        }

        .login-header {
            margin-bottom: 0.82rem;
        }

        .login-logo {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.23rem;
            line-height: 1.25;
            color: var(--ink-900);
            letter-spacing: -0.3px;
        }

        .login-subtitle {
            color: var(--ink-700);
            font-size: 0.84rem;
            margin-top: 0.28rem;
        }

        .login-box .login-subtitle {
            color: #334155 !important;
        }

        .database-status {
            display: flex;
            gap: 0.55rem;
            align-items: center;
            border: 1px solid var(--line);
            background: var(--soft);
            border-radius: 10px;
            padding: 0.48rem 0.66rem;
            margin-bottom: 0.8rem;
        }

        .status-indicator {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #16a34a;
            box-shadow: 0 0 0 5px rgba(22, 163, 74, 0.18);
            flex-shrink: 0;
        }

        .db-label {
            font-size: 0.66rem;
            color: var(--ink-700);
            letter-spacing: 0.35px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .login-box .db-label {
            color: #475569 !important;
        }

        .db-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.84rem;
            color: var(--brand);
            font-weight: 700;
            letter-spacing: 0.15px;
        }

        .login-box .db-name {
            color: #0f766e !important;
        }

        .form-group {
            margin-bottom: 0.78rem;
        }

        .form-label {
            display: block;
            color: #0f172a;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.28rem;
            text-transform: uppercase;
            letter-spacing: 0.36px;
        }

        .login-box .form-label {
            color: #1e293b !important;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
            font-size: 0.96rem;
            user-select: none;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #0f172a;
        }

        .form-control {
            width: 100%;
            padding: 0.56rem 0.68rem;
            background: #fff;
            border: 1px solid #94a3b8;
            border-radius: 9px;
            color: #0b1220;
            font-size: 0.86rem;
            font-weight: 500;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .login-box .form-control {
            color: #0b1220 !important;
            background: #ffffff !important;
        }

        .form-control::placeholder {
            color: #475569;
            opacity: 1;
        }

        .login-box .form-control::placeholder {
            color: #475569 !important;
            opacity: 1 !important;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--brand-2);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
        }

        .alert-danger,
        .login-box .alert-danger {
            background: #fff1f2;
            border: 1px solid #ef4444;
            color: #991b1b !important;
            padding: 0.68rem 0.78rem;
            border-radius: 9px;
            margin-bottom: 0.82rem;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1.45;
            box-shadow: 0 10px 24px rgba(185, 28, 28, 0.08);
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.75);
            opacity: 1 !important;
            letter-spacing: 0.1px;
            -webkit-text-fill-color: #991b1b !important;
        }

        .login-box .alert-danger * {
            color: #991b1b !important;
            opacity: 1 !important;
            -webkit-text-fill-color: #991b1b !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .save-pw-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0.32rem 0 0.95rem;
            gap: 0.55rem;
        }

        .save-pw-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #1e293b;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .login-box .save-pw-label,
        .login-box .save-pw-label span {
            color: #334155 !important;
        }

        .save-pw-label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--brand);
            cursor: pointer;
            flex-shrink: 0;
        }

        .btn-clear-saved {
            padding: 0.28rem 0.62rem;
            background: #fff;
            border: 1px solid #fecaca;
            border-radius: 7px;
            color: var(--danger);
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-clear-saved:hover {
            background: #fef2f2;
        }

        .login-buttons {
            display: grid;
            gap: 0.52rem;
            margin-top: 0.2rem;
            grid-template-columns: 1fr 1fr;
        }

        .login-buttons button {
            padding: 0.58rem 0.62rem;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
            letter-spacing: 0.12px;
        }

        .btn-owner {
            background: #1e293b;
            color: #f8fafc;
            box-shadow: 0 6px 16px rgba(30, 41, 59, 0.2);
        }

        .btn-owner:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(30, 41, 59, 0.28);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brand-2), var(--brand));
            color: #fff;
            box-shadow: 0 6px 16px rgba(13, 148, 136, 0.24);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(13, 148, 136, 0.32);
        }

        .login-footer {
            text-align: center;
            margin-top: 0.86rem;
            padding-top: 0.86rem;
            border-top: 1px dashed var(--line);
            color: var(--ink-600);
            font-size: 0.68rem;
        }

        .login-box .login-footer {
            color: #64748b !important;
        }

        @media (max-width: 860px) {
            .login-shell {
                grid-template-columns: 1fr;
                width: min(470px, 93vw);
            }

            .login-hero {
                padding: 1.3rem 1.2rem;
            }

            .hero-footer {
                display: none;
            }
        }

        @media (max-width: 420px) {
            .login-container {
                padding: 0.72rem;
            }

            .login-box {
                padding: 0.98rem 0.9rem;
            }

            .login-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-shell">
            <aside class="login-hero">
                <div class="hero-head">
                    <span class="hero-badge">ADF SYSTEM • CENTRAL ACCESS</span>
                    <?php if ($loginLogoUrl): ?>
                        <img src="<?php echo $loginLogoUrl; ?>?v=<?php echo time(); ?>" alt="Logo" class="business-logo-img">
                    <?php else: ?>
                        <span class="business-logo-icon"><?php echo $displayInfo['icon']; ?></span>
                    <?php endif; ?>
                    <h1 class="hero-title"><?php echo $displayInfo['name']; ?></h1>
                    <p class="hero-subtitle">Manage operations, billing, and business reports from one cleaner and faster control panel.</p>

                    <div class="hero-list">
                        <div class="hero-list-item"><span class="hero-list-dot"></span>Owner login and system login in one page</div>
                        <div class="hero-list-item"><span class="hero-list-dot"></span>Multi-business mode with role-based access switching</div>
                        <div class="hero-list-item"><span class="hero-list-dot"></span>Lightweight UI focused on desktop and mobile readability</div>
                    </div>
                </div>
                <div class="hero-footer">Active database: <?php echo $displayInfo['db_name']; ?></div>
            </aside>

            <div class="login-box">
                <div class="login-header">
                    <h2 class="login-logo">Sign in to Dashboard</h2>
                    <p class="login-subtitle"><?php echo $displayInfo['subtitle']; ?><?php if (isset($_GET['biz'])): ?> • Hotel System<?php endif; ?></p>
                </div>

                <div class="database-status">
                    <div class="status-indicator"></div>
                    <div class="db-info">
                        <div class="db-label">DATABASE</div>
                        <div class="db-name"><?php echo $displayInfo['db_name']; ?></div>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert-danger" role="alert" aria-live="assertive">
                        <span style="font-weight:800;color:#991b1b !important;opacity:1 !important;-webkit-text-fill-color:#991b1b !important;">&#9888; <?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" autocomplete="on">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" autocomplete="username" class="form-control" placeholder="Enter username" required autofocus value="<?= htmlspecialchars($savedUser) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="loginPassword" autocomplete="current-password" class="form-control" placeholder="Enter password" required style="padding-right: 45px;">
                            <span class="password-toggle" onclick="togglePassword('loginPassword', this)">👁️</span>
                        </div>
                    </div>

                    <div class="save-pw-row">
                        <label class="save-pw-label">
                            <input type="checkbox" id="savePasswordChk" onchange="toggleSavePassword(this)">
                            <span>Remember me</span>
                        </label>
                        <button type="button" class="btn-clear-saved" id="clearSavedBtn" onclick="clearSavedCredentials()" style="display:none;">Clear</button>
                    </div>

                    <div class="login-buttons">
                        <button type="submit" name="login_type" value="owner" class="btn-owner">Owner Login</button>
                        <button type="submit" name="login_type" value="normal" class="btn-primary">System Login</button>
                    </div>
                </form>

                <div class="login-footer">
                    &copy; <?php echo APP_YEAR; ?> <?php echo APP_NAME; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                iconElement.textContent = '👁️‍🗨️';
            } else {
                input.type = 'password';
                iconElement.textContent = '👁️';
            }
        }

        function toggleSavePassword(chk) {
            let rememberInput = document.querySelector('input[name="remember_me"]');
            if (chk.checked) {
                if (!rememberInput) {
                    rememberInput = document.createElement('input');
                    rememberInput.type = 'hidden';
                    rememberInput.name = 'remember_me';
                    document.querySelector('form').appendChild(rememberInput);
                }
                rememberInput.value = '1';
                document.getElementById('clearSavedBtn').style.display = 'inline-flex';
            } else {
                if (rememberInput) rememberInput.value = '0';
                document.getElementById('clearSavedBtn').style.display = 'none';
            }
        }

        // Clear Saved Credentials
        function clearSavedCredentials() {
            if (confirm('Clear all saved credentials? You will need to log in manually again.')) {
                // Clear cookies via server-side (send AJAX request)
                fetch('<?= BASE_URL ?>/api/clear-login-cookie.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                }).then(() => {
                    // Clear form
                    document.querySelector('input[name="username"]').value = '';
                    document.querySelector('input[name="password"]').value = '';

                    // Reset UI states
                    const clearBtn = document.getElementById('clearSavedBtn');
                    const saveChk = document.getElementById('savePasswordChk');
                    if (saveChk) saveChk.checked = false;
                    clearBtn.style.display = 'none';

                    // Remove hidden remember_me input
                    const rememberInput = document.querySelector('input[name="remember_me"]');
                    if (rememberInput) rememberInput.remove();

                    alert('Saved credentials cleared successfully.');
                    location.reload();
                }).catch(err => {
                    alert('Failed to clear saved credentials. Please try again.');
                });
            }
        }





        // Remember me - auto login via secure HMAC token
        document.addEventListener('DOMContentLoaded', function() {
            const clearBtn = document.getElementById('clearSavedBtn');
            const usernameInput = document.querySelector('input[name="username"]');

            // If user saved, check the checkbox and show clear button
            const hasSavedUser = <?= !empty($savedUser) ? 'true' : 'false' ?>;
            if (hasSavedUser) {
                const chk = document.getElementById('savePasswordChk');
                if (chk) chk.checked = true;
                clearBtn.style.display = 'inline-flex';
            }

            // Clean up old localStorage (one-time migration)
            try {
                localStorage.removeItem('saved_username');
                localStorage.removeItem('saved_password');
                localStorage.removeItem('remember_me');
            } catch (e) {}
        });
    </script>
</body>

</html>
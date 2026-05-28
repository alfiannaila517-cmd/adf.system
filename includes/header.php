<?php
// Load helper functions
require_once __DIR__ . '/functions.php';

// Load language system
require_once __DIR__ . '/language.php';

// Get favicon from settings
$faviconUrl = null;
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        $faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
        $faviconFile = $faviconSetting['setting_value'] ?? null;
        if ($faviconFile) {
            if (strpos($faviconFile, 'http') === 0) {
                $faviconUrl = $faviconFile;
            } elseif (file_exists(BASE_PATH . '/uploads/icons/' . $faviconFile)) {
                $faviconUrl = BASE_URL . '/uploads/icons/' . $faviconFile;
            }
        }
    }
} catch (Exception $e) {
    // Silent fail
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['user_language'] ?? 'id'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <?php if ($faviconUrl): ?>
        <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
        <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <?php endif; ?>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Main CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">

    <!-- Icons (Feather Icons) -->
    <script src="https://unpkg.com/feather-icons"></script>

    <!-- Additional CSS -->
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Inline Styles -->
    <?php if (isset($inlineStyles)): ?>
        <?php echo $inlineStyles; ?>
    <?php endif; ?>

    <!-- Business Theme CSS -->
    <style>
        <?php echo getBusinessThemeCSS(); ?>@keyframes bellShake {
            0% {
                transform: rotate(0)
            }

            15% {
                transform: rotate(14deg)
            }

            30% {
                transform: rotate(-14deg)
            }

            45% {
                transform: rotate(10deg)
            }

            60% {
                transform: rotate(-6deg)
            }

            75% {
                transform: rotate(2deg)
            }

            100% {
                transform: rotate(0)
            }
        }
    </style>
</head>
<?php
// Load user theme from database per business (reliable method)
$userTheme = 'dark';
$themeError = null;

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = Database::getInstance();

        // Load theme for current business and user
        $themeResult = $db->fetchOne(
            "SELECT theme FROM user_preferences WHERE user_id = ? AND branch_id = ? LIMIT 1",
            [$_SESSION['user_id'], ACTIVE_BUSINESS_ID]
        );

        if ($themeResult && !empty($themeResult['theme'])) {
            $userTheme = $themeResult['theme'];
        } else {
            // Fallback: try to find any preference for this user
            $fallbackTheme = $db->fetchOne(
                "SELECT theme FROM user_preferences WHERE user_id = ? LIMIT 1",
                [$_SESSION['user_id']]
            );

            if ($fallbackTheme && !empty($fallbackTheme['theme'])) {
                $userTheme = $fallbackTheme['theme'];
            }
        }
    } catch (Exception $e) {
        $userTheme = 'dark';
        $themeError = $e->getMessage();
        // Don't die, just use default theme
    }
}
?>

<body data-theme="<?php echo htmlspecialchars($userTheme); ?>" data-business="<?php echo ACTIVE_BUSINESS_ID; ?>" data-business-type="<?php echo BUSINESS_TYPE; ?>">
    <?php if ($themeError): ?>
        <!-- Theme Load Warning: <?php echo htmlspecialchars($themeError); ?> -->
    <?php endif; ?>
    <div class="main-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <?php
                // Get business logo
                $logoPath = getBusinessLogo();

                // Get company name from settings, fallback to BUSINESS_NAME
                $companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
                $displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
                    ? $companyNameSetting['setting_value']
                    : BUSINESS_NAME;
                ?>
                <div style="display: flex; align-items: center; gap: 0.875rem;">
                    <?php if ($logoPath): ?>
                        <?php if (ACTIVE_BUSINESS_ID === 'cqc'): ?>
                            <!-- CQC: rectangular logo, no company name -->
                            <div style="width: 100%; border-radius: var(--radius-md); background: var(--bg-secondary, #fff); padding: 8px 10px; display: flex; align-items: center; justify-content: center;">
                                <img src="<?php echo $logoPath; ?>" alt="CQC" style="width: 100%; max-height: 48px; border-radius: 4px; object-fit: contain;">
                            </div>
                        <?php else: ?>
                            <div style="width: 48px; height: 48px; border-radius: var(--radius-md); background: #0f172a; padding: 4px; display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                                <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($displayCompanyName); ?>" style="width: 100%; height: 100%; border-radius: 6px; object-fit: cover;">
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="width: 48px; height: 48px; border-radius: var(--radius-md); background: linear-gradient(135deg, <?php echo BUSINESS_COLOR; ?>, <?php echo BUSINESS_COLOR; ?>dd); display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <span style="font-size: 1.5rem; font-weight: 800; color: white;"><?php echo BUSINESS_ICON; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (ACTIVE_BUSINESS_ID !== 'cqc'): ?>
                        <div style="flex: 1;">
                            <h1 class="logo" style="margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($displayCompanyName); ?></h1>
                            <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0; margin-top: 0.25rem;"><?php echo ucfirst(BUSINESS_TYPE); ?> System</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Business Switcher Dropdown (Only show if user has multiple business access) -->
                <?php
                require_once __DIR__ . '/business_access.php';
                $userBusinesses = getUserAvailableBusinesses();
                if (count($userBusinesses) > 1):
                ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                        <label style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; display: block;">Switch Business</label>
                        <select onchange="switchBusiness(this.value)" style="width: 100%; padding: 0.5rem; background: var(--bg-tertiary); border: 1px solid var(--bg-quaternary); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.875rem; cursor: pointer;">
                            <?php
                            foreach ($userBusinesses as $bizId => $bizConfig):
                                $selected = ($bizId === ACTIVE_BUSINESS_ID) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($bizId); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($bizConfig['theme']['icon'] . ' ' . $bizConfig['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Database Indicator -->
                <div style="margin-top: 0.75rem; padding: 0.625rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: var(--radius-md);">
                    <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem;">Database</div>
                    <div style="font-size: 0.75rem; color: var(--primary-color); font-weight: 600; word-break: break-all;">
                        <?php
                        require_once __DIR__ . '/../config/database.php';
                        $currentDb = Database::getCurrentDatabase();
                        echo htmlspecialchars($currentDb);
                        ?>
                    </div>
                </div>
            </div>

            <nav style="flex: 1; overflow-y: auto; overflow-x: hidden;">
                <ul class="nav-menu">
                    <?php if ($auth->hasPermission('dashboard')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/index.php" class="nav-link <?php echo activeMenu('index.php'); ?>">
                                <i data-feather="home" class="nav-icon"></i>
                                <span><?php echo __('dashboard.title'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- CQC Projects Menu (Solar Panel) -->
                    <?php if ($auth->hasPermission('cqc-projects')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/cqc-projects/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('cqc-projects'); ?>">
                                <i data-feather="sun" class="nav-icon"></i>
                                <span>CQC Projects</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                        <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                        <span>Dashboard</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/add.php" class="submenu-link <?php echo activeMenu('add.php'); ?>">
                                        <i data-feather="plus-circle" class="submenu-icon"></i>
                                        <span>Tambah Proyek</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Production Module (Manufacture/PWF) -->
                    <?php if ($auth->hasPermission('production')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/') !== false) ? 'active' : ''; ?>">
                                <i data-feather="tool" class="nav-icon"></i>
                                <span>Produksi</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/production/dashboard.php" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/dashboard') !== false) ? 'active' : ''; ?>">
                                        <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                        <span>Dashboard</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/production/sales-orders.php" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/sales-orders') !== false) ? 'active' : ''; ?>">
                                        <i data-feather="shopping-bag" class="submenu-icon"></i>
                                        <span>Sales Order</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/production/orders.php" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/orders') !== false) ? 'active' : ''; ?>">
                                        <i data-feather="hammer" class="submenu-icon"></i>
                                        <span>Order Produksi</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/production/materials.php" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/materials') !== false) ? 'active' : ''; ?>">
                                        <i data-feather="package" class="submenu-icon"></i>
                                        <span>Stok Bahan Baku</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/production/schedule.php" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/schedule') !== false) ? 'active' : ''; ?>">
                                        <i data-feather="calendar" class="submenu-icon"></i>
                                        <span>Workshop Schedule</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if ($auth->hasPermission('cashbook')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="nav-link <?php echo activeMenu('cashbook'); ?>">
                                <i data-feather="book" class="nav-icon"></i>
                                <span><?php echo __('cashbook.title'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($auth->hasPermission('divisions')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/divisions/index.php" class="nav-link <?php echo activeMenu('divisions'); ?>">
                                <i data-feather="grid" class="nav-icon"></i>
                                <span><?php echo __('settings.divisions'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($auth->hasPermission('frontdesk') && isModuleEnabled('frontdesk')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/frontdesk/') !== false && strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? activeMenu('frontdesk') : ''; ?>">
                                <i data-feather="home" class="nav-icon"></i>
                                <span><?php echo __('menu.frontdesk'); ?></span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                        <i data-feather="layout" class="submenu-icon"></i>
                                        <span><?php echo __('dashboard.title'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="submenu-link <?php echo activeMenu('reservasi.php'); ?>">
                                        <i data-feather="calendar" class="submenu-icon"></i>
                                        <span><?php echo __('menu.reservations'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/calendar.php" class="submenu-link <?php echo activeMenu('calendar.php'); ?>">
                                        <i data-feather="grid" class="submenu-icon"></i>
                                        <span><?php echo __('menu.calendar'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/in-house.php" class="submenu-link <?php echo activeMenu('in-house.php'); ?>">
                                        <i data-feather="users" class="submenu-icon"></i>
                                        <span><?php echo __('menu.in_house'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php"
                                        class="submenu-link <?php echo activeMenu('breakfast.php'); ?>"
                                        onclick="console.log('Breakfast link clicked!'); return true;">
                                        <i data-feather="coffee" class="submenu-icon"></i>
                                        <span><?php echo __('menu.breakfast'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/laporan.php" class="submenu-link <?php echo activeMenu('laporan.php'); ?>">
                                        <i data-feather="file-text" class="submenu-icon"></i>
                                        <span><?php echo __('menu.reports'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="submenu-link <?php echo activeMenu('settings.php'); ?>">
                                        <i data-feather="settings" class="submenu-icon"></i>
                                        <span><?php echo __('settings.title'); ?></span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Hotel Services Menu (hotel only) -->
                    <?php if (defined('BUSINESS_TYPE') && BUSINESS_TYPE === 'hotel' && $auth->hasPermission('frontdesk')): ?>
                        <li class="nav-item has-submenu <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                <i data-feather="briefcase" class="nav-icon"></i>
                                <span>Hotel Services</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/hotel-services.php" class="submenu-link <?php echo activeMenu('hotel-services.php'); ?>">
                                        <i data-feather="file-text" class="submenu-icon"></i>
                                        <span>Invoice & Layanan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/frontdesk/rental-motor-dashboard.php" class="submenu-link <?php echo (activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                        <i data-feather="truck" class="submenu-icon"></i>
                                        <span>Rental Motor</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Sales Invoice Menu (CQC only, not for hotel) -->
                    <?php if ($auth->hasPermission('sales_invoice') && isModuleEnabled('sales') && (!defined('BUSINESS_TYPE') || BUSINESS_TYPE !== 'hotel')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/sales/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('sales'); ?>">
                                <i data-feather="file-text" class="nav-icon"></i>
                                <span><?php echo __('menu.sales_invoice'); ?></span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=termin" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'termin') ? 'active' : ''; ?>">
                                        <i data-feather="file" class="submenu-icon"></i>
                                        <span>Invoice Termin</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=general" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'general') ? 'active' : ''; ?>">
                                        <i data-feather="file-text" class="submenu-icon"></i>
                                        <span>Invoice Umum</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=quotation" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'quotation') ? 'active' : ''; ?>">
                                        <i data-feather="clipboard" class="submenu-icon"></i>
                                        <span>Quotation</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Bills / Tagihan Menu -->
                    <?php if ($auth->hasPermission('bills')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/bills/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('bills'); ?>">
                                <i data-feather="credit-card" class="nav-icon"></i>
                                <span>Tagihan</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/bills/index.php" class="submenu-link <?php echo activeMenu('bills/index'); ?>">
                                        <i data-feather="list" class="submenu-icon"></i>
                                        <span>Daftar Tagihan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/bills/create.php" class="submenu-link <?php echo activeMenu('create.php'); ?>">
                                        <i data-feather="plus-circle" class="submenu-icon"></i>
                                        <span>Tambah Tagihan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/bills/templates.php" class="submenu-link <?php echo activeMenu('templates.php'); ?>">
                                        <i data-feather="layers" class="submenu-icon"></i>
                                        <span>Template Rutin</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Cafe Invoice Menu (Bens Cafe only) -->
                    <?php if (isModuleEnabled('cafe-invoice')): ?>
                        <li class="nav-item <?php echo (strpos($_SERVER['REQUEST_URI'], '/cafe-invoice/') !== false) ? 'open' : ''; ?>">
                            <a href="<?php echo BASE_URL; ?>/modules/cafe-invoice/index.php" class="nav-link <?php echo activeMenu('cafe-invoice'); ?>">
                                <i data-feather="file-text" class="nav-icon"></i>
                                <span>☕ Invoice</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Payroll Menu -->
                    <?php if ($auth->hasPermission('payroll') && isModuleEnabled('payroll')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/payroll/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('payroll'); ?>">
                                <i data-feather="dollar-sign" class="nav-icon"></i>
                                <span>Payroll</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/payroll/index.php" class="submenu-link <?php echo activeMenu('index.php'); ?>">
                                        <i data-feather="layout" class="submenu-icon"></i>
                                        <span>Dashboard</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/payroll/employees.php" class="submenu-link <?php echo activeMenu('employees.php'); ?>">
                                        <i data-feather="users" class="submenu-icon"></i>
                                        <span>Employee Data</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/payroll/process.php" class="submenu-link <?php echo activeMenu('process.php'); ?>">
                                        <i data-feather="monitor" class="submenu-icon"></i>
                                        <span>Process Salary</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/payroll/slips.php" class="submenu-link <?php echo activeMenu('slips.php'); ?>">
                                        <i data-feather="file-text" class="submenu-icon"></i>
                                        <span>Salary Slips</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/payroll/attendance.php" class="submenu-link <?php echo activeMenu('attendance.php'); ?>">
                                        <i data-feather="map-pin" class="submenu-icon"></i>
                                        <span>Absensi GPS</span>
                                    </a>
                                </li>

                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Procurement Dropdown Menu -->
                    <?php if ($auth->hasPermission('procurement') && isModuleEnabled('procurement')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/procurement/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('procurement'); ?>">
                                <i data-feather="shopping-cart" class="nav-icon"></i>
                                <span><?php echo __('menu.procurement'); ?></span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/procurement/purchase-orders.php" class="submenu-link <?php echo activeMenu('purchase-orders.php'); ?>">
                                        <i data-feather="file-plus" class="submenu-icon"></i>
                                        <span><?php echo __('menu.purchase_orders'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/procurement/purchases.php" class="submenu-link <?php echo activeMenu('purchases.php'); ?>">
                                        <i data-feather="package" class="submenu-icon"></i>
                                        <span><?php echo __('menu.purchase_invoices'); ?></span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/procurement/suppliers.php" class="submenu-link <?php echo activeMenu('suppliers.php'); ?>">
                                        <i data-feather="users" class="submenu-icon"></i>
                                        <span><?php echo __('menu.suppliers'); ?></span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Laporan Dropdown Menu -->
                    <?php if ($auth->hasPermission('reports') && isModuleEnabled('reports')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/reports/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('reports'); ?>">
                                <i data-feather="bar-chart-2" class="nav-icon"></i>
                                <span><?php echo __('menu.reports'); ?></span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/reports/daily.php" class="submenu-link <?php echo activeMenu('daily.php'); ?>">
                                        <i data-feather="calendar" class="submenu-icon"></i>
                                        <span>Laporan Harian</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/reports/monthly.php" class="submenu-link <?php echo activeMenu('monthly.php'); ?>">
                                        <i data-feather="trending-up" class="submenu-icon"></i>
                                        <span>Laporan Bulanan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/reports/yearly.php" class="submenu-link <?php echo activeMenu('yearly.php'); ?>">
                                        <i data-feather="activity" class="submenu-icon"></i>
                                        <span>Laporan Tahunan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/reports/by-division.php" class="submenu-link <?php echo activeMenu('by-division.php'); ?>">
                                        <i data-feather="grid" class="submenu-icon"></i>
                                        <span>Laporan Per Divisi</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Invest & Projek Menu -->
                    <?php if ($auth->hasPermission('investor') && isModuleEnabled('investor')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/investor/" class="nav-link <?php echo activeMenu('investor'); ?>">
                                <i data-feather="briefcase" class="nav-icon"></i>
                                <span>Invest & Projek</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Project Menu -->
                    <?php if ($auth->hasPermission('project')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/project/" class="nav-link <?php echo activeMenu('project'); ?>">
                                <i data-feather="folder" class="nav-icon"></i>
                                <span>Project</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Finance Menu -->
                    <?php if ($auth->hasPermission('finance')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/finance/" class="nav-link <?php echo activeMenu('finance'); ?>">
                                <i data-feather="trending-up" class="nav-icon"></i>
                                <span>Manajemen Keuangan</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Owner Monitoring Menu -->
                    <?php if ($auth->hasPermission('owner')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/owner/" class="nav-link <?php echo activeMenu('owner'); ?>">
                                <i data-feather="eye" class="nav-icon"></i>
                                <span>Owner Monitoring</span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Database Master Menu (CQC) -->
                    <?php if ($auth->hasPermission('database')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/database/') !== false) ? 'open' : ''; ?>">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('database'); ?>">
                                <i data-feather="database" class="nav-icon"></i>
                                <span>Database</span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/database/" class="submenu-link">
                                        <i data-feather="home" class="submenu-icon"></i>
                                        <span>Dashboard</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/database/suppliers.php" class="submenu-link">
                                        <i data-feather="truck" class="submenu-icon"></i>
                                        <span>Supplier</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/database/customers.php" class="submenu-link">
                                        <i data-feather="users" class="submenu-icon"></i>
                                        <span>Customer</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/database/staff.php" class="submenu-link">
                                        <i data-feather="user-check" class="submenu-icon"></i>
                                        <span>Staf</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if ($auth->hasPermission('settings')): ?>
                        <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/settings/') !== false) ? 'open' : ''; ?>" style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                            <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('settings'); ?>">
                                <i data-feather="settings" class="nav-icon"></i>
                                <span><?php echo __('settings.title'); ?></span>
                            </a>
                            <ul class="submenu">
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/settings/" class="submenu-link <?php echo activeMenu('settings-index'); ?>">
                                        <i data-feather="home" class="submenu-icon"></i>
                                        <span>Beranda Settings</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/settings/change-password.php" class="submenu-link <?php echo activeMenu('change-password.php'); ?>">
                                        <i data-feather="lock" class="submenu-icon"></i>
                                        <span>Ganti Password</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/settings/company.php" class="submenu-link <?php echo activeMenu('company.php'); ?>">
                                        <i data-feather="briefcase" class="submenu-icon"></i>
                                        <span>Setup Perusahaan</span>
                                    </a>
                                </li>
                                <li class="submenu-item">
                                    <a href="<?php echo BASE_URL; ?>/modules/settings/display.php" class="submenu-link <?php echo activeMenu('display.php'); ?>">
                                        <i data-feather="eye" class="submenu-icon"></i>
                                        <span>Display & Theme</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item" style="margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link">
                            <i data-feather="log-out" class="nav-icon"></i>
                            <span><?php echo __('menu.logout'); ?></span>
                        </a>
                    </li>
                </ul>
            </nav>

            <!-- Sidebar Footer -->
            <?php
            // Get custom footer version from settings
            $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
            $sidebarFooterVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
            ?>
            <div class="sidebar-footer" style="padding: 1rem; border-top: 1px solid var(--bg-tertiary); margin-top: auto;">
                <div style="display: flex; align-items: center; gap: 0.875rem; margin-bottom: 0.75rem;">
                    <?php
                    $devLogoPath = BASE_PATH . '/' . DEVELOPER_LOGO;
                    if (file_exists($devLogoPath)):
                    ?>
                        <div style="width: 52px; height: 52px; border-radius: 8px; background: #0f172a; padding: 4px; display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <img src="<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>?v=<?php echo filemtime($devLogoPath); ?>" alt="Developer Logo" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    <?php else: ?>
                        <div style="width: 52px; height: 52px; border-radius: 8px; background: var(--primary-color); display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <span style="font-size: 1.5rem; font-weight: 700; color: white;">&lt;/&gt;</span>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);"><?php echo DEVELOPER_NAME; ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-muted);">Developer</div>
                    </div>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-muted); text-align: center; padding-top: 0.5rem; border-top: 1px solid var(--bg-tertiary);">
                    <?php echo htmlspecialchars($sidebarFooterVersion); ?> • <?php echo APP_YEAR; ?>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div>
                    <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
                    <?php if (isset($pageSubtitle)): ?>
                        <p style="color: var(--text-muted); margin-top: 0.5rem;"><?php echo $pageSubtitle; ?></p>
                    <?php endif; ?>
                </div>

                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <!-- End Shift Button -->
                    <a id="endShiftButton" href="<?php echo BASE_URL; ?>/print-end-shift-report.php" target="_blank" rel="noopener"
                        style="padding: 0.5rem 1rem; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: all 0.3s; text-decoration: none;">
                        <i data-feather="power" style="width: 18px; height: 18px;"></i>
                        <span>End Shift</span>
                    </a>

                    <!-- Notification Bell -->
                    <div id="adminNotifBell" style="position:relative;cursor:pointer;" onclick="toggleAdminNotif()">
                        <i data-feather="bell" style="width:22px;height:22px;color:var(--text-muted);transition:color .2s;"></i>
                        <span id="adminNotifBadge" style="display:none;position:absolute;top:-4px;right:-6px;background:#ef4444;color:#fff;font-size:0.6rem;font-weight:800;min-width:16px;height:16px;border-radius:8px;align-items:center;justify-content:center;padding:0 4px;"></span>
                    </div>

                    <!-- Notification Panel -->
                    <div id="adminNotifPanel" style="display:none;position:absolute;top:60px;right:120px;width:400px;max-height:500px;background:#fff;border-radius:12px;box-shadow:0 10px 50px rgba(0,0,0,.15);border:1px solid #e5e7eb;z-index:999;overflow:hidden;">
                        <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:700;font-size:0.9rem;color:#1e293b;">📋 Pengajuan Staff</span>
                            <span id="adminNotifCount" style="background:#ef4444;color:#fff;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:10px;display:none;">0</span>
                        </div>
                        <div id="adminNotifList" style="max-height:420px;overflow-y:auto;padding:4px 0;">
                            <div style="padding:30px;text-align:center;color:#94a3b8;font-size:0.8rem;">Memuat...</div>
                        </div>
                    </div>

                    <!-- Date & Time Display -->
                    <div style="text-align: right; padding-right: 1.5rem; border-right: 1px solid var(--bg-tertiary);">
                        <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);" id="currentDate">
                            <?php echo date('d/m/Y'); ?>
                        </div>
                        <div style="font-size: 0.875rem; font-weight: 700; color: var(--primary-color); font-variant-numeric: tabular-nums;" id="currentTime">
                            <?php echo date('H:i:s'); ?>
                        </div>
                    </div>

                    <!-- User Info -->
                    <div class="user-info">
                        <div style="text-align: right; margin-right: 1rem;">
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?php echo $_SESSION['full_name'] ?? 'User'; ?>
                            </div>
                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo ucfirst($_SESSION['role'] ?? 'staff'); ?>
                            </div>
                        </div>
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($success = getFlash('success')): ?>
                <div class="alert alert-success fade-in" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="check-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error = getFlash('error')): ?>
                <div class="alert alert-danger fade-in" style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="alert-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="page-content">

                <script>
                    // Business Switcher Function
                    function switchBusiness(businessId) {
                        if (confirm('Switch to selected business? Current page will reload.')) {
                            // Send AJAX request to switch business
                            fetch('<?php echo BASE_URL; ?>/api/switch-business.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: 'business_id=' + encodeURIComponent(businessId)
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Reload page to apply new business
                                        window.location.reload();
                                    } else {
                                        alert('Failed to switch business: ' + (data.message || 'Unknown error'));
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Failed to switch business. Please try again.');
                                });
                        } else {
                            // Reset select to current value
                            document.querySelector('select[onchange*="switchBusiness"]').value = '<?php echo ACTIVE_BUSINESS_ID; ?>';
                        }
                    }

                    // ═══ Admin Notification System ═══
                    let adminNotifOpen = false;
                    const NOTIF_BASE = '<?php echo BASE_URL; ?>';

                    function toggleAdminNotif() {
                        adminNotifOpen = !adminNotifOpen;
                        const panel = document.getElementById('adminNotifPanel');
                        if (adminNotifOpen) {
                            panel.style.display = 'block';
                            loadAdminNotifs();
                        } else {
                            panel.style.display = 'none';
                        }
                    }

                    document.addEventListener('click', function(e) {
                        if (adminNotifOpen && !e.target.closest('#adminNotifBell') && !e.target.closest('#adminNotifPanel')) {
                            adminNotifOpen = false;
                            document.getElementById('adminNotifPanel').style.display = 'none';
                        }
                    });

                    async function loadAdminNotifs() {
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_pending');
                            const data = await res.json();
                            const leaves = data.pending_leaves || [];
                            const overtimes = data.pending_overtimes || [];
                            const total = leaves.length + overtimes.length;

                            if (total === 0) {
                                document.getElementById('adminNotifList').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:0.8rem;">✅ Tidak ada pengajuan pending</div>';
                                return;
                            }

                            let html = '';
                            overtimes.forEach(o => {
                                html += `<div style="padding:12px 18px;border-bottom:1px solid #f8fafc;" id="notif-ot-${o.id}">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span style="font-size:14px;">⏰</span>
                                        <span style="font-weight:700;font-size:0.78rem;color:#1e293b;">${o.full_name}</span>
                                        <span style="margin-left:auto;background:#fef3c7;color:#92400e;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:8px;">LEMBUR</span>
                                    </div>
                                    <div style="font-size:0.75rem;color:#64748b;margin-bottom:8px;">📅 ${o.overtime_date} — ${o.reason||'Tidak ada keterangan'}</div>
                                    <div style="display:flex;gap:6px;">
                                        <button onclick="approveReject('overtime','approve',${o.id})" style="flex:1;padding:6px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">✅ Setujui</button>
                                        <button onclick="approveReject('overtime','reject',${o.id})" style="flex:1;padding:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">❌ Tolak</button>
                                    </div>
                                </div>`;
                            });
                            leaves.forEach(l => {
                                const tl = {
                                    cuti: '🏖️ Cuti',
                                    sakit: '🩺 Sakit',
                                    izin: '📋 Izin',
                                    cuti_khusus: '⭐ Cuti Khusus'
                                } [l.leave_type] || l.leave_type;
                                html += `<div style="padding:12px 18px;border-bottom:1px solid #f8fafc;" id="notif-lv-${l.id}">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span style="font-size:14px;">📝</span>
                                        <span style="font-weight:700;font-size:0.78rem;color:#1e293b;">${l.full_name}</span>
                                        <span style="margin-left:auto;background:#dbeafe;color:#1e40af;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:8px;">${tl}</span>
                                    </div>
                                    <div style="font-size:0.75rem;color:#64748b;margin-bottom:4px;">📅 ${l.start_date} s/d ${l.end_date}</div>
                                    ${l.reason ? `<div style="font-size:0.72rem;color:#64748b;margin-bottom:8px;">💬 ${l.reason}</div>` : ''}
                                    <div style="display:flex;gap:6px;">
                                        <button onclick="approveReject('leave','approve',${l.id})" style="flex:1;padding:6px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">✅ Setujui</button>
                                        <button onclick="approveReject('leave','reject',${l.id})" style="flex:1;padding:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">❌ Tolak</button>
                                    </div>
                                </div>`;
                            });
                            document.getElementById('adminNotifList').innerHTML = html;
                        } catch (e) {
                            document.getElementById('adminNotifList').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:0.8rem;">Gagal memuat</div>';
                        }
                    }

                    async function approveReject(type, action, id) {
                        const notes = prompt(action === 'reject' ? 'Alasan penolakan (opsional):' : 'Catatan (opsional):');
                        if (notes === null) return;
                        const fd = new FormData();
                        if (type === 'overtime') {
                            fd.append('action', action === 'approve' ? 'approve_overtime' : 'reject_overtime');
                            fd.append('overtime_id', id);
                        } else {
                            fd.append('action', action === 'approve' ? 'approve_leave' : 'reject_leave');
                            fd.append('leave_id', id);
                        }
                        fd.append('admin_notes', notes);
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_action', {
                                method: 'POST',
                                body: fd
                            });
                            const data = await res.json();
                            if (data.success) {
                                const el = document.getElementById('notif-' + (type === 'overtime' ? 'ot' : 'lv') + '-' + id);
                                if (el) {
                                    el.innerHTML = '<div style="padding:8px;text-align:center;color:' + (action === 'approve' ? '#16a34a' : '#ef4444') + ';font-size:0.78rem;font-weight:700;">' + (action === 'approve' ? '✅ Disetujui' : '❌ Ditolak') + '</div>';
                                    setTimeout(() => {
                                        el.style.display = 'none';
                                        checkAdminNotifs();
                                    }, 1500);
                                }
                            } else {
                                alert(data.message || 'Gagal memproses');
                            }
                        } catch (e) {
                            alert('Error: ' + e.message);
                        }
                    }

                    let _lastAdminCount = 0;
                    async function checkAdminNotifs() {
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_count');
                            const data = await res.json();
                            const count = data.pending_count || 0;
                            const badge = document.getElementById('adminNotifBadge');
                            const bell = document.getElementById('adminNotifBell');
                            if (badge) {
                                if (count > 0) {
                                    badge.textContent = count;
                                    badge.style.display = 'flex';
                                    if (count > _lastAdminCount && bell) {
                                        bell.style.animation = 'none';
                                        void bell.offsetWidth;
                                        bell.style.animation = 'bellShake .6s ease';
                                    }
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                            const cntEl = document.getElementById('adminNotifCount');
                            if (cntEl) {
                                if (count > 0) {
                                    cntEl.textContent = count;
                                    cntEl.style.display = 'inline';
                                } else {
                                    cntEl.style.display = 'none';
                                }
                            }
                            _lastAdminCount = count;
                            if (adminNotifOpen) loadAdminNotifs();
                        } catch (e) {
                            console.log('notif check err', e);
                        }
                    }
                    checkAdminNotifs();
                    setInterval(checkAdminNotifs, 15000);
                </script>
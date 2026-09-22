<?php

/**
 * Standalone config for the Sunsea public marketing website TEMPLATE.
 *
 * Deliberately independent from adf_system's shared config/config.php: that file
 * hardcodes ACTIVE_BUSINESS_ID via config/active-business.php (currently
 * 'narayana-hotel') and loads the multi-business Database class, which would make
 * this public site show the WRONG business's data. Instead this bootstrap only
 * defines the couple of constants website-bootstrap.php needs (BASE_PATH/BASE_URL)
 * and never loads adf_system's config/database.php - modules/sunsea/db-helper.php's
 * getSunseaConnection() automatically falls back to a direct PDO connection to the
 * Sunsea database whenever the app-wide `Database` class isn't loaded, so this page
 * always talks to Sunsea's own data regardless of what business is active elsewhere
 * in the admin panel.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

// website-sunsea/config/config.php -> up 2 levels = adf_system app root.
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__, 2));

if (!defined('BASE_URL')) {
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    if (php_sapi_name() !== 'cli') {
        $isLocal  = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
        // Matches adf_system/config/config.php's own convention so uploaded assets
        // (package photos, logo, etc.) resolve to the same shared uploads/ folder.
        $basePath = $isLocal ? '/adf_system' : '';
        define('BASE_URL', $protocol . '://' . $host . $basePath);
    } else {
        define('BASE_URL', 'http://localhost/adf_system');
    }
}

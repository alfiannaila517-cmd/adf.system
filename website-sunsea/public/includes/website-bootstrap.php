<?php

/**
 * Bootstrap shared by all public website pages (home, paket, galeri, kontak, dll).
 * Provides $pdo (sunsea DB) + basic company settings for header/footer.
 *
 * TEMPLATE COPY: adapted from karimunjawa-explore's includes/website-bootstrap.php to
 * live inside adf_system as a reusable template (see website-sunsea/config/config.php
 * for why this does NOT use adf_system's shared config/config.php).
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/functions.php';
require_once BASE_PATH . '/modules/sunsea/db-helper.php';

$pdo = getSunseaConnection();
sunseaEnsureWebsiteContentSchema($pdo);

$weCompanyName  = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
$weCompanyPhone = sunseaSetting($pdo, 'company_phone', '');
$weCompanyPhones = sunseaCompanyPhones($pdo);
$weCompanyEmail = sunseaSetting($pdo, 'company_email', '');
$weCompanyAddr  = sunseaSetting($pdo, 'company_address', 'Karimunjawa, Jepara, Jawa Tengah');

$weLogoSetting = sunseaSetting($pdo, 'company_logo', '');
$weLogoSrc = $weLogoSetting ? sunseaAssetUrl($weLogoSetting) : '';

$weWaAdmins = sunseaWaAdminList($pdo);

$weSocialLinks = [
    'facebook'  => sunseaSetting($pdo, 'website_social_facebook', ''),
    'instagram' => sunseaSetting($pdo, 'website_social_instagram', ''),
    'tiktok'    => sunseaSetting($pdo, 'website_social_tiktok', ''),
    'youtube'   => sunseaSetting($pdo, 'website_social_youtube', ''),
];

<?php
/**
 * Admin credentials for the ADF System website admin panel.
 * Change the password after first login (see admin/change-password.php).
 */

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '$2y$10$OIjepiL3Vidlisp1o44vdOn48aXANW2ZIwNZ1JKb24F7UXlb/Qdcu');

/**
 * Rewrites the ADMIN_PASSWORD_HASH constant in this file in place.
 * Used by admin/change-password.php since credentials are stored as
 * plain PHP constants here rather than in a database.
 */
function adf_admin_update_password_hash(string $newHash): bool
{
    $path = __FILE__;
    $contents = file_get_contents($path);
    if ($contents === false) {
        return false;
    }
    $updated = preg_replace(
        "/define\('ADMIN_PASSWORD_HASH',\s*'.*?'\);/",
        "define('ADMIN_PASSWORD_HASH', '" . addslashes($newHash) . "');",
        $contents,
        1,
        $count
    );
    if ($updated === null || $count !== 1) {
        return false;
    }
    return file_put_contents($path, $updated, LOCK_EX) !== false;
}

<?php
/**
 * Legacy single-admin credentials, kept only to seed the first user into
 * data/users.json the first time the site runs (see includes/users-store.php).
 * After that, all users/passwords/roles live in data/users.json, managed via
 * admin/users.php — editing these constants after the initial seed has no effect.
 */

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '$2y$10$nuCQmfuLN4E7vFOzbUJkD.BdIxeoNvBe78Wq1MmA1EMtVcCglD/xW');
define('ADMIN_EMAIL', 'admin@adfsystem.store');


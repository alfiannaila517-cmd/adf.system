<?php
require_once __DIR__ . '/../includes/admin-auth.php';
adf_admin_logout();
header('Location: login.php');
exit;

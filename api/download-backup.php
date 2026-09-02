<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can download backups
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    die('Akses ditolak. Hanya admin atau developer yang bisa download backup.');
}

if (!isset($_GET['file'])) {
    http_response_code(400);
    die('File tidak ditemukan.');
}

$filename = basename($_GET['file']);
$filePath = __DIR__ . '/../backups/' . $filename;

// Security check: only allow .sql files
if (pathinfo($filename, PATHINFO_EXTENSION) !== 'sql') {
    http_response_code(403);
    die('Tipe file tidak diizinkan.');
}

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File tidak ditemukan.');
}

// Large backups (e.g. Bens Cafe) can take a while to stream out.
@set_time_limit(300);
@ini_set('zlib.output_compression', 'Off');

// config.php's global ob_start() (plus session/gzip buffers) can leave extra
// bytes ahead of the file content, making the real body size no longer match
// the Content-Length header below -> browser aborts with ERR_INVALID_RESPONSE.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Force download
header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

// Read file and send to output
readfile($filePath);
exit;

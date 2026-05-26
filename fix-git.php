<?php
// One-time script to fix git conflict on hosting
// Upload via cPanel File Manager to /home/adfb2574/public_html/fix-git.php
// Then open: https://adfsystem.online/fix-git.php?token=adf-deploy-2025-secure
// DELETE THIS FILE AFTER USE

$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', $token)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
$dir = dirname(__FILE__);
$repo = 'ratn050-oss/adf_system';
$branch = 'main';

echo "=== Fix Git Conflicts - Direct File Overwrite ===\n";
echo "Directory: {$dir}\n\n";

// Must match current HEAD on server to make git see files as "clean"
$currentHead = '929d6c727cd2f0cd4fb1a46973407e7920d7929f';

// Conflict files that need to be restored to current HEAD version
$conflictFiles = ['.htaccess', 'config/config.php'];

$ctx = stream_context_create([
    'http' => ['timeout' => 30, 'user_agent' => 'ADF-Fix/1.0'],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

foreach ($conflictFiles as $file) {
    $url = "https://raw.githubusercontent.com/{$repo}/{$currentHead}/{$file}";
    $localPath = $dir . '/' . $file;
    
    echo "Downloading: {$file}\n";
    echo "  URL: {$url}\n";
    
    $content = @file_get_contents($url, false, $ctx);
    
    if ($content === false) {
        echo "  ERROR: Failed to download\n";
        // Show PHP error
        $err = error_get_last();
        echo "  Detail: " . ($err['message'] ?? 'unknown') . "\n\n";
        continue;
    }
    
    echo "  Downloaded: " . strlen($content) . " bytes\n";
    
    // Ensure directory exists
    $fileDir = dirname($localPath);
    if (!is_dir($fileDir)) {
        @mkdir($fileDir, 0755, true);
    }
    
    $written = @file_put_contents($localPath, $content);
    if ($written !== false) {
        echo "  WRITTEN: {$written} bytes to {$localPath}\n\n";
    } else {
        echo "  ERROR: Failed to write to {$localPath}\n\n";
    }
}

echo "=== Files overwritten. Now go to cPanel Git → Update from Remote → Deploy HEAD Commit ===\n";
echo "=== THEN DELETE THIS FILE! ===\n";

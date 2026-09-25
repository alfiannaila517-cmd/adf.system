<?php
/**
 * Safe image upload helper for admin panel (hero background, product images).
 */

function adf_upload_image(string $fieldName, string $subdir): ?string
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return null;
    }

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];
    if (!isset($allowed[$info[2]])) {
        return null;
    }
    $ext = $allowed[$info[2]];

    $uploadDir = __DIR__ . '/../uploads/' . trim($subdir, '/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return null;
    }

    return 'uploads/' . trim($subdir, '/') . '/' . $filename;
}

function adf_delete_uploaded_image(string $relativePath): void
{
    if ($relativePath === '' || strpos($relativePath, 'uploads/') !== 0) {
        return;
    }
    $path = __DIR__ . '/../' . $relativePath;
    if (is_file($path)) {
        @unlink($path);
    }
}

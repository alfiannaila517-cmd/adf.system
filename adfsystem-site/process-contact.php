<?php
/**
 * Handles the contact form submission from kontak.php.
 * Basic validation + header-injection-safe mail() usage.
 */
require_once __DIR__ . '/includes/site-config.php';

function redirect_with(string $query): never
{
    header('Location: kontak.php?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kontak.php');
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

// Strip any newline characters to prevent header injection via name/email fields.
$name = preg_replace('/[\r\n]+/', ' ', $name);
$email = preg_replace('/[\r\n]+/', ' ', $email);

if ($name === '' || $email === '' || $message === '') {
    redirect_with('error=' . rawurlencode('Semua kolom wajib diisi.'));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with('error=' . rawurlencode('Format email tidak valid.'));
}

if (mb_strlen($name) > 120 || mb_strlen($email) > 160 || mb_strlen($message) > 2000) {
    redirect_with('error=' . rawurlencode('Isian terlalu panjang.'));
}

$to = CONTACT_EMAIL;
$subject = 'Pesan Baru dari Formulir Kontak ' . SITE_NAME;
$body = "Nama: {$name}\nEmail: {$email}\n\nPesan:\n{$message}\n";

$headers = "From: no-reply@adfsystem.id\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sentOk = @mail($to, $subject, $body, $headers);

if ($sentOk) {
    redirect_with('sent=1');
}

redirect_with('error=' . rawurlencode('Gagal mengirim pesan saat ini. Silakan hubungi kami langsung via email.'));

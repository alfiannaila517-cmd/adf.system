<?php

/** Kembali dari Google: email terverifikasi yang sama dengan user Console → langsung masuk (tanpa kode email). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

[$email, $err] = Google::callback($_GET);
if ($email === null) {
    $_SESSION['login_error'] = $err;
    redirect('/login.php');
}
if (Auth::isLocked($email)) {
    $_SESSION['login_error'] = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    redirect('/login.php');
}
$st = Auth::db()->prepare('SELECT * FROM console_users WHERE LOWER(email) = ? LIMIT 1');
$st->execute([$email]);
$user = $st->fetch();
if (!$user) {
    Auth::recordFail($email);
    Telegram::security('⚠️', 'Login Google ADF Console DITOLAK', ['Email' => $email, 'IP' => Auth::ip()]);
    $_SESSION['login_error'] = "Akun Google {$email} tidak terdaftar sebagai user Console.";
    redirect('/login.php');
}
Auth::complete($user, 'Google (' . $email . ')');
redirect('/');

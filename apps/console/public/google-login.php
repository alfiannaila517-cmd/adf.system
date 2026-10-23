<?php

/** Mulai "Masuk dengan Google": arahkan ke halaman pilih akun Google. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (Auth::user()) {
    redirect('/');
}
$url = Google::authUrl();
if (!$url) {
    redirect('/login.php?google=off');
}
redirect($url);

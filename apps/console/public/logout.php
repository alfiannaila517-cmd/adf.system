<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (Auth::user()) {
    Auth::audit('logout');
}
Auth::logout();
redirect('/login.php');

<?php

/**
 * CSRF untuk halaman Gudang Nasita.
 *
 * Pakai di baris paling atas halaman (setelah auth/session aktif):
 *     require_once __DIR__ . '/../../includes/gudang_csrf.php';
 *     gudangCsrfProtect();            // halaman HTML: verifikasi POST + suntik token ke form & fetch
 *     gudangCsrfProtect(false);       // endpoint JSON: hanya verifikasi
 *
 * Token disimpan di sesi. POST wajib membawa `_csrf` (field form) atau header `X-CSRF-Token`.
 * Untuk halaman HTML, skrip kecil disisipkan sebelum </head>: menambah field `_csrf` ke setiap form POST
 * (termasuk form yang dibuat lewat JS & form.submit()) dan header ke setiap fetch/XHR POST same-origin,
 * jadi form yang sudah ada tidak perlu diubah satu per satu.
 */
if (!function_exists('gudangCsrfToken')) {
    function gudangCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['gudang_csrf'])) {
            $_SESSION['gudang_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['gudang_csrf'];
    }

    /** Tolak (403) bila request bukan GET/HEAD/OPTIONS dan tokennya tidak cocok. */
    function gudangCsrfVerify(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals(gudangCsrfToken(), $sent)) {
            http_response_code(403);
            $wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
                || strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.']);
            } else {
                echo 'Sesi tidak valid (CSRF). Muat ulang halaman lalu coba lagi.';
            }
            exit;
        }
    }

    function gudangCsrfProtect(bool $injectHtml = true): void
    {
        gudangCsrfVerify();
        if (!$injectHtml) {
            return;
        }
        $token = gudangCsrfToken();
        $js = '<script>(function(){var T=' . json_encode($token) . ';'
            . 'function add(f){if(!f||(f.method||"get").toLowerCase()!=="post")return;'
            . 'var i=f.querySelector("input[name=_csrf]");if(!i){i=document.createElement("input");i.type="hidden";i.name="_csrf";f.appendChild(i);}i.value=T;}'
            . 'document.addEventListener("submit",function(e){add(e.target);},true);'
            . 'var s=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){add(this);return s.apply(this,arguments);};'
            . 'var sub=HTMLFormElement.prototype.requestSubmit;if(sub){HTMLFormElement.prototype.requestSubmit=function(){add(this);return sub.apply(this,arguments);};}'
            . 'var f=window.fetch;if(f){window.fetch=function(u,o){try{var m=((o&&o.method)||(u&&u.method)||"GET").toUpperCase();'
            . 'var url=new URL((u&&u.url)||u,location.href);if(m!=="GET"&&m!=="HEAD"&&url.origin===location.origin){o=o||{};'
            . 'var h=new Headers(o.headers||(u&&u.headers)||{});h.set("X-CSRF-Token",T);o.headers=h;}}catch(x){}return f.call(this,u,o);};}'
            . 'var xo=XMLHttpRequest.prototype.open,xs=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m){this.__m=String(m).toUpperCase();return xo.apply(this,arguments);};'
            . 'XMLHttpRequest.prototype.send=function(){try{if(this.__m&&this.__m!=="GET"&&this.__m!=="HEAD")this.setRequestHeader("X-CSRF-Token",T);}catch(x){}return xs.apply(this,arguments);};'
            . '})();</script>';
        ob_start(function ($html) use ($js) {
            // Hanya halaman HTML: lewati JSON, ekspor Excel/CSV, unduhan, dll.
            foreach (headers_list() as $h) {
                if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
                    return $html;
                }
                if (stripos($h, 'Content-Disposition:') === 0) {
                    return $html;
                }
            }
            $pos = stripos($html, '</head>');
            if ($pos === false) {
                return $js . $html;
            }
            return substr($html, 0, $pos) . $js . substr($html, $pos);
        });
    }
}

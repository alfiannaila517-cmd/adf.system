<?php
/**
 * Tag <head> untuk aplikasi ADF Store Admin (PWA): bisa "Add to Home Screen" di iPhone/Android,
 * tampil layar penuh, ikon sendiri, dan menghormati notch / home bar iPhone (viewport-fit=cover).
 * Dipakai di semua halaman admin (admin-header.php) dan halaman login.
 */
?>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b0e14">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ADF Admin">
    <meta name="format-detection" content="telephone=no">
    <link rel="apple-touch-icon" href="app-icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="app-icons/favicon-32.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
        }
    </script>

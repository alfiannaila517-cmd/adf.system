<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/invoices-store.php';
require_once __DIR__ . '/../includes/invoice-render.php';
require_once __DIR__ . '/../includes/invoice-mail.php';
adf_admin_require_role('admin');

$id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
$inv = $id !== '' ? adf_invoice_find($id) : null;
if (!$inv) {
    header('Location: invoices.php');
    exit;
}
$settings = adf_invoice_settings();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adf_admin_csrf_check($_POST['csrf'] ?? null) && ($_POST['action'] ?? '') === 'email') {
    [$ok, $err] = adf_invoice_send_email($inv, $settings);
    header('Location: invoice-view.php?id=' . urlencode($id) . '&mail=' . ($ok ? 'ok' : 'fail') . ($ok ? '' : '&err=' . urlencode($err)));
    exit;
}
if (isset($_GET['saved'])) {
    $flash = 'Invoice tersimpan.';
} elseif (($_GET['mail'] ?? '') === 'ok') {
    $flash = 'Email invoice terkirim ke ' . ($inv['customer']['email'] ?? '') . '.';
} elseif (($_GET['mail'] ?? '') === 'fail') {
    $flash = 'Email gagal dikirim: ' . (string) ($_GET['err'] ?? 'SMTP error');
}

$url = adf_invoice_public_url($inv);
$csrf = adf_admin_csrf_token();
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$actions = '<a href="invoice-edit.php?id=' . $e(urlencode($id)) . '">Ubah</a>'
    . '<button type="button" id="copyLink" data-url="' . $e($url) . '">Salin link pelanggan</button>'
    . (($inv['customer']['email'] ?? '') !== '' && ($inv['status'] ?? '') !== 'draft'
        ? '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="id" value="' . $e($id) . '"><input type="hidden" name="action" value="email"><button type="submit" onclick="return confirm(\'Kirim invoice ke email pelanggan?\')">Kirim email</button></form>'
        : '')
    . '<a href="invoices.php">Daftar</a>'
    . '<script>document.addEventListener("click",function(ev){var b=ev.target.closest("#copyLink");if(!b)return;var u=b.dataset.url;(navigator.clipboard?navigator.clipboard.writeText(u):Promise.reject()).then(function(){b.textContent="Link tersalin ✓"}).catch(function(){window.prompt("Salin link:",u)});});</script>';

adf_invoice_render_document($inv, $settings, ['asset_base' => '../', 'actions' => $actions, 'flash' => $flash]);

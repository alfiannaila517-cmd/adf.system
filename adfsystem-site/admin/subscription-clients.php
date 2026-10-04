<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
adf_admin_require_role('admin');

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        adf_subscription_client_delete((string) ($_POST['client_key'] ?? ''));
        header('Location: subscription-clients.php');
        exit;
    } elseif (in_array($_POST['action'] ?? '', ['lock', 'unlock'], true)) {
        // Kunci / buka sistem klien dari sini. Klien membaca status ini saat sinkron (maks. 1 menit).
        $lockKey = (string) ($_POST['client_key'] ?? '');
        if (adf_subscription_client_find($lockKey)) {
            adf_subscription_client_upsert([
                'client_key' => $lockKey,
                'locked' => $_POST['action'] === 'lock',
                'locked_at' => $_POST['action'] === 'lock' ? date('c') : null,
            ]);
        }
        header('Location: subscription-clients.php');
        exit;
    } else {
        $clientKey = trim($_POST['client_key'] ?? '');
        $clientName = trim($_POST['client_name'] ?? '');
        $baseFee = (float) str_replace(['.', ','], ['', '.'], $_POST['base_fee'] ?? '0');
        $perGuestFee = (float) str_replace(['.', ','], ['', '.'], $_POST['per_guest_fee'] ?? '0');
        $subscriptionStartDate = trim($_POST['subscription_start_date'] ?? '');
        $dueDateOverride = trim($_POST['due_date_override'] ?? '');
        $pakasirSlug = trim($_POST['pakasir_slug'] ?? '');
        $pakasirApiKey = trim($_POST['pakasir_api_key'] ?? '');
        $pakasirWebhookSecret = trim($_POST['pakasir_webhook_secret'] ?? '');
        $notifyEmail = trim($_POST['notify_email'] ?? '');
        $existingToken = trim($_POST['existing_token'] ?? '');
        $regenerateToken = isset($_POST['regenerate_token']);

        if ($clientKey === '' || $clientName === '') {
            $error = 'Client Key dan Nama Klien wajib diisi.';
        } else {
            $token = $existingToken;
            if ($regenerateToken || $token === '') {
                $token = bin2hex(random_bytes(24));
            }
            $ok = adf_subscription_client_upsert([
                'client_key' => $clientKey,
                'client_name' => $clientName,
                'base_fee' => $baseFee,
                'per_guest_fee' => $perGuestFee,
                'subscription_start_date' => $subscriptionStartDate,
                'due_date_override' => $dueDateOverride,
                'pakasir_slug' => $pakasirSlug,
                'pakasir_api_key' => $pakasirApiKey,
                'pakasir_webhook_secret' => $pakasirWebhookSecret,
                'notify_email' => $notifyEmail,
                'client_token' => $token,
            ]);
            if ($ok) {
                $saved = true;
            } else {
                $error = 'Gagal menyimpan data klien.';
            }
        }
    }
}

$clients = adf_subscription_clients_load();
$csrf = adf_admin_csrf_token();

$editClient = null;
if (!empty($_GET['edit'])) {
    $editClient = adf_subscription_client_find((string) $_GET['edit']);
}

$adminPageTitle = 'Klien Langganan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide sc-page">
    <div class="sc-head">
        <div>
            <h1>Klien Langganan</h1>
            <p>Biaya bulanan, jatuh tempo, dan kunci sistem klien ADF System.</p>
        </div>
        <?php if (!$editClient): ?>
            <button type="button" class="btn btn-primary" onclick="var d=document.getElementById('scForm');d.open=!d.open;">+ Tambah Klien</button>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Data klien tersimpan.</div>
    <?php endif; ?>

    <!-- Form tambah/edit: tertutup kecuali sedang edit atau ada error, supaya daftar klien muat satu layar -->
    <details id="scForm" class="sc-card" <?php echo ($editClient || $error) ? 'open' : ''; ?>>
        <summary><?php echo $editClient ? 'Edit Klien: ' . htmlspecialchars($editClient['client_name'] ?? '') : 'Tambah Klien'; ?></summary>
        <form method="post" class="admin-form sc-form">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="existing_token" value="<?php echo htmlspecialchars($editClient['client_token'] ?? ''); ?>">
            <label>Client Key
                <input type="text" name="client_key" required placeholder="karimunjawa-explore" value="<?php echo htmlspecialchars($editClient['client_key'] ?? ''); ?>" <?php echo $editClient ? ' readonly' : ''; ?>>
            </label>
            <label>Nama Klien
                <input type="text" name="client_name" required placeholder="Karimunjawa Explore" value="<?php echo htmlspecialchars($editClient['client_name'] ?? ''); ?>">
            </label>
            <label>Biaya / Bulan (Rp)
                <input type="text" name="base_fee" value="<?php echo htmlspecialchars((string) ($editClient['base_fee'] ?? 150000)); ?>">
            </label>
            <label>Biaya / Tamu (Rp)
                <input type="text" name="per_guest_fee" value="<?php echo htmlspecialchars((string) ($editClient['per_guest_fee'] ?? 5000)); ?>">
            </label>
            <label>Mulai Langganan
                <input type="date" name="subscription_start_date" value="<?php echo htmlspecialchars($editClient['subscription_start_date'] ?? ($editClient ? '' : date('Y-m-d'))); ?>">
            </label>
            <label>Jatuh Tempo <small>(kosong = mulai +1 bln)</small>
                <input type="date" name="due_date_override" value="<?php echo htmlspecialchars($editClient['due_date_override'] ?? ''); ?>">
            </label>
            <label>Slug Pakasir
                <input type="text" name="pakasir_slug" value="<?php echo htmlspecialchars($editClient['pakasir_slug'] ?? ''); ?>">
            </label>
            <label>API Key Pakasir
                <input type="text" name="pakasir_api_key" value="<?php echo htmlspecialchars($editClient['pakasir_api_key'] ?? ''); ?>">
            </label>
            <label>Webhook Secret
                <input type="text" name="pakasir_webhook_secret" value="<?php echo htmlspecialchars($editClient['pakasir_webhook_secret'] ?? ''); ?>">
            </label>
            <label>Email Notifikasi
                <input type="email" name="notify_email" placeholder="owner@email.com" value="<?php echo htmlspecialchars($editClient['notify_email'] ?? ''); ?>">
            </label>
            <div class="sc-form-foot">
                <label class="admin-checkbox-line"><input type="checkbox" name="regenerate_token" value="1"> Buat ulang Client Token</label>
                <span class="sc-spacer"></span>
                <?php if ($editClient): ?>
                    <a href="subscription-clients.php" class="btn">Batal</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?php echo $editClient ? 'Simpan Perubahan' : 'Simpan Klien'; ?></button>
            </div>
        </form>
    </details>

    <div class="sc-card sc-table-card">
        <table class="sc-table">
            <thead>
                <tr>
                    <th>Klien</th>
                    <th>Status</th>
                    <th>Biaya</th>
                    <th>Jatuh Tempo</th>
                    <th>Email Notifikasi</th>
                    <th>Client Token</th>
                    <th class="sc-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c):
                    // Jatuh tempo pertama: tanggal Jatuh Tempo kalau diisi, kalau kosong Mulai Langganan + 1 bulan; lalu tanggal yang sama tiap bulan.
                    $firstDue = '';
                    $dueDay = 0;
                    if (!empty($c['due_date_override'])) {
                        $firstDue = $c['due_date_override'];
                        $dueDay = (int) date('j', strtotime($c['due_date_override']));
                    } elseif (!empty($c['subscription_start_date'])) {
                        $startTs = strtotime($c['subscription_start_date']);
                        $nextMonth = strtotime(date('Y-m-01', $startTs) . ' +1 month');
                        $firstDue = date('Y-m-', $nextMonth) . sprintf('%02d', min((int) date('j', $startTs), (int) date('t', $nextMonth)));
                        $dueDay = (int) date('j', $startTs);
                    }
                    $locked = !empty($c['locked']);
                    $jsName = htmlspecialchars(addslashes($c['client_name'] ?? ''));
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($c['client_name'] ?? '-'); ?></strong>
                            <small><?php echo htmlspecialchars($c['client_key'] ?? '-'); ?></small>
                        </td>
                        <td>
                            <span class="sc-badge <?php echo $locked ? 'sc-badge-red' : 'sc-badge-green'; ?>" title="<?php echo $locked && !empty($c['locked_at']) ? 'Dikunci ' . htmlspecialchars(date('d M Y H:i', strtotime($c['locked_at']))) : ''; ?>"><?php echo $locked ? 'Terkunci' : 'Aktif'; ?></span>
                        </td>
                        <td>
                            <strong>Rp <?php echo number_format((float) ($c['base_fee'] ?? 0), 0, ',', '.'); ?></strong>
                            <?php if ((float) ($c['per_guest_fee'] ?? 0) > 0): ?>
                                <small>+ Rp <?php echo number_format((float) $c['per_guest_fee'], 0, ',', '.'); ?>/tamu</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $dueDay ? 'Tgl ' . $dueDay . ' tiap bulan' : '-'; ?>
                            <?php if ($firstDue): ?><small>Mulai <?php echo htmlspecialchars(date('d M Y', strtotime($firstDue))); ?></small><?php endif; ?>
                        </td>
                        <td class="sc-email" title="<?php echo htmlspecialchars($c['notify_email'] ?? ''); ?>"><?php echo htmlspecialchars(($c['notify_email'] ?? '') ?: '-'); ?></td>
                        <td>
                            <span class="sc-token" title="<?php echo htmlspecialchars($c['client_token'] ?? ''); ?>"><?php echo htmlspecialchars(substr((string) ($c['client_token'] ?? ''), 0, 8)); ?>…</span>
                            <button type="button" class="sc-btn" data-token="<?php echo htmlspecialchars($c['client_token'] ?? ''); ?>" onclick="navigator.clipboard.writeText(this.dataset.token);this.textContent='Tersalin';setTimeout(()=>this.textContent='Salin',1500);">Salin</button>
                        </td>
                        <td class="sc-actions">
                            <a href="subscription-clients.php?edit=<?php echo urlencode($c['client_key'] ?? ''); ?>" class="sc-btn">Edit</a>
                            <a href="subscription-manual-invoice.php?client=<?php echo urlencode($c['client_key'] ?? ''); ?>" class="sc-btn">Tagih</a>
                            <form method="POST" onsubmit="return confirm('<?php echo $locked ? 'Buka kunci' : 'Kunci'; ?> sistem <?php echo $jsName; ?>?');">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="<?php echo $locked ? 'unlock' : 'lock'; ?>">
                                <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($c['client_key'] ?? ''); ?>">
                                <button type="submit" class="sc-btn <?php echo $locked ? 'sc-btn-green' : 'sc-btn-amber'; ?>"><?php echo $locked ? 'Buka Kunci' : 'Kunci'; ?></button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Hapus klien <?php echo $jsName; ?>?');">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($c['client_key'] ?? ''); ?>">
                                <button type="submit" class="sc-btn sc-btn-red">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="7" class="sc-empty">Belum ada klien.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="sc-note">
        API klien: <code><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/api/subscription-config.php'); ?></code>
        — isi <strong>Client Key</strong> &amp; <strong>Client Token</strong> di halaman Tagihan Langganan klien.
    </p>
</div>
<style>
    .sc-page { font-size: 12.5px; }
    .sc-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
    .sc-head h1 { font-size: 17px; margin: 0 0 2px; }
    .sc-head p { margin: 0; font-size: 12px; opacity: .6; }
    .admin-body .sc-page .btn { padding: 6px 12px; font-size: 12px; border-radius: 7px; }
    .sc-card { background: rgba(255, 255, 255, .03); border: 1px solid rgba(255, 255, 255, .08); border-radius: 10px; margin-bottom: 12px; }
    details.sc-card > summary { cursor: pointer; padding: 9px 12px; font-weight: 600; font-size: 12.5px; list-style: none; }
    details.sc-card > summary::-webkit-details-marker { display: none; }
    details.sc-card > summary::before { content: '▸ '; opacity: .5; }
    details.sc-card[open] > summary::before { content: '▾ '; }
    .admin-body .sc-form { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px 10px; padding: 0 12px 12px; margin: 0; max-width: none; }
    .admin-body .sc-form label { font-size: 11px; gap: 3px; margin: 0; }
    .sc-form label small { opacity: .55; font-weight: 400; }
    .admin-body .sc-form input[type="text"],
    .admin-body .sc-form input[type="email"],
    .admin-body .sc-form input[type="date"] { padding: 5px 8px; font-size: 12px; height: 30px; border-radius: 6px; }
    .sc-form-foot { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; }
    .admin-body .sc-form-foot .admin-checkbox-line { font-size: 11.5px; margin: 0; }
    .sc-spacer { flex: 1; }
    .sc-table-card { overflow-x: auto; }
    .sc-table { width: 100%; border-collapse: collapse; }
    .sc-table th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; opacity: .55; font-weight: 600; padding: 7px 10px; border-bottom: 1px solid rgba(255, 255, 255, .08); white-space: nowrap; }
    .sc-table td { padding: 7px 10px; border-bottom: 1px solid rgba(255, 255, 255, .05); vertical-align: middle; white-space: nowrap; font-size: 12px; }
    .sc-table tr:last-child td { border-bottom: 0; }
    .sc-table tbody tr:hover { background: rgba(255, 255, 255, .025); }
    .sc-table td small { display: block; font-size: 10.5px; opacity: .55; margin-top: 1px; }
    .sc-table th.sc-right, .sc-table td.sc-actions { text-align: right; }
    .sc-email { max-width: 190px; overflow: hidden; text-overflow: ellipsis; }
    .sc-token { font-family: ui-monospace, Consolas, monospace; font-size: 11px; opacity: .7; margin-right: 4px; }
    .sc-badge { display: inline-block; font-size: 10.5px; font-weight: 600; padding: 2px 7px; border-radius: 99px; }
    .sc-badge-green { background: rgba(34, 197, 94, .14); color: #4ade80; }
    .sc-badge-red { background: rgba(239, 68, 68, .15); color: #f87171; }
    .sc-actions form { display: inline; margin: 0; }
    .admin-body .sc-btn { display: inline-block; padding: 3px 8px; font-size: 11px; font-weight: 500; line-height: 1.4; border-radius: 6px; border: 1px solid rgba(255, 255, 255, .12); background: rgba(255, 255, 255, .04); color: inherit; text-decoration: none; cursor: pointer; margin-left: 2px; }
    .admin-body .sc-btn:hover { background: rgba(255, 255, 255, .1); }
    .admin-body .sc-btn.sc-btn-amber { color: #fbbf24; border-color: rgba(251, 191, 36, .35); }
    .admin-body .sc-btn.sc-btn-green { color: #4ade80; border-color: rgba(74, 222, 128, .35); }
    .admin-body .sc-btn.sc-btn-red { color: #f87171; border-color: rgba(248, 113, 113, .35); }
    .sc-empty { text-align: center; opacity: .5; padding: 18px !important; }
    .sc-note { font-size: 11px; opacity: .55; margin: 4px 0 0; }
    .sc-note code { font-size: 10.5px; }
    @media (max-width: 1100px) { .admin-body .sc-form { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .admin-body .sc-form { grid-template-columns: 1fr 1fr; } .sc-head { flex-wrap: wrap; } }
</style>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

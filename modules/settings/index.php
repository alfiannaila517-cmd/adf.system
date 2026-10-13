<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check permission
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan';

// Auto-create settings table if not exists
try {
    $tableExists = $db->fetchOne("SHOW TABLES LIKE 'settings'");
    if (!$tableExists) {
        $sql = file_get_contents('../../database-settings.sql');
        $db->getConnection()->exec($sql);
        setFlashMessage('success', '✅ Settings table berhasil dibuat dengan data default!');
    }
} catch (Exception $e) {
    // Continue if table exists
}


$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan';

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// Business feature detection (config-based)
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isCQC = $hasProjectModule; // Legacy compatibility

include '../../includes/header.php';
?>
<?php
// ---- Daftar menu hub Pengaturan: [link, ikon feather, judul, deskripsi, warna A, warna B] dikelompokkan ----
$shGroups = $isCQC
    ? [
        ['Umum', [
            ['company.php', 'briefcase', 'Pengaturan Perusahaan', 'Edit nama, logo, alamat, kontak CQC untuk laporan dan tampilan', '#f0b429', '#b8860b'],
            ['change-password.php', 'lock', 'Ganti Password', 'Ubah password akun Anda dengan verifikasi password lama', '#6366f1', '#4338ca'],
        ]],
        ['Laporan & Shift', [
            ['report-settings.php', 'file-text', 'Pengaturan Laporan PDF', 'Konfigurasi header laporan: logo, nama, alamat yang tampil di PDF', '#3b82f6', '#1d4ed8'],
        ]],
        ['Data & Keamanan', [
            ['reset.php', 'database', 'Reset Data', 'Backup database, reset data buku kas CQC atau data proyek', '#ef4444', '#b91c1c'],
        ]],
    ]
    : [
        ['Umum', [
            ['company.php', 'briefcase', 'Pengaturan Perusahaan', 'Edit nama, logo, alamat, kontak perusahaan untuk laporan dan tampilan', '#6366f1', '#4338ca'],
            ['display.php', 'monitor', 'Pengaturan Tampilan', 'Atur format tanggal, mata uang, timezone, tema gelap/terang dan bahasa', '#ec4899', '#be185d'],
            ['change-password.php', 'lock', 'Ganti Password', 'Ubah password akun Anda dengan verifikasi password lama', '#8b5cf6', '#6d28d9'],
        ]],
        ['Struktur & Data Master', [
            ['divisions.php', 'grid', 'Kelola Divisi', 'Tambah, edit, hapus divisi hotel (Restaurant, SPA, Laundry, dll)', '#10b981', '#047857'],
            ['categories.php', 'tag', 'Kelola Kategori', 'Kelola kategori transaksi per divisi (pemasukan & pengeluaran)', '#f59e0b', '#b45309'],
        ]],
        ['Laporan & Shift', [
            ['report-settings.php', 'file-text', 'Pengaturan Laporan PDF', 'Konfigurasi header laporan: logo, nama, alamat yang tampil di PDF', '#3b82f6', '#1d4ed8'],
            ['end-shift.php', 'power', 'End Shift Configuration', 'Atur nomor WhatsApp GM/Admin untuk menerima laporan End Shift otomatis', '#f97316', '#c2410c'],
        ]],
        ['Data & Keamanan', [
            ['reset.php', 'database', 'Backup & Reset Data', 'Backup database, restore data, atau reset data tertentu', '#eab308', '#a16207'],
            ['reset-audit-log.php', 'trash-2', 'Reset Audit Log', 'Hapus semua catatan audit log sistem untuk membersihkan data lama', '#ef4444', '#b91c1c'],
        ]],
    ];

$shDbVersion = '';
try {
    $shDbVersion = (string)($db->fetchOne("SELECT VERSION() AS v")['v'] ?? '');
} catch (\Throwable $e) {
}
$shServer = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
$shInfo = [
    ['cpu', 'PHP Version', PHP_VERSION],
    ['database', 'Database', 'MySQL ' . $shDbVersion],
    ['server', 'Server', $shServer],
    ['package', 'Application Version', '1.0.0'],
];
?>
<style>
    .sh-wrap { max-width: 1100px; }
    .sh-group { margin: 0 0 1.5rem; }
    .sh-label { display: flex; align-items: center; gap: .6rem; margin: 0 0 .7rem .1rem; font-size: .68rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--text-muted, #94a3b8); }
    .sh-label::after { content: ''; flex: 1; height: 1px; background: var(--border-color, rgba(148, 163, 184, .25)); }
    .sh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: .85rem; }
    .sh-tile { position: relative; display: flex; align-items: center; gap: .95rem; padding: .95rem 1rem; text-decoration: none !important;
        background: var(--bg-secondary, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 16px;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .sh-tile:hover { transform: translateY(-2px); border-color: var(--sh-a); box-shadow: 0 12px 26px rgba(15, 23, 42, .14); }
    .sh-ico { flex: none; width: 46px; height: 46px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, var(--sh-a), var(--sh-b)); box-shadow: 0 6px 14px rgba(0, 0, 0, .2); }
    .sh-ico svg { width: 22px; height: 22px; stroke: #fff !important; color: #fff !important; fill: none !important; stroke-width: 2; }
    .sh-txt { min-width: 0; flex: 1; }
    .sh-txt strong { display: block; font-size: .93rem; font-weight: 700; line-height: 1.25; color: var(--text-primary, #1e293b) !important; }
    .sh-txt small { display: block; margin-top: 3px; font-size: .75rem; line-height: 1.4; color: var(--text-secondary, #64748b) !important; }
    .sh-go { flex: none; width: 18px; height: 18px; stroke: var(--text-muted, #94a3b8) !important; fill: none !important; stroke-width: 2; transition: transform .18s ease; }
    .sh-tile:hover .sh-go { transform: translateX(3px); stroke: var(--sh-a) !important; }
    .sh-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem; padding: 1rem 1.1rem; margin-top: .5rem;
        background: var(--bg-secondary, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 16px; }
    .sh-info > div { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .sh-info .sh-i { flex: none; width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: rgba(99, 102, 241, .12); }
    .sh-info .sh-i svg { width: 17px; height: 17px; stroke: #6366f1 !important; fill: none !important; stroke-width: 2; }
    .sh-info small { display: block; font-size: .66rem; letter-spacing: .04em; color: var(--text-muted, #94a3b8); }
    .sh-info b { display: block; font-size: .82rem; font-weight: 600; color: var(--text-primary, #1e293b); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 640px) { .sh-grid { grid-template-columns: 1fr; } }
</style>

<div class="sh-wrap">
    <?php foreach ($shGroups as [$shLabel, $shItems]): ?>
        <section class="sh-group">
            <div class="sh-label"><?php echo htmlspecialchars($shLabel); ?></div>
            <div class="sh-grid">
                <?php foreach ($shItems as [$shHref, $shIcon, $shTitle, $shDesc, $shA, $shB]): ?>
                    <a href="<?php echo htmlspecialchars($shHref); ?>" class="sh-tile" style="--sh-a: <?php echo $shA; ?>; --sh-b: <?php echo $shB; ?>;">
                        <span class="sh-ico"><i data-feather="<?php echo $shIcon; ?>"></i></span>
                        <span class="sh-txt">
                            <strong><?php echo htmlspecialchars($shTitle); ?></strong>
                            <small><?php echo htmlspecialchars($shDesc); ?></small>
                        </span>
                        <i data-feather="chevron-right" class="sh-go"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="sh-group">
        <div class="sh-label">Informasi Sistem</div>
        <div class="sh-info">
            <?php foreach ($shInfo as [$shInfoIcon, $shInfoLabel, $shInfoValue]): ?>
                <div>
                    <span class="sh-i"><i data-feather="<?php echo $shInfoIcon; ?>"></i></span>
                    <span style="min-width:0">
                        <small><?php echo htmlspecialchars($shInfoLabel); ?></small>
                        <b title="<?php echo htmlspecialchars($shInfoValue); ?>"><?php echo htmlspecialchars($shInfoValue); ?></b>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
    if (window.feather) feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>

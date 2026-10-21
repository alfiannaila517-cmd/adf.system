<?php

/**
 * FRONT DESK - PENGATURAN HARGA AGENT
 * Daftar harga khusus (Agent, Travel A, …) berisi harga /malam per tipe kamar. Dipakai lewat dropdown
 * "Harga" di penawaran (Cek Ketersediaan → Penawaran, dan Ubah Penawaran): harga semua kamar menyesuaikan otomatis.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AgentRates.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/dashboard.php');
    exit;
}

$db = Database::getInstance();

// ---- Simpan (PRG): seluruh daftar dalam satu kiriman ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plans = [];
    foreach ((array)($_POST['plans'] ?? []) as $p) {
        if (!empty($p['delete'])) continue;
        $plans[] = $p;
    }
    try {
        AgentRates::save($db, $plans);
        $_SESSION['flash_success'] = 'Harga Agent disimpan.';
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = 'Gagal menyimpan: ' . $e->getMessage();
    }
    header('Location: agent-rates.php');
    exit;
}

$types = AgentRates::roomTypes($db);
$plans = AgentRates::plans($db);
$plans[] = ['id' => '', 'name' => '', 'note' => '', 'prices' => [], 'new' => true]; // kartu kosong untuk daftar baru
$flashOk = $_SESSION['flash_success'] ?? '';
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$pageTitle = 'Harga Agent';
include '../../includes/header.php';
?>
<style>
    .ar-wrap { max-width: 980px; }
    .ar-top { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .ar-top h2 { margin: 0; font-size: 1.15rem; color: var(--text-primary, #1e293b); }
    .ar-top p { margin: 3px 0 0; font-size: .8rem; color: var(--text-secondary, #64748b); }
    .ar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
    .ar-card { background: var(--bg-secondary, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 12px 14px; }
    .ar-card.is-new { border-style: dashed; }
    .ar-card h3 { margin: 0 0 8px; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: var(--text-secondary, #64748b); }
    .ar-in { box-sizing: border-box; width: 100%; height: 34px; padding: 0 10px; border: 1px solid var(--border-color, #e2e8f0); border-radius: 9px; background: var(--bg-primary, #fff); color: var(--text-primary, #1e293b); font: inherit; font-size: .85rem; }
    .ar-in.num { text-align: right; }
    .ar-row { display: grid; grid-template-columns: minmax(0, 1fr) 92px 116px; gap: 8px; align-items: center; padding: 5px 0; border-top: 1px solid var(--border-color, #e2e8f0); font-size: .82rem; color: var(--text-primary, #1e293b); }
    .ar-row small { color: var(--text-secondary, #64748b); text-align: right; }
    .ar-head { font-size: .66rem; letter-spacing: .06em; text-transform: uppercase; color: var(--text-secondary, #64748b); border-top: 0; }
    .ar-field { margin-bottom: 8px; }
    .ar-field label { display: block; font-size: .7rem; color: var(--text-secondary, #64748b); margin-bottom: 3px; }
    .ar-del { display: flex; gap: 6px; align-items: center; margin-top: 8px; font-size: .75rem; color: #dc2626; }
    .ar-save { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
    .ar-btn { height: 38px; padding: 0 18px; border: 0; border-radius: 10px; background: var(--primary, #6366f1); color: #fff; font-weight: 700; cursor: pointer; }
    .ar-note { font-size: .74rem; color: var(--text-secondary, #64748b); }
    .ar-flash { padding: 10px 14px; border-radius: 10px; margin-bottom: 12px; font-size: .85rem; }
    .ar-flash.ok { background: rgba(16, 185, 129, .12); color: #047857; }
    .ar-flash.err { background: rgba(239, 68, 68, .12); color: #b91c1c; }
    @media (max-width: 520px) { .ar-row { grid-template-columns: minmax(0, 1fr) 104px; } .ar-row small { display: none; } }
</style>

<div class="ar-wrap">
    <?php $sxActiveKey = 'agent_rates'; include __DIR__ . '/settings-nav.php'; ?>
    <?php if ($flashOk): ?><div class="ar-flash ok"><?php echo htmlspecialchars($flashOk); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="ar-flash err"><?php echo htmlspecialchars($flashErr); ?></div><?php endif; ?>

    <div class="ar-top">
        <div>
            <h2>Harga Agent</h2>
            <p>Buat daftar harga khusus per tipe kamar. Di penawaran, pilih daftar ini di dropdown <b>Harga</b> — harga semua kamar menyesuaikan otomatis (masih bisa diketik ulang).</p>
        </div>
    </div>

    <?php if (!$types): ?>
        <div class="ar-card">Belum ada tipe kamar. Atur dulu di <a href="settings.php?tab=rooms">Room &amp; Tipe</a>.</div>
    <?php else: ?>
        <form method="post">
            <div class="ar-grid">
                <?php foreach ($plans as $i => $p): $isNew = !empty($p['new']); ?>
                    <div class="ar-card<?php echo $isNew ? ' is-new' : ''; ?>">
                        <h3><?php echo $isNew ? '+ Daftar harga baru' : 'Daftar harga'; ?></h3>
                        <input type="hidden" name="plans[<?php echo $i; ?>][id]" value="<?php echo htmlspecialchars($p['id']); ?>">
                        <div class="ar-field"><label>Nama</label><input class="ar-in" name="plans[<?php echo $i; ?>][name]" value="<?php echo htmlspecialchars($p['name']); ?>" placeholder="<?php echo $isNew ? 'mis. Agent, Travel A' : ''; ?>" maxlength="60"></div>
                        <div class="ar-field"><label>Catatan (opsional)</label><input class="ar-in" name="plans[<?php echo $i; ?>][note]" value="<?php echo htmlspecialchars($p['note']); ?>" placeholder="mis. kontrak 2026" maxlength="200"></div>
                        <div class="ar-row ar-head"><span>Tipe kamar</span><small>Normal</small><span style="text-align:right">Harga /malam</span></div>
                        <?php foreach ($types as $t): $v = $p['prices'][(string)$t['id']] ?? ''; ?>
                            <div class="ar-row">
                                <span><?php echo htmlspecialchars($t['name']); ?></span>
                                <small>Rp <?php echo number_format($t['base'], 0, ',', '.'); ?></small>
                                <input class="ar-in num" name="plans[<?php echo $i; ?>][prices][<?php echo (int)$t['id']; ?>]" value="<?php echo $v === '' ? '' : (int)$v; ?>" inputmode="numeric" placeholder="= normal">
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$isNew): ?>
                            <label class="ar-del"><input type="checkbox" name="plans[<?php echo $i; ?>][delete]" value="1"> Hapus daftar ini</label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="ar-save">
                <button class="ar-btn" type="submit">Simpan</button>
                <span class="ar-note">Kosongkan harga = ikut harga normal tipe kamar.</span>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>

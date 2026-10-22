<?php
/**
 * Developer Panel - Maintenance
 * Tutup sementara seluruh sistem untuk staf (mis. saat pemindahan data ke server baru): pemberitahuan + hitung mundur.
 * Status disimpan di maintenance.json (lihat includes/maintenance.php), bukan database.
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();
$pageTitle = 'Maintenance';

$file = adf_maintenance_file();
$flash = '';
$flashType = 'success';

// Simpan status maintenance (ditulis atomik; cookie supaya browser developer ini tetap bisa memakai sistem)
$writeState = static function (array $state) use ($file): bool {
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return @rename($tmp, $file);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $cur = adf_maintenance_state();
    if ($action === 'on') {
        $minutes = max(5, min(720, (int)($_POST['minutes'] ?? 45)));
        $pass = bin2hex(random_bytes(16));
        $state = [
            'on' => true,
            'until' => time() + $minutes * 60,
            'message' => mb_substr(trim((string)($_POST['message'] ?? '')), 0, 500),
            'website' => !empty($_POST['website']),
            'pass' => $pass,
            'started' => date('c'),
        ];
        if ($writeState($state)) {
            setcookie('adf_maint_pass', $pass, ['expires' => time() + 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
            $flash = "Maintenance AKTIF selama ±{$minutes} menit. Staf melihat pemberitahuan; browser Anda tetap bisa memakai sistem.";
        } else {
            $flash = 'Gagal menulis maintenance.json — periksa izin tulis folder utama.';
            $flashType = 'danger';
        }
    } elseif ($action === 'extend' && $cur) {
        $cur['until'] = max(time(), (int)$cur['until']) + 15 * 60;
        $writeState($cur);
        $flash = 'Perkiraan selesai ditambah 15 menit.';
    } elseif ($action === 'off') {
        if (is_file($file)) {
            @unlink($file);
        }
        setcookie('adf_maint_pass', '', ['expires' => time() - 3600, 'path' => '/']);
        $flash = 'Maintenance DIMATIKAN — sistem terbuka kembali untuk semua staf.';
    } elseif ($action === 'move') {
        // Pindah alamat: simpan / hapus domain-move.json
        $mode = (string)($_POST['mode'] ?? 'off');
        $from = strtolower(trim((string)($_POST['from'] ?? '')));
        $to = strtolower(trim((string)($_POST['to'] ?? '')));
        $domainRe = '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/';
        if ($mode === 'off') {
            @unlink(adf_domain_move_file());
            $flash = 'Pindah alamat dimatikan — alamat lama dibuka seperti biasa.';
        } elseif (!in_array($mode, ['info', 'direct'], true) || !preg_match($domainRe, $from) || !preg_match($domainRe, $to) || $from === $to) {
            $flash = 'Alamat lama / baru tidak valid.';
            $flashType = 'danger';
        } elseif (@file_put_contents(adf_domain_move_file(), json_encode(['from' => $from, 'to' => $to, 'mode' => $mode, 'since' => date('c')]), LOCK_EX) === false) {
            $flash = 'Gagal menulis domain-move.json — periksa izin tulis folder utama.';
            $flashType = 'danger';
        } else {
            $flash = $mode === 'info' ? "Pengunjung {$from} melihat info alamat baru {$to}, lalu otomatis pindah." : "Pengunjung {$from} langsung dialihkan ke {$to}.";
        }
    }
}
$move = adf_domain_move_state();

$state = adf_maintenance_state();
// Developer yang membuka halaman ini selalu diberi akses lewat (mis. setelah alamat pindah ke server baru)
if ($state && !empty($state['pass']) && ($_COOKIE['adf_maint_pass'] ?? '') !== $state['pass'] && !headers_sent()) {
    setcookie('adf_maint_pass', (string)$state['pass'], ['expires' => time() + 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
}
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:760px">
    <h4 class="mb-1"><i class="bi bi-cone-striped me-2"></i>Maintenance</h4>
    <p class="text-secondary small mb-4">Tutup sistem sementara untuk semua staf (mis. saat pemindahan data). Developer Panel dan browser Anda tetap bisa dipakai.</p>

    <?php if ($flash): ?><div class="alert alert-<?= $flashType ?> py-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if ($state): $left = max(0, (int)$state['until'] - time()); ?>
        <div class="card border-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge bg-warning text-dark mb-2">AKTIF</span>
                        <div class="fw-semibold">Perkiraan selesai <?= date('H:i', (int)$state['until']) ?> WIB <span class="text-secondary small">(±<?= (int)ceil($left / 60) ?> menit lagi)</span></div>
                        <div class="small text-secondary">Mulai <?= htmlspecialchars(date('d M H:i', strtotime((string)($state['started'] ?? 'now')))) ?> · Website hotel <?= !empty($state['website']) ? 'ikut ditutup' : 'tetap buka' ?></div>
                        <?php if (!empty($state['message'])): ?><div class="small mt-1">“<?= htmlspecialchars((string)$state['message']) ?>”</div><?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="post"><input type="hidden" name="action" value="extend"><button class="btn btn-outline-secondary btn-sm">+15 menit</button></form>
                        <form method="post" onsubmit="return confirm('Buka kembali sistem untuk semua staf?')"><input type="hidden" name="action" value="off"><button class="btn btn-success btn-sm"><i class="bi bi-unlock me-1"></i>Selesai — buka sistem</button></form>
                    </div>
                </div>
            </div>
        </div>
        <p class="small text-secondary">Lihat tampilan staf: buka sistem di jendela <b>InPrivate / Incognito</b>.</p>
    <?php else: ?>
        <div class="card mb-3">
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Tutup sistem untuk semua staf sekarang?')">
                    <input type="hidden" name="action" value="on">
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <label class="form-label small">Perkiraan durasi (menit)</label>
                            <input type="number" name="minutes" value="45" min="5" max="720" class="form-control">
                        </div>
                        <div class="col-sm-8">
                            <label class="form-label small">Pesan untuk staf (opsional)</label>
                            <input name="message" class="form-control" maxlength="500" placeholder="mis. Pemindahan ke server baru. Setelah selesai login seperti biasa.">
                        </div>
                        <div class="col-12">
                            <label class="form-check-label small"><input type="checkbox" name="website" value="1" class="form-check-input me-1"> Website hotel (narayanakarimunjawa.com) ikut ditutup — pemesanan tamu berhenti sementara</label>
                        </div>
                    </div>
                    <button class="btn btn-warning mt-3"><i class="bi bi-cone-striped me-1"></i>Aktifkan maintenance</button>
                </form>
            </div>
        </div>
        <p class="small text-secondary">Status: <span class="badge bg-success">sistem terbuka normal</span></p>
    <?php endif; ?>

    <h5 class="mt-5 mb-1"><i class="bi bi-signpost-split me-2"></i>Pindah alamat</h5>
    <p class="text-secondary small mb-3">Staf yang membuka alamat lama diarahkan ke alamat baru. Webhook, API, cron, dan Developer Panel tetap jalan di alamat lama.</p>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="move">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small">Alamat lama</label>
                        <input name="from" class="form-control" value="<?= htmlspecialchars((string)($move['from'] ?? 'adfsystem.online')) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Alamat baru</label>
                        <input name="to" class="form-control" value="<?= htmlspecialchars((string)($move['to'] ?? 'nasita.biz.id')) ?>">
                    </div>
                    <div class="col-12 small">
                        <?php $cm = $move['mode'] ?? 'off'; ?>
                        <label class="d-block"><input type="radio" name="mode" value="off" class="form-check-input me-1" <?= $cm === 'off' ? 'checked' : '' ?>> Mati — alamat lama dibuka seperti biasa</label>
                        <label class="d-block"><input type="radio" name="mode" value="info" class="form-check-input me-1" <?= $cm === 'info' ? 'checked' : '' ?>> Info dulu — tampil "Alamat sistem sudah pindah" + tips menyimpan, otomatis pindah dalam 10 detik <span class="text-secondary">(minggu-minggu awal)</span></label>
                        <label class="d-block"><input type="radio" name="mode" value="direct" class="form-check-input me-1" <?= $cm === 'direct' ? 'checked' : '' ?>> Langsung pindah — tanpa halaman info <span class="text-secondary">(setelah staf terbiasa)</span></label>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm mt-3">Simpan</button>
                <?php if ($move): ?><span class="badge bg-info text-dark ms-2">AKTIF · <?= $move['mode'] === 'info' ? 'info dulu' : 'langsung' ?></span><?php endif; ?>
            </form>
        </div>
    </div>
    <p class="small text-secondary mt-2">Uji dulu: isi alamat lama <code>staging.adfsystem.online</code>, buka di jendela InPrivate, lalu kembalikan ke Mati.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

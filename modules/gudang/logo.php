<?php

/**
 * Gudang Nasita — Logo Perusahaan
 * Upload / ganti / hapus logo yang tampil di sidebar dan dokumen gudang.
 * Disimpan di settings: company_logo_<ACTIVE_BUSINESS_ID> (dibaca oleh getBusinessLogo()).
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('settings'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Logo Perusahaan';
$settingKey = 'company_logo_' . ACTIVE_BUSINESS_ID;
$uploadDir = BASE_PATH . '/uploads/logos/';
$defaultLogo = BASE_URL . '/assets/img/gudang-nasita-logo.svg';

$row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$settingKey]);
$currentValue = $row['setting_value'] ?? '';

/** Hapus file logo lokal lama (URL Cloudinary dibiarkan, ditimpa oleh public_id yang sama). */
function gudangDeleteLocalLogo(string $value, string $dir): void
{
    if ($value === '' || strpos($value, 'http') === 0) return;
    $path = $dir . basename($value);
    if (is_file($path)) @unlink($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $file = $_FILES['logo'] ?? null;
        $mimeExt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'Pilih file logo terlebih dahulu.');
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            setFlash('error', 'Ukuran file maksimal 2 MB.');
        } else {
            // Validasi isi file benar-benar gambar (bukan hanya ekstensi) — cegah upload skrip.
            $info = @getimagesize($file['tmp_name']);
            $ext = $info ? ($mimeExt[$info['mime']] ?? null) : null;
            if (!$ext) {
                setFlash('error', 'Format tidak didukung. Gunakan PNG, JPG, WEBP, atau GIF.');
            } else {
                $localFilename = ACTIVE_BUSINESS_ID . '_logo_' . time() . '.' . $ext;
                $result = CloudinaryHelper::getInstance()->smartUpload($file, 'uploads/logos', $localFilename, 'logos', $settingKey);

                if (!empty($result['success'])) {
                    if ($currentValue !== '' && $currentValue !== $result['path']) {
                        gudangDeleteLocalLogo($currentValue, $uploadDir);
                    }
                    if ($row) {
                        $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$result['path'], $settingKey]);
                    } else {
                        $db->insert('settings', [
                            'setting_key'   => $settingKey,
                            'setting_value' => $result['path'],
                            'setting_type'  => 'string',
                            'description'   => 'Company logo for ' . BUSINESS_NAME,
                        ]);
                    }
                    unset($_SESSION['adf_biz_logo_cache']); // logo di kartu Switch Business ikut diperbarui
                    setFlash('success', 'Logo berhasil diperbarui.');
                } else {
                    setFlash('error', 'Gagal mengunggah logo: ' . htmlspecialchars($result['error'] ?? 'unknown error'));
                }
            }
        }
    } elseif ($action === 'remove' && $currentValue !== '') {
        gudangDeleteLocalLogo($currentValue, $uploadDir);
        $db->query("DELETE FROM settings WHERE setting_key = ?", [$settingKey]);
        unset($_SESSION['adf_biz_logo_cache']);
        setFlash('success', 'Logo dihapus. Sidebar kembali memakai logo bawaan.');
    }

    header('Location: logo.php');
    exit;
}

// URL logo yang sedang aktif (sama dengan yang tampil di sidebar).
$logoUrl = $defaultLogo;
$isCustom = false;
if ($currentValue !== '') {
    if (strpos($currentValue, 'http') === 0) {
        $logoUrl = $currentValue;
        $isCustom = true;
    } elseif (is_file($uploadDir . basename($currentValue))) {
        $logoUrl = BASE_URL . '/uploads/logos/' . rawurlencode(basename($currentValue)) . '?v=' . filemtime($uploadDir . basename($currentValue));
        $isCustom = true;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
    .lg-wrap { max-width: 760px; --lg-line: #d5dde8; --lg-card: #fff; }
    body[data-theme="dark"] .lg-wrap { --lg-line: rgba(255, 255, 255, .14); --lg-card: rgba(255, 255, 255, .04); }
    .lg-card {
        border-radius: .75rem; padding: 1.1rem 1.2rem;
        background: var(--lg-card); border: 1px solid var(--lg-line);
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }
    .lg-head { font-size: 1.05rem; font-weight: 700; margin: 0 0 .15rem; }
    .lg-sub { font-size: .78rem; color: var(--text-muted); margin: 0 0 1rem; }
    .lg-grid { display: grid; grid-template-columns: 180px 1fr; gap: 1.25rem; align-items: start; }
    .lg-preview { text-align: center; }
    .lg-circle {
        width: 132px; height: 132px; margin: 0 auto .5rem; border-radius: 50%; overflow: hidden;
        background: #fff; box-shadow: 0 0 0 1px var(--lg-line), 0 0 0 5px rgba(37, 99, 235, .08);
    }
    .lg-circle img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .lg-badge { display: inline-block; font-size: .66rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; }
    .lg-badge.custom { background: #dcfce7; color: #166534; }
    .lg-badge.default { background: #e2e8f0; color: #475569; }
    .lg-drop {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .3rem;
        border: 1.5px dashed var(--lg-line); border-radius: .65rem; padding: 1.1rem; cursor: pointer;
        font-size: .8rem; color: var(--text-muted); text-align: center; transition: border-color .15s, background .15s;
    }
    .lg-drop:hover, .lg-drop.drag { border-color: #2563eb; background: rgba(37, 99, 235, .05); }
    .lg-drop b { color: var(--text-primary, #0f172a); font-size: .84rem; }
    .lg-drop input { display: none; }
    .lg-tips { font-size: .74rem; color: var(--text-muted); margin: .7rem 0 0; padding-left: 1rem; line-height: 1.6; }
    .lg-actions { display: flex; gap: .5rem; margin-top: .9rem; flex-wrap: wrap; }
    .lg-btn { border: none; border-radius: .5rem; padding: .5rem 1rem; font-size: .8rem; font-weight: 600; cursor: pointer; }
    .lg-btn.pri { background: #2563eb; color: #fff; }
    .lg-btn.pri:disabled { opacity: .5; cursor: not-allowed; }
    .lg-btn.del { background: transparent; color: #b91c1c; border: 1px solid #fecaca; }
    @media (max-width: 640px) { .lg-grid { grid-template-columns: 1fr; } }
</style>

<div class="lg-wrap">
    <div class="lg-card">
        <h2 class="lg-head">Logo Perusahaan</h2>
        <p class="lg-sub">Logo ini tampil di sidebar dan dokumen Gudang Nasita.</p>

        <div class="lg-grid">
            <div class="lg-preview">
                <div class="lg-circle"><img id="lgPreview" src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo"></div>
                <span class="lg-badge <?php echo $isCustom ? 'custom' : 'default'; ?>"><?php echo $isCustom ? 'Logo kustom' : 'Logo bawaan'; ?></span>
            </div>

            <div>
                <form method="post" enctype="multipart/form-data" id="lgForm">
                    <input type="hidden" name="action" value="upload">
                    <label class="lg-drop" id="lgDrop">
                        <input type="file" name="logo" id="lgFile" accept="image/png,image/jpeg,image/webp,image/gif">
                        <b id="lgName">Klik atau tarik file logo ke sini</b>
                        <span>PNG, JPG, WEBP, atau GIF · maks. 2 MB</span>
                    </label>
                    <ul class="lg-tips">
                        <li>Gunakan gambar persegi (mis. 512 × 512 px) agar pas di lingkaran.</li>
                        <li>Logo dengan latar penuh (tidak transparan) terlihat paling rapi.</li>
                    </ul>
                    <div class="lg-actions">
                        <button type="submit" class="lg-btn pri" id="lgSave" disabled>Simpan Logo</button>
                    </div>
                </form>

                <?php if ($isCustom): ?>
                    <form method="post" onsubmit="return confirm('Hapus logo dan kembali ke logo bawaan?');" style="margin-top:.5rem">
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" class="lg-btn del">Hapus Logo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const input = document.getElementById('lgFile');
        const drop = document.getElementById('lgDrop');
        const save = document.getElementById('lgSave');
        const name = document.getElementById('lgName');
        const preview = document.getElementById('lgPreview');

        // Pratinjau langsung sebelum disimpan.
        function show(file) {
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('Ukuran file maksimal 2 MB.');
                input.value = '';
                save.disabled = true;
                return;
            }
            name.textContent = file.name;
            preview.src = URL.createObjectURL(file);
            save.disabled = false;
        }

        input.addEventListener('change', () => show(input.files[0]));
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
        drop.addEventListener('drop', e => {
            if (!e.dataTransfer.files.length) return;
            input.files = e.dataTransfer.files;
            show(input.files[0]);
        });
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

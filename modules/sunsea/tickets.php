<?php
/**
 * Sunsea - Database Tiket (Kapal, BTN, dll)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $payload = [
        'ticket_type' => $_POST['ticket_type'] ?? 'kapal',
        'ticket_name' => trim($_POST['ticket_name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'unit' => trim($_POST['unit'] ?? 'pax'),
        'price_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
        'price_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($payload['ticket_name'] === '') {
        $_SESSION['flash_message'] = 'Nama tiket wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: tickets.php');
        exit;
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE tickets
            SET ticket_type=?, ticket_name=?, description=?, unit=?, price_cost=?, price_sell=?, notes=?, is_active=?, updated_at=NOW()
            WHERE id=?")
            ->execute([
                $payload['ticket_type'], $payload['ticket_name'], $payload['description'], $payload['unit'],
                $payload['price_cost'], $payload['price_sell'], $payload['notes'], $payload['is_active'], $id
            ]);
        $_SESSION['flash_message'] = 'Data tiket berhasil diperbarui.';
    } else {
        $lastCode = $pdo->query("SELECT ticket_code FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
        $next = 1;
        if ($lastCode && preg_match('/(\\d+)$/', $lastCode, $m)) {
            $next = (int)$m[1] + 1;
        }
        $code = 'SS-TKT-' . str_pad($next, 3, '0', STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO tickets
            (ticket_code, ticket_type, ticket_name, description, unit, price_cost, price_sell, notes, is_active)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                $code, $payload['ticket_type'], $payload['ticket_name'], $payload['description'], $payload['unit'],
                $payload['price_cost'], $payload['price_sell'], $payload['notes'], $payload['is_active']
            ]);
        $_SESSION['flash_message'] = 'Database tiket berhasil ditambahkan.';
    }

    $_SESSION['flash_type'] = 'success';
    header('Location: tickets.php');
    exit;
}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM tickets ORDER BY is_active DESC, ticket_type, ticket_name")->fetchAll();
} catch (Exception $e) {
    $_SESSION['flash_message'] = 'Tabel tickets belum ada. Jalankan update database Sunsea terbaru terlebih dahulu.';
    $_SESSION['flash_type'] = 'error';
}

$pageTitle = 'Database Tiket';
$activePage = 'database';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:400px 1fr;gap:18px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Tiket</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div class="ss-form-group">
                <label class="ss-label">Tipe Tiket</label>
                <select name="ticket_type" class="ss-select">
                    <option value="express_bahari">Express Bahari</option>
                    <option value="ferry">Ferry Siginjal</option>
                    <option value="pesawat_susi">Pesawat Susi Air</option>
                </select>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Nama Tiket *</label>
                <input class="ss-input" name="ticket_name" required placeholder="Kapal Reguler PP / BTN Anak">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Deskripsi</label>
                <input class="ss-input" name="description" placeholder="Jam berangkat, estimasi waktu, dll">
            </div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group">
                    <label class="ss-label">Unit</label>
                    <input class="ss-input" name="unit" value="pax">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Harga Modal</label>
                    <input class="ss-input" name="price_cost" placeholder="0">
                </div>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Harga Jual</label>
                <input class="ss-input" name="price_sell" placeholder="0">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Catatan</label>
                <textarea class="ss-textarea" name="notes" placeholder="Catatan khusus"></textarea>
            </div>
            <div class="ss-form-group">
                <label><input type="checkbox" name="is_active" checked> Aktif</label>
            </div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Tiket</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Database Tiket</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Tipe</th>
                        <th>Nama Tiket</th>
                        <th>Deskripsi</th>
                        <th>Harga Modal</th>
                        <th>Harga Jual</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--ss-muted);">Belum ada data tiket.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['ticket_code'] ?? '-'); ?></td>
                            <td><?php echo ucfirst($r['ticket_type']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['ticket_name']); ?></strong><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['description'] ?: '-'); ?></small></td>
                            <td><?php echo htmlspecialchars($r['description'] ?: '-'); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['price_cost']); ?></td>
                            <td><strong><?php echo sunseaRupiah((float)$r['price_sell']); ?></strong></td>
                            <td><?php echo $r['is_active'] ? '<span class="ss-status ss-status-approved">Aktif</span>' : '<span class="ss-status ss-status-draft">Nonaktif</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';

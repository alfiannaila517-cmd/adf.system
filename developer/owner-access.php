<?php
/**
 * Developer Panel - Owner Monitoring Access Management
 * Configure which businesses each owner user can access for monitoring
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Akses Owner';

$success = '';
$error = '';

// Check & show success message from session
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Ensure user_business_assignment table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_business_assignment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        business_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_business (user_id, business_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Get all businesses
$businesses = [];
try {
    $businesses = $pdo->query("SELECT id, business_code, business_name, business_type, database_name, is_active FROM businesses WHERE is_active = 1 ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get all users (except developer) for monitoring access management
$owners = [];
try {
    $owners = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.is_active, u.last_login, r.role_name, r.role_code
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code != 'developer' AND u.is_active = 1
        ORDER BY r.role_code, u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Ensure owner_footer_config table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS owner_footer_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        menu_key VARCHAR(50) NOT NULL,
        menu_order INT DEFAULT 0,
        is_enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_menu (user_id, menu_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Available footer menu definitions
$footerMenuDefs = [
    'home' => ['label' => 'Home', 'icon' => 'bi-house-fill', 'desc' => 'Dashboard utama', 'always' => true],
    'frontdesk' => ['label' => 'Frontdesk', 'icon' => 'bi-calendar3', 'desc' => 'Monitor kamar & booking'],
    'projects' => ['label' => 'Projects', 'icon' => 'bi-graph-up', 'desc' => 'Monitor investor & proyek'],
    'cashbook' => ['label' => 'Cashbook', 'icon' => 'bi-wallet2', 'desc' => 'Kas harian'],
    'capital' => ['label' => 'Capital', 'icon' => 'bi-bank', 'desc' => 'Modal & investasi'],
    'health' => ['label' => 'Health', 'icon' => 'bi-clipboard2-pulse', 'desc' => 'Laporan kesehatan bisnis'],
    'logout' => ['label' => 'Logout', 'icon' => 'bi-box-arrow-right', 'desc' => 'Keluar', 'always' => true],
];

// Get current assignments for all users
$assignments = [];
try {
    $stmt = $pdo->query("SELECT user_id, business_id FROM user_business_assignment");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['user_id']][] = (int)$row['business_id'];
    }
} catch (Exception $e) {}

// Business code to slug mapping (for display)
$codeToSlug = [
    'BENSCAFE' => 'bens-cafe',
    'NARAYANAHOTEL' => 'narayana-hotel',
    'DEMO' => 'demo'
];

// Business type icons
$typeIcons = [
    'hotel' => 'bi-building',
    'restaurant' => 'bi-cup-hot',
    'cafe' => 'bi-cup-hot',
    'retail' => 'bi-shop',
    'manufacture' => 'bi-gear',
    'tourism' => 'bi-globe',
    'general' => 'bi-grid',
    'other' => 'bi-grid'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    // Save owner business access
    if ($formAction === 'save_owner_access') {
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $businessIds = $_POST['business_ids'] ?? [];
        
        if ($ownerId) {
            try {
                $pdo->beginTransaction();
                
                // Remove all existing assignments for this owner
                $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ?")->execute([$ownerId]);
                
                // Insert new assignments
                if (!empty($businessIds)) {
                    $insertStmt = $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id, assigned_at) VALUES (?, ?, NOW())");
                    foreach ($businessIds as $bizId) {
                        $insertStmt->execute([$ownerId, (int)$bizId]);
                    }
                }
                
                $pdo->commit();
                
                // Get owner name for message
                $ownerName = '';
                foreach ($owners as $o) {
                    if ($o['id'] == $ownerId) {
                        $ownerName = $o['full_name'];
                        break;
                    }
                }
                
                $auth->logAction('update_owner_access', 'user_business_assignment', $ownerId, null, [
                    'owner_id' => $ownerId,
                    'business_ids' => $businessIds
                ]);
                
                $_SESSION['success_message'] = "Akses monitoring untuk <strong>{$ownerName}</strong> berhasil diperbarui! (" . count($businessIds) . " bisnis)";
                header("Location: owner-access.php");
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }
    
    // Bulk save all owners at once
    if ($formAction === 'save_all_access') {
        $allAccess = $_POST['access'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
// Clear all user assignments (non-developer users only)
                $ownerIds = array_column($owners, 'id');
                if (empty($ownerIds)) {
                    // Re-fetch user IDs since $owners may not be populated yet in POST
                    $idRows = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code != 'developer' AND u.is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    $ownerIds = $idRows;
                }
            if (!empty($ownerIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
                $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id IN ($placeholders)")->execute($ownerIds);
            }
            
            // Insert new assignments
            $insertStmt = $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id, assigned_at) VALUES (?, ?, NOW())");
            $totalAssigned = 0;
            foreach ($allAccess as $ownerId => $bizIds) {
                foreach ($bizIds as $bizId) {
                    $insertStmt->execute([(int)$ownerId, (int)$bizId]);
                    $totalAssigned++;
                }
            }
            
            $pdo->commit();
            
            $auth->logAction('bulk_update_owner_access', 'user_business_assignment', null, null, [
                'owners' => count($allAccess),
                'total_assignments' => $totalAssigned
            ]);
            
            $_SESSION['success_message'] = "Semua akses monitoring berhasil diperbarui! ({$totalAssigned} assignment untuk " . count($allAccess) . " user)";
            header("Location: owner-access.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
    
    // Save footer menus for all users
    if ($formAction === 'save_all_footer') {
        $allFooter = $_POST['footer'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
            // Clear existing footer configs for all non-developer users
            $ownerIds = array_column($owners, 'id');
            if (empty($ownerIds)) {
                $idRows = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code != 'developer' AND u.is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                $ownerIds = $idRows;
            }
            if (!empty($ownerIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
                $pdo->prepare("DELETE FROM owner_footer_config WHERE user_id IN ($placeholders)")->execute($ownerIds);
            }
            
            // Insert new footer configs
            $insertStmt = $pdo->prepare("INSERT INTO owner_footer_config (user_id, menu_key, menu_order, is_enabled) VALUES (?, ?, ?, 1)");
            $totalMenus = 0;
            foreach ($allFooter as $userId => $menuKeys) {
                $order = 0;
                foreach ($menuKeys as $key) {
                    $insertStmt->execute([(int)$userId, $key, $order++]);
                    $totalMenus++;
                }
            }
            
            $pdo->commit();
            
            $auth->logAction('bulk_update_footer_menus', 'owner_footer_config', null, null, [
                'users' => count($allFooter),
                'total_menus' => $totalMenus
            ]);
            
            $_SESSION['success_message'] = "Footer menu berhasil diperbarui! ({$totalMenus} menu untuk " . count($allFooter) . " user)";
            header("Location: owner-access.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan footer: ' . $e->getMessage();
        }
    }
}

// Refresh assignments after save
$assignments = [];
try {
    $stmt = $pdo->query("SELECT user_id, business_id FROM user_business_assignment");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['user_id']][] = (int)$row['business_id'];
    }
} catch (Exception $e) {}

// Load footer configs for all users
$footerConfigs = [];
try {
    $stmt = $pdo->query("SELECT user_id, menu_key FROM owner_footer_config WHERE is_enabled = 1 ORDER BY menu_order, id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $footerConfigs[$row['user_id']][] = $row['menu_key'];
    }
} catch (Exception $e) {}
$defaultFooter = ['home', 'frontdesk', 'projects', 'logout'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Akses Owner — tabel ringkas: baris = user, kolom = bisnis / menu footer */
    .oa-tabs { display: inline-flex; background: var(--dev-border); border-radius: 9px; padding: 3px; margin-bottom: 12px; }
    .oa-tabs button { border: 0; background: none; padding: 5px 14px; border-radius: 7px; font-size: 12px; font-weight: 600; color: var(--dev-muted); }
    .oa-tabs button.on { background: #fff; color: var(--dev-primary); box-shadow: 0 1px 2px rgba(15, 23, 42, .08); }
    .oa-card { background: #fff; border: 1px solid var(--dev-border); border-radius: 10px; overflow: hidden; }
    .oa-hint { font-size: 11.5px; color: var(--dev-muted); padding: 9px 14px; border-bottom: 1px solid var(--dev-border); }
    .oa-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .oa-table th, .oa-table td { padding: 6px 8px; border-bottom: 1px solid #f0f1f5; vertical-align: middle; }
    .oa-table thead th { background: var(--dev-light); font-size: 10.5px; font-weight: 600; color: var(--dev-muted); text-align: center; line-height: 1.25; position: sticky; top: 0; z-index: 1; }
    .oa-table thead th:first-child { text-align: left; }
    .oa-table thead th .t { display: block; font-weight: 400; font-size: 9.5px; text-transform: capitalize; opacity: .8; }
    .oa-table tbody tr:hover td { background: #fafaff; }
    .oa-user { display: flex; align-items: center; gap: 8px; min-width: 170px; }
    .oa-user b { font-size: 12px; display: block; line-height: 1.2; }
    .oa-user small { font-size: 10.5px; color: var(--dev-muted); }
    .oa-av { width: 26px; height: 26px; border-radius: 7px; background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary)); color: #fff; font-size: 10px; font-weight: 700; display: grid; place-items: center; flex-shrink: 0; }
    .oa-role { font-size: 9.5px; padding: 1px 6px; border-radius: 99px; background: #eef0f5; color: #475569; margin-left: 4px; }
    .oa-role.owner { background: #fef3c7; color: #92400e; }
    .oa-cell { text-align: center; }
    .oa-cell input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--dev-primary); }
    .oa-cell input.always { opacity: .45; cursor: not-allowed; }
    .oa-count { font-size: 11px; font-weight: 600; white-space: nowrap; text-align: center; }
    .oa-count.zero { color: var(--dev-danger); }
    .oa-row-actions { white-space: nowrap; text-align: right; }
    .oa-row-actions button { border: 0; background: none; font-size: 11px; color: var(--dev-primary); padding: 0 4px; }
    .oa-row-actions button + button { color: var(--dev-muted); }
    .oa-savebar { position: sticky; bottom: 0; display: none; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 14px; background: #fffbeb; border-top: 1px solid #fcd34d; font-size: 12px; }
    .oa-savebar.show { display: flex; }
</style>

<div class="container-fluid py-4">
    <div class="page-head">
        <p>Centang bisnis yang boleh dipantau tiap user di Owner Dashboard, dan menu footer HP-nya.</p>
        <div class="actions">
            <span class="badge bg-light text-dark border"><i class="bi bi-people me-1"></i><?php echo count($owners); ?> user</span>
            <span class="badge bg-light text-dark border"><i class="bi bi-building me-1"></i><?php echo count($businesses); ?> bisnis</span>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (empty($owners)): ?>
        <div class="oa-card" style="padding:24px;text-align:center;">
            <p class="text-muted mb-2">Belum ada user.</p>
            <a href="index.php?section=user-setup" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Buat User</a>
        </div>
    <?php else: ?>

    <div class="oa-tabs">
        <button type="button" class="on" id="tab-access" onclick="oaTab('access')"><i class="bi bi-building me-1"></i>Akses Bisnis</button>
        <button type="button" id="tab-footer" onclick="oaTab('footer')"><i class="bi bi-phone me-1"></i>Footer Menu HP</button>
    </div>

    <!-- Akses bisnis -->
    <form method="POST" action="" id="accessForm" class="oa-card" data-oa>
        <input type="hidden" name="form_action" value="save_all_access">
        <div class="oa-hint">Centang = user bisa memantau bisnis itu. Klik <b>Simpan</b> setelah selesai mengubah.</div>
        <div class="table-responsive">
            <table class="oa-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <?php foreach ($businesses as $biz): ?>
                            <th><?php echo htmlspecialchars($biz['business_name']); ?><span class="t"><?php echo str_replace('_', ' ', $biz['business_type'] ?? 'other'); ?></span></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $owner):
                        $ownerAssignments = $assignments[$owner['id']] ?? [];
                        $isOwnerRole = $owner['role_code'] === 'owner';
                    ?>
                        <tr data-row>
                            <td>
                                <div class="oa-user">
                                    <div class="oa-av"><?php echo htmlspecialchars(strtoupper(substr($owner['full_name'], 0, 2))); ?></div>
                                    <div>
                                        <b><?php echo htmlspecialchars($owner['full_name']); ?><span class="oa-role <?php echo $isOwnerRole ? 'owner' : ''; ?>"><?php echo htmlspecialchars($owner['role_name']); ?></span></b>
                                        <small>@<?php echo htmlspecialchars($owner['username']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <?php foreach ($businesses as $biz): ?>
                                <td class="oa-cell">
                                    <input type="checkbox" name="access[<?php echo (int) $owner['id']; ?>][]" value="<?php echo (int) $biz['id']; ?>"
                                        title="<?php echo htmlspecialchars($owner['full_name'] . ' → ' . $biz['business_name']); ?>"
                                        <?php echo in_array((int) $biz['id'], $ownerAssignments, true) ? 'checked' : ''; ?>>
                                </td>
                            <?php endforeach; ?>
                            <td class="oa-count"></td>
                            <td class="oa-row-actions">
                                <button type="button" onclick="oaRow(this, true)">Semua</button><button type="button" onclick="oaRow(this, false)">Kosongkan</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="oa-savebar">
            <span><i class="bi bi-exclamation-circle me-1"></i>Ada perubahan yang belum disimpan.</span>
            <span><a href="owner-access.php" class="btn btn-outline-secondary btn-sm">Batal</a> <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Simpan Akses</button></span>
        </div>
    </form>

    <!-- Footer menu HP -->
    <form method="POST" action="" id="footerForm" class="oa-card" data-oa style="display:none;">
        <input type="hidden" name="form_action" value="save_all_footer">
        <div class="oa-hint">Menu yang tampil di footer Owner Dashboard (HP) tiap user. Menu abu-abu selalu tampil.</div>
        <div class="table-responsive">
            <table class="oa-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <?php foreach ($footerMenuDefs as $key => $menu): ?>
                            <th><i class="bi <?php echo $menu['icon']; ?>"></i><span class="t"><?php echo htmlspecialchars($menu['label']); ?></span></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $owner):
                        $userFooter = $footerConfigs[$owner['id']] ?? $defaultFooter;
                    ?>
                        <tr data-row>
                            <td>
                                <div class="oa-user">
                                    <div class="oa-av"><?php echo htmlspecialchars(strtoupper(substr($owner['full_name'], 0, 2))); ?></div>
                                    <div>
                                        <b><?php echo htmlspecialchars($owner['full_name']); ?></b>
                                        <small>@<?php echo htmlspecialchars($owner['username']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <?php foreach ($footerMenuDefs as $key => $menu):
                                $isAlways = isset($menu['always']);
                            ?>
                                <td class="oa-cell">
                                    <input type="checkbox" name="footer[<?php echo (int) $owner['id']; ?>][]" value="<?php echo htmlspecialchars($key); ?>"
                                        title="<?php echo htmlspecialchars($menu['label'] . ' — ' . $menu['desc']); ?>"
                                        <?php echo ($isAlways || in_array($key, $userFooter, true)) ? 'checked' : ''; ?>
                                        <?php echo $isAlways ? 'class="always" onclick="return false;"' : ''; ?>>
                                </td>
                            <?php endforeach; ?>
                            <td class="oa-count"></td>
                            <td class="oa-row-actions">
                                <button type="button" onclick="oaRow(this, true)">Semua</button><button type="button" onclick="oaRow(this, false)">Kosongkan</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="oa-savebar">
            <span><i class="bi bi-exclamation-circle me-1"></i>Ada perubahan yang belum disimpan.</span>
            <span><a href="owner-access.php" class="btn btn-outline-secondary btn-sm">Batal</a> <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Simpan Footer</button></span>
        </div>
    </form>

    <?php endif; ?>
</div>

<script>
let oaDirty = false;

function oaTab(tab) {
    document.getElementById('tab-access').classList.toggle('on', tab === 'access');
    document.getElementById('tab-footer').classList.toggle('on', tab === 'footer');
    document.getElementById('accessForm').style.display = tab === 'access' ? '' : 'none';
    document.getElementById('footerForm').style.display = tab === 'footer' ? '' : 'none';
}

// Hitung jumlah centang per baris (menu "selalu tampil" tidak dihitung sebagai pilihan)
function oaCount(row) {
    const boxes = row.querySelectorAll('.oa-cell input:not(.always)');
    const checked = row.querySelectorAll('.oa-cell input:not(.always):checked').length;
    const cell = row.querySelector('.oa-count');
    cell.textContent = checked + '/' + boxes.length;
    cell.classList.toggle('zero', checked === 0);
}

function oaChanged(form) {
    oaDirty = true;
    form.querySelector('.oa-savebar').classList.add('show');
}

function oaRow(btn, checked) {
    const row = btn.closest('tr');
    row.querySelectorAll('.oa-cell input:not(.always)').forEach(cb => { cb.checked = checked; });
    oaCount(row);
    oaChanged(btn.closest('form'));
}

document.querySelectorAll('[data-oa]').forEach(form => {
    form.querySelectorAll('tr[data-row]').forEach(oaCount);
    form.addEventListener('change', e => {
        if (e.target.matches('.oa-cell input')) {
            oaCount(e.target.closest('tr'));
            oaChanged(form);
        }
    });
    form.addEventListener('submit', () => { oaDirty = false; });
});

window.addEventListener('beforeunload', e => {
    if (oaDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

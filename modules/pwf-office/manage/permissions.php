<?php
/**
 * PWF Management - User Menu Permissions
 * Manage akses menu per user
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$msg = '';
$msgType = 'success';

// Get PWF business ID
$pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
$pwfBizId = $pwfBiz['id'] ?? null;

// Get all PWF users
$stmt = $masterPdo->prepare("SELECT u.id, u.username, u.full_name, u.email FROM users u INNER JOIN user_business_assignment uba ON u.id = uba.user_id WHERE uba.business_id = ? ORDER BY u.username");
$stmt->execute([$pwfBizId]);
$pwfUsers = $stmt->fetchAll();

// Get all PWF menus
$stmt = $masterPdo->prepare("SELECT id, menu_name, menu_label FROM menu_items WHERE business_id = ? OR business_id IS NULL ORDER BY menu_order, menu_name");
$stmt->execute([$pwfBizId]);
$menus = $stmt->fetchAll();

// Get selected user's permissions
$selectedUserId = (int)($_GET['user'] ?? 0);
$userPermissions = [];

if ($selectedUserId > 0) {
    $stmt = $masterPdo->prepare("
        SELECT menu_id, can_view, can_create, can_edit, can_delete 
        FROM user_menu_permissions 
        WHERE user_id = ? AND business_id = ?
    ");
    $stmt->execute([$selectedUserId, $pwfBizId]);
    foreach ($stmt->fetchAll() as $perm) {
        $userPermissions[$perm['menu_id']] = $perm;
    }
}

// Handle permission save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    
    // Delete existing permissions
    $masterPdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$userId, $pwfBizId]);
    
    // Insert new permissions
    $stmt = $masterPdo->prepare("
        INSERT INTO user_menu_permissions (user_id, business_id, menu_id, can_view, can_create, can_edit, can_delete, granted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insertCount = 0;
    foreach ($menus as $menu) {
        $canView = isset($_POST["menu_{$menu['id']}_view"]) ? 1 : 0;
        $canCreate = isset($_POST["menu_{$menu['id']}_create"]) ? 1 : 0;
        $canEdit = isset($_POST["menu_{$menu['id']}_edit"]) ? 1 : 0;
        $canDelete = isset($_POST["menu_{$menu['id']}_delete"]) ? 1 : 0;
        
        // Only insert if user has at least view permission
        if ($canView || $canCreate || $canEdit || $canDelete) {
            if ($stmt->execute([$userId, $pwfBizId, $menu['id'], $canView, $canCreate, $canEdit, $canDelete, $_SESSION['user_id'] ?? null])) {
                $insertCount++;
            }
        }
    }
    
    $msg = "Akses menu berhasil disimpan untuk " . $insertCount . " menu!";
    $msgType = 'success';
    
    // Reload selected user permissions
    $userPermissions = [];
    $stmt = $masterPdo->prepare("
        SELECT menu_id, can_view, can_create, can_edit, can_delete 
        FROM user_menu_permissions 
        WHERE user_id = ? AND business_id = ?
    ");
    $stmt->execute([$userId, $pwfBizId]);
    foreach ($stmt->fetchAll() as $perm) {
        $userPermissions[$perm['menu_id']] = $perm;
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manajemen Akses - PWF Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: linear-gradient(135deg, #f5f3f0 0%, #faf9f7 100%); color: #1c1511; }
        .navbar { background: white; border-bottom: 1px solid #E7E5E4; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,.04); position: sticky; top: 0; z-index: 100; }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700; color: #1c1511; text-decoration: none; }
        .navbar-brand i { color: #B8860B; font-size: 20px; }
        .navbar-right { display: flex; align-items: center; gap: 16px; }
        .nav-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all .2s; display: flex; align-items: center; gap: 6px; background: #F5F3F0; color: #1c1511; }
        .nav-btn:hover { background: #EAE8E5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 24px; }
        .header { margin-bottom: 32px; }
        .header h1 { font-size: 28px; font-weight: 800; display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .header h1 i { color: #B8860B; font-size: 32px; }
        .header p { color: #999; font-size: 14px; }
        .alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 13px; }
        .alert.success { background: #F0FDF4; color: #166534; border: 1px solid #DCFCE7; }
        .alert.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
        .user-selector { margin-bottom: 32px; background: white; padding: 20px; border-radius: 10px; border: 1px solid #E7E5E4; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .user-selector label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 8px; color: #666; text-transform: uppercase; }
        .user-selector select { width: 100%; max-width: 400px; padding: 12px 14px; border: 1px solid #E7E5E4; border-radius: 8px; font-size: 14px; font-family: inherit; }
        .user-selector select:focus { outline: none; border-color: #B8860B; box-shadow: 0 0 0 3px rgba(184, 134, 11, .1); }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; background: white; border-radius: 10px; border: 1px solid #E7E5E4; }
        .permissions-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,.06); border: 1px solid #E7E5E4; margin-bottom: 24px; }
        .permissions-table th { background: linear-gradient(135deg, #F5F3F0 0%, #F9F7F4 100%); padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: .6px; border-bottom: 1px solid #E7E5E4; }
        .permissions-table td { padding: 14px 16px; border-bottom: 1px solid #E7E5E4; font-size: 13px; }
        .permissions-table tr:last-child td { border-bottom: none; }
        .menu-name { font-weight: 700; color: #1c1511; }
        .checkbox-wrapper { display: flex; align-items: center; gap: 6px; }
        .checkbox-wrapper input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #B8860B; }
        .form-actions { display: flex; gap: 12px; }
        .btn-save { padding: 12px 22px; background: linear-gradient(135deg, #B8860B 0%, #D4A017 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all .2s; box-shadow: 0 2px 6px rgba(184, 134, 11, .2); }
        .btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(184, 134, 11, .3); }
        .btn-reset { padding: 12px 22px; background: #F5F3F0; color: #1c1511; border: 1px solid #E7E5E4; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all .2s; }
        .btn-reset:hover { background: #EAE8E5; }
    </style>
    <div class="navbar">
        <div class="navbar-left">
            <a href="index.php" class="navbar-brand">
                <i class="bi bi-gear-fill"></i>
                PWF Management
            </a>
        </div>
        <div class="navbar-right">
            <button class="nav-btn" onclick="window.location.href='index.php'" title="Back to Menu">
                <i class="bi bi-arrow-left"></i> Kembali
            </button>
            <button class="nav-btn" onclick="window.location.href='../dashboard.php'" title="Back to Dashboard">
                <i class="bi bi-house"></i> Dashboard
            </button>
        </div>
    </div>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-shield-lock"></i>Manajemen Akses Menu</h1>
            <p>Atur hak akses menu PWF untuk setiap user</p>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>">
                ✓ <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <div class="user-selector">
            <label>📋 Pilih User untuk Manage Akses</label>
            <select onchange="location.href='?user=' + this.value" id="userSelect">
                <option value="">-- Pilih User --</option>
                <?php foreach ($pwfUsers as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= $selectedUserId === (int)$user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['username']) ?> - <?= htmlspecialchars($user['full_name'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($selectedUserId > 0 && !empty($menus)): ?>
            <form method="post">
                <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
                
                <table class="permissions-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Menu</th>
                            <th style="width: 15%; text-align:center;">👁️ Lihat</th>
                            <th style="width: 15%; text-align:center;">➕ Buat</th>
                            <th style="width: 15%; text-align:center;">✏️ Edit</th>
                            <th style="width: 15%; text-align:center;">🗑️ Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menus as $menu): ?>
                            <?php 
                            $perm = $userPermissions[$menu['id']] ?? null;
                            $canView = $perm['can_view'] ?? 0;
                            $canCreate = $perm['can_create'] ?? 0;
                            $canEdit = $perm['can_edit'] ?? 0;
                            $canDelete = $perm['can_delete'] ?? 0;
                            ?>
                            <tr>
                                <td class="menu-name"><?= htmlspecialchars($menu['menu_label'] ?? $menu['menu_name']) ?></td>
                                <td style="text-align:center;">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="menu_<?= $menu['id'] ?>_view" 
                                               name="menu_<?= $menu['id'] ?>_view" 
                                               value="1" 
                                               <?= $canView ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="menu_<?= $menu['id'] ?>_create" 
                                               name="menu_<?= $menu['id'] ?>_create" 
                                               value="1" 
                                               <?= $canCreate ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="menu_<?= $menu['id'] ?>_edit" 
                                               name="menu_<?= $menu['id'] ?>_edit" 
                                               value="1" 
                                               <?= $canEdit ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" 
                                               id="menu_<?= $menu['id'] ?>_delete" 
                                               name="menu_<?= $menu['id'] ?>_delete" 
                                               value="1" 
                                               <?= $canDelete ? 'checked' : '' ?>>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="form-actions">
                    <button type="submit" class="btn-save">💾 Simpan Akses</button>
                    <button type="reset" class="btn-reset">↻ Reset</button>
                </div>
            </form>
        <?php elseif ($selectedUserId > 0): ?>
            <div class="empty-state">
                <p>📭 Tidak ada menu ditemukan</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>👤 Pilih user di atas untuk manage akses menu</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

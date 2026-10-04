<?php
/**
 * Developer Panel - Main Dashboard & User Setup
 * Integrated management interface with sidebar navigation
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once __DIR__ . '/includes/password-crypto.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
ensurePasswordViewColumn($pdo);

// =============================================
// FUNCTION: Sync Password to Business Databases
// =============================================
function syncPasswordToBusinesses($username, $hashedPassword, $mainPdo) {
    try {
        // Get all businesses
        $stmt = $mainPdo->prepare("SELECT database_name FROM businesses WHERE is_active = 1");
        $stmt->execute();
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($businesses as $biz) {
            $dbName = $biz['database_name'];
            try {
                // Try to update user in business database
                $bizPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                $updateStmt = $bizPdo->prepare("UPDATE users SET password=? WHERE username=?");
                $updateStmt->execute([$hashedPassword, $username]);
                
            } catch (Exception $e) {
                // Log sync error but don't fail
                error_log("Password sync failed for DB: $dbName - " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Password sync to businesses failed: " . $e->getMessage());
    }
}

// Determine which section to display
$section = $_GET['section'] ?? 'dashboard';
$pageTitle = 'Dashboard';

// =============================================
// SECTION: USER SETUP (3-step wizard)
// =============================================
if ($section === 'user-setup') {
    $pageTitle = 'User & Akses Bisnis';
    
    $activeStep = $_GET['step'] ?? 'users';
    $selectedUserId = $_GET['user_id'] ?? null;
    
    // Initialize variables
    $editUser = null;
    $users = [];
    $roles = [];
    $allBusinesses = [];
    $assignedBusinesses = [];
    $userBusinesses = [];
    $menus = [];
    
    // =============================================
    // STEP 1: USER LOGIN MANAGEMENT
    // =============================================
    if ($activeStep === 'users') {
        // Handle create/edit user
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'save_user') {
                try {
                    $userId = $_POST['user_id'] ?? null;
                    $username = trim($_POST['username']) ?: null;
                    $password = trim($_POST['password']) ?: null;
                    $fullName = trim($_POST['full_name']) ?: null;
                    $email = trim($_POST['email']) ?: null;
                    $roleId = $_POST['role_id'] ?? null;
                    
                    if (!$username || !$fullName || !$email || !$roleId) {
                        throw new Exception('Username, name, email, dan role harus diisi!');
                    }
                    
                    if ($userId) {
                        // Update existing user
                        if ($password) {
                            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                            $viewablePassword = encryptDevPassword($password);
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, password_view=?, full_name=?, role_id=? WHERE id=?");
                            $stmt->execute([$username, $email, $hashedPassword, $viewablePassword, $fullName, $roleId, $userId]);
                            
                            // Sync password to all business databases
                            syncPasswordToBusinesses($username, $hashedPassword, $pdo);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, full_name=?, role_id=? WHERE id=?");
                            $stmt->execute([$username, $email, $fullName, $roleId, $userId]);
                        }
                        $_SESSION['success_message'] = '✅ User updated and synced to all businesses!';
                    } else {
                        // Create new user
                        if (!$password) throw new Exception('Password harus diisi untuk user baru!');
                        
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $viewablePassword = encryptDevPassword($password);
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, password_view, full_name, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $hashedPassword, $viewablePassword, $fullName, '0000000000', $roleId, 1]);
                        
                        // Sync password to all business databases
                        syncPasswordToBusinesses($username, $hashedPassword, $pdo);
                        
                        $auth->logAction('create_user', 'users', $pdo->lastInsertId());
                        $_SESSION['success_message'] = '✅ User created and synced to all businesses!';
                    }
                    
                    $selectedUserId = null;
                } catch (Exception $e) {
                    $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
                }
            } elseif ($action === 'delete_user') {
                try {
                    $deleteUserId = (int) ($_POST['user_id'] ?? 0);
                    if ($deleteUserId <= 0 || $deleteUserId === (int) $user['id']) {
                        throw new Exception('Tidak bisa menghapus akun yang sedang dipakai login.');
                    }
                    
                    // Disable FK checks
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                    
                    // Reassign any businesses owned by this user to current user
                    $stmt = $pdo->prepare("SELECT id FROM businesses WHERE owner_id = ?");
                    $stmt->execute([$deleteUserId]);
                    $ownedBusinesses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if ($ownedBusinesses) {
                        $stmt = $pdo->prepare("UPDATE businesses SET owner_id = ? WHERE owner_id = ?");
                        $stmt->execute([$user['id'], $deleteUserId]);
                    }
                    
                    // Delete references
                    // Hapus semua relasi user (tabel yang belum ada di-skip)
                    foreach (['user_menu_permissions', 'user_business_assignment', 'user_preferences'] as $relTable) {
                        try {
                            $pdo->prepare("DELETE FROM {$relTable} WHERE user_id = ?")->execute([$deleteUserId]);
                        } catch (Exception $e) {
                        }
                    }
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deleteUserId]);
                    
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    
                    $auth->logAction('delete_user', 'users', $deleteUserId);
                    $_SESSION['success_message'] = 'User berhasil dihapus.';
                    $selectedUserId = null;
                } catch (Exception $e) {
                    $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
                }
            }
        }
        
        // Get all users
        $users = $pdo->query("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get selected user for edit
        $editUser = null;
        if ($selectedUserId) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$selectedUserId]);
            $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Get all roles
        $roles = $pdo->query("SELECT * FROM roles WHERE is_system_role = 1 ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
    }
    // =============================================
    // STEP 2: BUSINESS ASSIGNMENT
    // =============================================
    elseif ($activeStep === 'business') {
        if (!$selectedUserId) {
            $_SESSION['error_message'] = '❌ Pilih user dulu!';
            $_GET['step'] = 'users';
            $activeStep = 'users';
        } else {
            // Handle business assignment
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
                try {
                    $businessId = $_POST['business_id'];
                    $action = $_POST['action'];
                    
                    if ($action === 'assign') {
                        // Assign business to user
                        $stmt = $pdo->prepare("INSERT IGNORE INTO user_business_assignment (user_id, business_id) VALUES (?, ?)");
                        $stmt->execute([$selectedUserId, $businessId]);
                        $auth->logAction('assign_business', 'user_business_assignment', $selectedUserId);
                        $_SESSION['success_message'] = '✅ Business assigned!';
                    } else if ($action === 'remove') {
                        // Remove business from user
                        $stmt = $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ? AND business_id = ?");
                        $stmt->execute([$selectedUserId, $businessId]);
                        $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$selectedUserId, $businessId]);
                        $_SESSION['success_message'] = '✅ Business removed!';
                    }
                } catch (Exception $e) {
                    $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
                }
            }
            
            // Get user info
            $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmt->execute([$selectedUserId]);
            $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get all businesses
            $allBusinesses = $pdo->query("SELECT id, business_name FROM businesses ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
            
            // Get assigned businesses
            $stmt = $pdo->prepare("SELECT business_id FROM user_business_assignment WHERE user_id = ?");
            $stmt->execute([$selectedUserId]);
            $assignedBusinesses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    // =============================================
    // STEP 3: PERMISSION SETUP
    // =============================================
    elseif ($activeStep === 'permissions') {
        if (!$selectedUserId) {
            $_SESSION['error_message'] = '❌ Pilih user dulu!';
            $_GET['step'] = 'users';
            $activeStep = 'users';
        } else {
            // Handle permission updates
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permission') {
                try {
                    $businessId = $_POST['business_id'] ?? null;
                    $permission = $_POST['permission'] ?? null;
                    
                    if (!$businessId || !$permission) {
                        throw new Exception('Business dan permission harus dipilih!');
                    }
                    
                    // Set permission values based on level
                    $permissions = ['can_view' => 1, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
                    if ($permission === 'view') {
                        $permissions = ['can_view' => 1, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
                    } elseif ($permission === 'create') {
                        $permissions = ['can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 0];
                    } elseif ($permission === 'all') {
                        $permissions = ['can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1];
                    }
                    
                    // Get all active menus enabled for this business from database
                    $menuStmt = $pdo->prepare("
                        SELECT m.menu_code FROM menu_items m
                        JOIN business_menu_config bmc ON m.id = bmc.menu_id
                        WHERE bmc.business_id = ? AND bmc.is_enabled = 1 AND m.is_active = 1
                    ");
                    $menuStmt->execute([$businessId]);
                    $menus = $menuStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (empty($menus)) {
                        // Fallback: get all active menus
                        $menus = $pdo->query("SELECT menu_code FROM menu_items WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    }
                    
                    // Delete existing permissions for this user+business, then insert fresh
                    $delStmt = $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
                    $delStmt->execute([$selectedUserId, $businessId]);
                    
                    $stmt = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    foreach ($menus as $menu) {
                        $stmt->execute([$selectedUserId, $businessId, $menu, 
                                       $permissions['can_view'], $permissions['can_create'], $permissions['can_edit'], $permissions['can_delete']]);
                    }
                    
                    $_SESSION['success_message'] = '✅ Permission updated untuk semua menus!';
                } catch (Exception $e) {
                    $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
                }
            }
            
            // Get user info
            $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmt->execute([$selectedUserId]);
            $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get assigned businesses
            $stmt = $pdo->prepare("SELECT id, business_name FROM businesses WHERE id IN (SELECT business_id FROM user_business_assignment WHERE user_id = ?)");
            $stmt->execute([$selectedUserId]);
            $userBusinesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Menu list - load from database
            $menuRows = $pdo->query("SELECT menu_code, menu_name FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
            $menus = [];
            foreach ($menuRows as $mr) {
                $menus[$mr['menu_code']] = $mr['menu_name'];
            }
            if (empty($menus)) {
                // Fallback if menu_items table is empty
                $menus = ['dashboard' => 'Dashboard', 'cashbook' => 'Buku Kas Besar', 'divisions' => 'Kelola Divisi', 
                    'frontdesk' => 'Frontdesk', 'sales_invoice' => 'Sales Invoice', 'procurement' => 'PO & SHOOP',
                    'bills' => 'Tagihan', 'reports' => 'Reports', 'investor' => 'Investor', 'project' => 'Project',
                    'cqc-projects' => 'CQC Projects', 'database' => 'Database Master', 'finance' => 'Manajemen Keuangan',
                    'settings' => 'Pengaturan', 'payroll' => 'Payroll', 'owner' => 'Owner Monitoring'];
            }
        }
    }
} else {
    // SECTION: DASHBOARD (default) — ringkasan yang perlu dipantau developer
    require_once __DIR__ . '/includes/adfstore_bridge.php';

    $stats = ['users' => 0, 'users_active' => 0, 'online' => 0, 'businesses' => 0, 'active_businesses' => 0];
    try {
        $stats['users'] = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['users_active'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
        $stats['businesses'] = (int) $pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
        $stats['active_businesses'] = (int) $pdo->query("SELECT COUNT(*) FROM businesses WHERE is_active = 1")->fetchColumn();
        $stats['online'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND last_login >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
    } catch (Exception $e) {
        // Tables might not exist yet
    }

    // Bisnis + jumlah user + status langganan ADF Store
    $businesses = [];
    try {
        $businesses = $pdo->query("
            SELECT b.*, u.full_name AS owner_name,
                   (SELECT COUNT(*) FROM user_business_assignment uba WHERE uba.business_id = b.id) AS user_count
            FROM businesses b
            LEFT JOIN users u ON b.owner_id = u.id
            ORDER BY b.is_active DESC, b.business_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }

    $sub = ['active' => 0, 'locked' => 0, 'unpaid' => 0.0, 'unpaid_count' => 0];
    $attention = []; // [ikon, warna, teks, link]
    foreach ($businesses as &$biz) {
        $biz['sub'] = $biz['is_active'] ? adfstore_status($biz) : ['code' => 'inactive', 'label' => 'Nonaktif'];
        $code = $biz['sub']['code'];
        $name = htmlspecialchars($biz['business_name']);
        if ($code === 'active' || $code === 'locked') {
            $sub[$code]++;
            $sub['unpaid'] += $biz['sub']['unpaid'];
            $sub['unpaid_count'] += $biz['sub']['unpaid_count'];
            if ($code === 'locked') {
                $attention[] = ['lock-fill', 'danger', "<b>$name</b> sedang dikunci", 'https://adfsystem.store/admin/subscription-clients.php'];
            }
            if ($biz['sub']['unpaid_count'] > 0) {
                $attention[] = ['receipt', 'warning', "<b>$name</b> punya " . $biz['sub']['unpaid_count'] . ' tagihan belum dibayar (Rp ' . number_format($biz['sub']['unpaid'], 0, ',', '.') . ')', 'businesses.php'];
            }
            if (!empty($biz['sub']['sync_error'])) {
                $attention[] = ['exclamation-triangle', 'danger', "<b>$name</b> gagal sinkron ke ADF Store", 'businesses.php'];
            }
        } elseif ($code === 'token_mismatch' || $code === 'not_in_store' || $code === 'not_connected') {
            $attention[] = ['link-45deg', 'warning', "<b>$name</b> " . strtolower($biz['sub']['label']) . ' — klik Hubungkan di halaman Bisnis', 'businesses.php'];
        } elseif ($code === 'no_db' && $biz['is_active']) {
            $attention[] = ['database-exclamation', 'danger', "<b>$name</b>: database tidak bisa dibuka", 'database.php'];
        }
        if (!$biz['is_active']) {
            $attention[] = ['gear', 'secondary', "<b>$name</b> nonaktif / belum selesai setup", 'businesses.php?action=setup&id=' . $biz['id'] . '&step=2'];
        } elseif ((int) $biz['user_count'] === 0) {
            $attention[] = ['person-x', 'secondary', "<b>$name</b> belum punya user", 'permissions.php?business_id=' . $biz['id']];
        }
    }
    unset($biz);

    // User aktif yang belum diberi akses ke bisnis mana pun (tidak bisa login ke bisnis)
    try {
        $orphans = $pdo->query("
            SELECT u.full_name FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.is_active = 1 AND COALESCE(r.role_code, '') <> 'developer'
              AND NOT EXISTS (SELECT 1 FROM user_business_assignment uba WHERE uba.user_id = u.id)
            ORDER BY u.full_name
        ")->fetchAll(PDO::FETCH_COLUMN);
        if ($orphans) {
            $attention[] = ['person-exclamation', 'secondary', count($orphans) . ' user belum punya akses bisnis: ' . htmlspecialchars(implode(', ', array_slice($orphans, 0, 4))) . (count($orphans) > 4 ? ', …' : ''), 'index.php?section=user-setup'];
        }
    } catch (Exception $e) {
    }

    // Aktivitas terakhir
    $auditLogs = [];
    try {
        $auditLogs = $pdo->query("
            SELECT al.*, u.full_name, u.username
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist yet
    }
}

// Include header with sidebar
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($section === 'dashboard'):
        $ago = static function ($ts) {
            $d = time() - strtotime($ts);
            if ($d < 60) return 'baru saja';
            if ($d < 3600) return floor($d / 60) . ' mnt lalu';
            if ($d < 86400) return floor($d / 3600) . ' jam lalu';
            return date('d M H:i', strtotime($ts));
        };
        $subBadge = ['active' => 'success', 'locked' => 'danger', 'token_mismatch' => 'warning', 'not_in_store' => 'warning', 'not_connected' => 'light', 'no_db' => 'light', 'no_store' => 'light', 'inactive' => 'light'];
    ?>
    <!-- ============== DASHBOARD SECTION ============== -->
    <style>
        .dx-kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .dx-kpi { background: #fff; border: 1px solid var(--dev-border); border-radius: 10px; padding: 10px 12px; text-decoration: none; color: inherit; display: block; }
        .dx-kpi:hover { border-color: var(--dev-primary); color: inherit; }
        .dx-kpi small { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--dev-muted); }
        .dx-kpi b { display: block; font-size: 18px; line-height: 1.3; margin-top: 2px; }
        .dx-kpi span { font-size: 11px; color: var(--dev-muted); }
        .dx-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 14px; align-items: start; }
        .dx-card { background: #fff; border: 1px solid var(--dev-border); border-radius: 10px; overflow: hidden; margin-bottom: 14px; }
        .dx-card-h { display: flex; align-items: center; justify-content: space-between; padding: 9px 14px; border-bottom: 1px solid var(--dev-border); }
        .dx-card-h h6 { margin: 0; font-size: 12.5px; font-weight: 600; }
        .dx-card-h a { font-size: 11.5px; text-decoration: none; }
        .dx-table td, .dx-table th { padding: 7px 12px; font-size: 12px; }
        .dx-table td small { display: block; font-size: 10.5px; color: var(--dev-muted); }
        .dx-list { list-style: none; margin: 0; padding: 4px 0; }
        .dx-list li { display: flex; gap: 9px; align-items: flex-start; padding: 7px 14px; font-size: 12px; line-height: 1.4; }
        .dx-list li + li { border-top: 1px solid #f2f3f7; }
        .dx-list li i { font-size: 13px; margin-top: 1px; }
        .dx-list li a { color: inherit; text-decoration: none; flex: 1; }
        .dx-list li a:hover { color: var(--dev-primary); }
        .dx-list .meta { color: var(--dev-muted); font-size: 10.5px; white-space: nowrap; }
        .dx-empty { padding: 16px; text-align: center; font-size: 12px; color: var(--dev-muted); }
        @media (max-width: 1100px) { .dx-grid { grid-template-columns: 1fr; } .dx-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 600px) { .dx-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>

    <div class="page-head">
        <p>Halo, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong> — kondisi semua bisnis ADF System hari ini.</p>
        <div class="actions">
            <a href="index.php?section=user-setup" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-plus me-1"></i>Tambah User</a>
            <a href="businesses.php?action=add" class="btn btn-primary btn-sm"><i class="bi bi-building-add me-1"></i>Tambah Bisnis</a>
        </div>
    </div>

    <!-- Angka utama -->
    <div class="dx-kpis">
        <a class="dx-kpi" href="businesses.php">
            <small><i class="bi bi-building"></i>Bisnis aktif</small>
            <b><?php echo $stats['active_businesses']; ?></b>
            <span>dari <?php echo $stats['businesses']; ?> terdaftar</span>
        </a>
        <a class="dx-kpi" href="index.php?section=user-setup">
            <small><i class="bi bi-people"></i>User aktif</small>
            <b><?php echo $stats['users_active']; ?></b>
            <span><?php echo $stats['online']; ?> online (30 menit)</span>
        </a>
        <a class="dx-kpi" href="businesses.php">
            <small><i class="bi bi-patch-check"></i>Langganan aktif</small>
            <b class="text-success"><?php echo $sub['active']; ?></b>
            <span>terhubung ke ADF Store</span>
        </a>
        <a class="dx-kpi" href="https://adfsystem.store/admin/subscription-clients.php" target="_blank" rel="noopener">
            <small><i class="bi bi-lock"></i>Terkunci</small>
            <b class="<?php echo $sub['locked'] ? 'text-danger' : ''; ?>"><?php echo $sub['locked']; ?></b>
            <span>bisnis dikunci</span>
        </a>
        <a class="dx-kpi" href="https://adfsystem.store/admin/orders.php" target="_blank" rel="noopener">
            <small><i class="bi bi-receipt"></i>Tagihan belum dibayar</small>
            <b class="<?php echo $sub['unpaid_count'] ? 'text-warning' : ''; ?>">Rp <?php echo number_format($sub['unpaid'], 0, ',', '.'); ?></b>
            <span><?php echo $sub['unpaid_count']; ?> tagihan</span>
        </a>
    </div>

    <div class="dx-grid">
        <!-- Bisnis -->
        <div class="dx-card">
            <div class="dx-card-h">
                <h6><i class="bi bi-building me-1"></i>Bisnis</h6>
                <a href="businesses.php">Kelola →</a>
            </div>
            <div class="table-responsive">
                <table class="table dx-table mb-0">
                    <thead>
                        <tr><th>Bisnis</th><th>Tipe</th><th class="text-center">User</th><th>Langganan</th><th class="text-end"></th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$businesses): ?>
                            <tr><td colspan="5" class="dx-empty">Belum ada bisnis. <a href="businesses.php?action=add">Tambah bisnis</a></td></tr>
                        <?php endif; ?>
                        <?php foreach ($businesses as $biz): $s = $biz['sub']; ?>
                            <tr class="<?php echo $biz['is_active'] ? '' : 'text-muted'; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($biz['addon_domain'] ?? '' ?: ($biz['owner_name'] ?? '-')); ?></small>
                                </td>
                                <td><?php echo ucwords(str_replace('_', ' ', $biz['business_type'])); ?></td>
                                <td class="text-center"><?php echo (int) $biz['user_count']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $subBadge[$s['code']] ?? 'light'; ?> <?php echo in_array($subBadge[$s['code']] ?? 'light', ['light', 'warning'], true) ? 'text-dark' : ''; ?> <?php echo ($subBadge[$s['code']] ?? 'light') === 'light' ? 'border' : ''; ?>"><?php echo htmlspecialchars($s['label']); ?></span>
                                    <?php if (!empty($s['unpaid_count'])): ?>
                                        <small class="text-danger">Rp <?php echo number_format($s['unpaid'], 0, ',', '.'); ?> belum dibayar</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="../developer-access.php?dev_access=<?php echo base64_encode($biz['database_name']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Buka sistem bisnis"><i class="bi bi-box-arrow-up-right"></i></a>
                                    <a href="businesses.php?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit bisnis"><i class="bi bi-pencil"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <!-- Perlu perhatian -->
            <div class="dx-card">
                <div class="dx-card-h">
                    <h6><i class="bi bi-bell me-1"></i>Perlu Perhatian</h6>
                    <span class="badge <?php echo $attention ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo count($attention); ?></span>
                </div>
                <?php if (!$attention): ?>
                    <div class="dx-empty"><i class="bi bi-check-circle text-success me-1"></i>Semua aman, tidak ada yang perlu ditindaklanjuti.</div>
                <?php else: ?>
                    <ul class="dx-list">
                        <?php foreach ($attention as [$icon, $color, $text, $link]): ?>
                            <li>
                                <i class="bi bi-<?php echo $icon; ?> text-<?php echo $color; ?>"></i>
                                <a href="<?php echo htmlspecialchars($link); ?>" <?php echo strpos($link, 'http') === 0 ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo $text; ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Aktivitas terakhir -->
            <div class="dx-card">
                <div class="dx-card-h">
                    <h6><i class="bi bi-clock-history me-1"></i>Aktivitas Terakhir</h6>
                    <a href="audit.php">Semua →</a>
                </div>
                <?php if (!$auditLogs): ?>
                    <div class="dx-empty">Belum ada aktivitas.</div>
                <?php else: ?>
                    <ul class="dx-list">
                        <?php foreach ($auditLogs as $log):
                            $act = str_replace('_', ' ', (string) ($log['action_type'] ?? $log['action'] ?? '-'));
                            $tbl = (string) ($log['table_name'] ?? $log['entity_type'] ?? '');
                        ?>
                            <li>
                                <i class="bi bi-dot text-muted"></i>
                                <span style="flex:1;"><b><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'Sistem'); ?></b> · <?php echo htmlspecialchars($act); ?><?php echo $tbl ? ' <span class="text-muted">(' . htmlspecialchars($tbl) . ')</span>' : ''; ?></span>
                                <span class="meta"><?php echo $ago($log['created_at']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php elseif ($section === 'user-setup'): ?>
    <!-- ============== USER SETUP SECTION ============== -->
    
    <div class="row">
        <div class="col-12">
            <div class="content-card us-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-person-gear me-2"></i>Tambah User &amp; Atur Akses</h5>
                    <small class="text-muted">1. buat user → 2. pilih bisnis → 3. atur menu yang boleh dibuka</small>
                </div>
                
                <!-- Alert Messages -->
                <?php if ($messages = ($_SESSION['success_message'] ?? null)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $messages; unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($messages = ($_SESSION['error_message'] ?? null)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $messages; unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Step Navigation -->
                <div class="wizard-steps">
                    <div class="step <?php echo $activeStep === 'users' ? 'active' : ''; ?> <?php echo $activeStep !== 'users' ? 'completed' : ''; ?>">
                        <div class="step-number">1</div>
                        <div class="step-label">Buat User</div>
                    </div>
                    <div class="step-arrow">→</div>
                    <div class="step <?php echo $activeStep === 'business' ? 'active' : ''; ?> <?php echo ($activeStep === 'permissions') ? 'completed' : ''; ?>">
                        <div class="step-number">2</div>
                        <div class="step-label">Pilih Bisnis</div>
                    </div>
                    <div class="step-arrow">→</div>
                    <div class="step <?php echo $activeStep === 'permissions' ? 'active' : ''; ?>">
                        <div class="step-number">3</div>
                        <div class="step-label">Atur Menu</div>
                    </div>
                </div>
                
                <?php if ($activeStep === 'users'): ?>
                <!-- ============== STEP 1: USERS (COMPACT) ============== -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Daftar User</h5>
                    <?php if (!$editUser): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
                        <i class="bi bi-person-plus me-1"></i>Tambah User
                    </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($editUser): ?>
                <!-- Edit Form -->
                <div class="card border-light mb-3">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title mb-0">Edit User: <strong><?php echo htmlspecialchars($editUser['full_name']); ?></strong></h6>
                            <a href="?section=user-setup&step=users" class="btn btn-sm btn-outline-secondary">Tutup</a>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($editUser['username']); ?>" required>
                                </div>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($editUser['email']); ?>" required>
                                </div>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($editUser['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Role <span class="text-danger">*</span></label>
                                    <select name="role_id" class="form-select" required>
                                        <option value="">Pilih role</option>
                                        <?php foreach ($roles ?? [] as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo $editUser['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row g-2 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Password <small class="text-muted">(kosongkan jika tidak ingin mengubah)</small></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="password" id="editPassword" class="form-control form-control-sm" placeholder="••••••••">
                                        <button class="btn btn-outline-secondary" type="button" id="toggleEditPassword" title="Tampilkan/Sembunyikan Password">
                                            <i class="bi bi-eye" id="editPasswordIcon"></i>
                                        </button>
                                        <button class="btn btn-outline-info" type="button" onclick="revealSavedPassword(<?php echo (int)$editUser['id']; ?>)" title="Lihat Password Tersimpan">
                                            <i class="bi bi-key"></i>
                                        </button>
                                    </div>
                                    <small id="revealedPasswordBox" class="d-block mt-1 text-success fw-bold"></small>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" name="action" value="save_user" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>Update User
                                </button>
                                <button type="submit" name="action" value="delete_user" class="btn btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus user ini?')">
                                    <i class="bi bi-trash me-1"></i>Hapus
                                </button>
                                <a href="?section=user-setup&step=users" class="btn btn-outline-secondary">Batal</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Users Table (Compact) -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:20%;">Username</th>
                                <th style="width:25%;">Nama Lengkap</th>
                                <th style="width:25%;">Email</th>
                                <th style="width:15%;">Role</th>
                                <th style="width:15%;" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-3 text-muted"><small>Belum ada user</small></td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($users as $usr): ?>
                            <tr>
                                <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($usr['username']); ?></code></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($usr['full_name']); ?></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($usr['email']); ?></td>
                                <td><span class="badge bg-info" style="font-size:0.75rem;"><?php echo htmlspecialchars($usr['role_name']); ?></span></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?section=user-setup&step=users&user_id=<?php echo $usr['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?section=user-setup&step=business&user_id=<?php echo $usr['id']; ?>" class="btn btn-outline-success" title="Pilih bisnis untuk user ini">
                                            <i class="bi bi-building"></i>
                                        </a>
                                        <?php if ((int) $usr['id'] !== (int) $user['id']): ?>
                                            <button type="submit" form="delUser<?php echo (int) $usr['id']; ?>" class="btn btn-outline-danger" title="Hapus user">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" id="delUser<?php echo (int) $usr['id']; ?>" class="d-none" onsubmit="return confirm('Hapus user <?php echo htmlspecialchars(addslashes($usr['full_name'] ?: $usr['username'])); ?>?

Akses ke semua bisnis ikut dihapus. Bisnis milik user ini dipindah ke akun Anda.');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $usr['id']; ?>">
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!$editUser && !empty($users)): ?>
                <div class="mt-3">
                    <a href="?section=user-setup&step=business" class="btn btn-sm btn-success">
                        <i class="bi bi-arrow-right me-1"></i>Lanjut: Pilih Bisnis
                    </a>
                </div>
                <?php endif; ?>
                
                <?php elseif ($activeStep === 'business'): ?>
                <!-- ============== STEP 2: BUSINESS ASSIGNMENT ============== -->
                <div class="row">
                    <div class="col-12">
                        <?php if ($selectedUserId && $editUser): ?>
                        <h5 class="mb-3">📦 Assign Businesses for: <strong style="color: #667eea;"><?php echo htmlspecialchars($editUser['full_name']); ?></strong></h5>
                        <p class="text-muted">Centang bisnis yang boleh diakses user ini:</p>
                        
                        <div class="row">
                            <?php if (empty($allBusinesses)): ?>
                            <div class="col-12">
                                <p class="text-center py-5 text-muted">No businesses available. <a href="businesses.php?action=add">Tambah baru</a></p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($allBusinesses as $biz): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card business-card <?php echo in_array($biz['id'], $assignedBusinesses) ? 'selected' : ''; ?>" id="biz_<?php echo $biz['id']; ?>">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input business-checkbox" id="biz_check_<?php echo $biz['id']; ?>" data-business-id="<?php echo $biz['id']; ?>" data-user-id="<?php echo $selectedUserId; ?>" <?php echo in_array($biz['id'], $assignedBusinesses) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="biz_check_<?php echo $biz['id']; ?>">
                                                <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning mt-4">
                            <strong>⚠️ Error: User tidak ditemukan!</strong> Kembali ke Step 1 dan klik tombol "Assign" untuk user yang ingin dikonfigurasi.
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        <div class="d-flex gap-2">
                            <a href="?section=user-setup&step=users" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Kembali: Daftar User
                            </a>
                            <a href="?section=user-setup&step=permissions&user_id=<?php echo $selectedUserId; ?>" class="btn btn-success">
                                <i class="bi bi-arrow-right me-1"></i>Lanjut: Atur Menu
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($activeStep === 'permissions'): ?>
                <!-- ============== STEP 3: PERMISSIONS ============== -->
                <div class="row">
                    <div class="col-12">
                        <?php if ($selectedUserId && $editUser): ?>
                        <h5 class="mb-3">🔒 Set Permissions for: <strong style="color: #667eea;"><?php echo htmlspecialchars($editUser['full_name']); ?></strong></h5>
                        
                        <?php if (empty($userBusinesses)): ?>
                        <p class="text-center py-5 text-muted">User has no businesses assigned. <a href="?section=user-setup&step=business&user_id=<?php echo $selectedUserId; ?>">Pilih bisnis dulu</a></p>
                        <?php else: ?>
                        <form id="permissionsForm" method="POST">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Bisnis</th>
                                            <th colspan="4" class="text-center">Hak Akses</th>
                                        </tr>
                                        <tr>
                                            <th></th>
                                            <th class="text-center"><small>Lihat Saja</small></th>
                                            <th class="text-center"><small>Buat/Ubah</small></th>
                                            <th class="text-center"><small>Akses Penuh</small></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userBusinesses as $biz): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($biz['business_name']); ?></strong></td>
                                            <td class="text-center">
                                                <input type="radio" name="perm_<?php echo $biz['id']; ?>" value="view" class="permission-radio" data-business-id="<?php echo $biz['id']; ?>">
                                            </td>
                                            <td class="text-center">
                                                <input type="radio" name="perm_<?php echo $biz['id']; ?>" value="create" class="permission-radio" data-business-id="<?php echo $biz['id']; ?>">
                                            </td>
                                            <td class="text-center">
                                                <input type="radio" name="perm_<?php echo $biz['id']; ?>" value="all" class="permission-radio" data-business-id="<?php echo $biz['id']; ?>">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="alert alert-warning mt-4">
                            <strong>⚠️ Error: User tidak ditemukan!</strong> Kembali ke Step 1 dan klik tombol "Assign" untuk user yang ingin dikonfigurasi.
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        <div class="d-flex gap-2">
                            <a href="?section=user-setup&step=users" class="btn btn-secondary">
                                <i class="bi bi-house me-1"></i>Back to Users
                            </a>
                            <a href="?section=user-setup&step=business&user_id=<?php echo $selectedUserId; ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Back: Assign Business
                            </a>
                            <a href="?section=user-setup&step=users" class="btn btn-success ms-auto">
                                <i class="bi bi-check-circle me-1"></i>Done - Manage More Users
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <style>
    /* Langkah wizard (1 Buat User → 2 Pilih Bisnis → 3 Atur Menu) — gaya lain mengikuti tema panel */
    .wizard-steps { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 0 0 16px; padding: 12px 16px; border-bottom: 1px solid var(--dev-border); flex-wrap: wrap; }
    .step { display: flex; align-items: center; gap: 8px; opacity: .55; }
    .step.active, .step.completed { opacity: 1; }
    .step-number { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; font-weight: 700; background: #fff; border: 2px solid #d6d9e2; color: var(--dev-muted); }
    .step.active .step-number { background: var(--dev-primary); border-color: var(--dev-primary); color: #fff; }
    .step.completed .step-number { background: var(--dev-success); border-color: var(--dev-success); color: #fff; }
    .step-label { font-size: 12.5px; font-weight: 600; color: var(--dev-muted); }
    .step.active .step-label { color: var(--dev-primary); }
    .step.completed .step-label { color: var(--dev-success); }
    .step-arrow { color: #c3c7d1; font-size: 14px; }
    /* Isi kartu wizard diberi jarak tepi yang sama */
    .us-card { padding-bottom: 16px; }
    .us-card > :not(.card-header-custom):not(.wizard-steps) { margin-left: 16px; margin-right: 16px; }
    .us-card .card-header-custom { flex-direction: column; align-items: flex-start; gap: 2px; }

    .business-card { cursor: pointer; border: 1px solid var(--dev-border); background: #fff; border-radius: 10px; }
    .business-card:hover { border-color: var(--dev-primary); }
    .business-card.selected { border-color: var(--dev-primary); background: rgba(109, 74, 224, .05); }
    .business-card .card-body { padding: 10px 12px; }
    .business-card .form-check-label { cursor: pointer; margin-bottom: 0; font-weight: 500; }
    </style>
    
    <script>
    function revealSavedPassword(userId) {
        const box = document.getElementById('revealedPasswordBox');
        box.textContent = 'Memuat...';
        fetch('reveal-password.php?user_id=' + encodeURIComponent(userId))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                box.textContent = data.password ? ('Password saat ini: ' + data.password) : (data.error || 'Tidak tersedia');
                box.className = 'd-block mt-1 fw-bold ' + (data.password ? 'text-success' : 'text-danger');
            })
            .catch(function () {
                box.textContent = 'Gagal memuat password.';
                box.className = 'd-block mt-1 fw-bold text-danger';
            });
    }

    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const type = input.type === 'password' ? 'text' : 'password';
        input.type = type;
    }
    
    
    // Handle business checkbox changes
    document.querySelectorAll('.business-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = document.getElementById('biz_' + this.dataset.businessId);
            const businessId = this.dataset.businessId;
            const userId = this.dataset.userId;
            const action = this.checked ? 'assign' : 'remove';
            
            // Visual feedback
            if (this.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
            
            // Send to server
            const formData = new FormData();
            formData.append('action', action);
            formData.append('business_id', businessId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                console.log(action === 'assign' ? '✅ Business assigned' : '✅ Business removed');
            })
            .catch(error => {
                console.error('Error:', error);
                // Revert checkbox on error
                this.checked = !this.checked;
                if (this.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });
        });
    });
    
    // Handle permission radio changes
    document.querySelectorAll('.permission-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            const businessId = this.dataset.businessId;
            const permission = this.value;
            const userId = document.querySelector('input[name="user_id"]') ? document.querySelector('input[name="user_id"]').value : null;
            
            if (businessId && permission) {
                const formData = new FormData();
                formData.append('action', 'update_permission');
                formData.append('business_id', businessId);
                formData.append('permission', permission);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    console.log('✅ Permission updated');
                })
                .catch(error => console.error('Error:', error));
            }
        });
    });
    
    // Toggle password visibility
    const toggleEditPassword = document.getElementById('toggleEditPassword');
    const editPassword = document.getElementById('editPassword');
    const editPasswordIcon = document.getElementById('editPasswordIcon');
    
    if (toggleEditPassword && editPassword) {
        toggleEditPassword.addEventListener('click', function() {
            if (editPassword.type === 'password') {
                editPassword.type = 'text';
                editPasswordIcon.classList.remove('bi-eye');
                editPasswordIcon.classList.add('bi-eye-slash');
            } else {
                editPassword.type = 'password';
                editPasswordIcon.classList.remove('bi-eye-slash');
                editPasswordIcon.classList.add('bi-eye');
            }
        });
    }
    </script>
    
    <?php endif; ?>
</div>

<!-- User Modal for Add User -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">Tambah User Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Pilih role</option>
                            <?php foreach ($roles ?? [] as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="modalPassword" class="form-control" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleModalPassword()">
                                <i class="bi bi-eye" id="modalPasswordIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="action" value="save_user" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleModalPassword() {
    const input = document.getElementById('modalPassword');
    const icon = document.getElementById('modalPasswordIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

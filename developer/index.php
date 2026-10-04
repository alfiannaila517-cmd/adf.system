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
                    $deleteUserId = $_POST['user_id'];
                    
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
                    $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ?")->execute([$deleteUserId]);
                    $pdo->prepare("DELETE FROM user_preferences WHERE user_id = ?")->execute([$deleteUserId]);
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deleteUserId]);
                    
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    
                    $auth->logAction('delete_user', 'users', $deleteUserId);
                    $_SESSION['success_message'] = '✅ User deleted successfully!';
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
    // SECTION: DASHBOARD (default)
    
    // Get statistics
    $stats = [
        'users' => 0,
        'businesses' => 0,
        'active_businesses' => 0,
        'menus' => 0
    ];

    try {
        $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['businesses'] = $pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
        $stats['active_businesses'] = $pdo->query("SELECT COUNT(*) FROM businesses WHERE is_active = 1")->fetchColumn();
        $stats['menus'] = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE is_active = 1")->fetchColumn();
    } catch (Exception $e) {
        // Tables might not exist yet
    }

    // Get recent audit logs
    $auditLogs = [];
    try {
        $stmt = $pdo->query("
            SELECT al.*, u.username 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.created_at DESC 
            LIMIT 10
        ");
        $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist yet
    }

    // Get recent users
    $recentUsers = [];
    try {
        $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
        $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }

    // Get businesses list
    $businesses = [];
    try {
        $stmt = $pdo->query("
            SELECT b.*, u.full_name as owner_name 
            FROM businesses b 
            LEFT JOIN users u ON b.owner_id = u.id 
            ORDER BY b.created_at DESC
        ");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }
}

// Include header with sidebar
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($section === 'dashboard'): ?>
    <!-- ============== DASHBOARD SECTION ============== -->
    
    <div class="page-head">
        <p>Halo, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong> — ringkasan semua bisnis dan user ADF System.</p>
        <div class="actions">
            <a href="index.php?section=user-setup" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-plus me-1"></i>Tambah User</a>
            <a href="businesses.php?action=add" class="btn btn-primary btn-sm"><i class="bi bi-building-add me-1"></i>Tambah Bisnis</a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-users">
                <div class="stat-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['users']); ?></h3>
                    <p>Total User</p>
                </div>
                <a href="index.php?section=user-setup" class="stat-link">Lihat <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-businesses">
                <div class="stat-icon">
                    <i class="bi bi-building"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['businesses']); ?></h3>
                    <p>Total Bisnis</p>
                </div>
                <a href="businesses.php" class="stat-link">Lihat <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-active">
                <div class="stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['active_businesses']); ?></h3>
                    <p>Bisnis Aktif</p>
                </div>
                <a href="businesses.php?filter=active" class="stat-link">Lihat <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-menus">
                <div class="stat-icon">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['menus']); ?></h3>
                    <p>Menu Aplikasi</p>
                </div>
                <a href="menus.php" class="stat-link">Atur <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row">
        <!-- Businesses List -->
        <div class="col-lg-8 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-building me-2"></i>Bisnis</h5>
                    <a href="businesses.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Bisnis</th>
                                <th>Tipe</th>
                                <th>Database</th>
                                <th>Pemilik</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($businesses)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    Belum ada bisnis. <a href="businesses.php?action=add">Tambah bisnis</a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($businesses as $biz): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($biz['business_code']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo ucwords(str_replace('_', ' ', $biz['business_type'])); ?></span>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($biz['database_name']); ?></code>
                                </td>
                                <td><?php echo htmlspecialchars($biz['owner_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($biz['is_active']): ?>
                                    <span class="badge bg-success">Aktif</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="businesses.php?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="permissions.php?business_id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-info" title="Permissions">
                                        <i class="bi bi-shield-lock"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions & Recent Activity -->
        <div class="col-lg-4 mb-4">
            <!-- Quick Actions -->
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h5><i class="bi bi-lightning-charge me-2"></i>Aksi Cepat</h5>
                </div>
                <div class="quick-actions">
                    <a href="index.php?section=user-setup" class="quick-action-btn">
                        <i class="bi bi-person-plus"></i>
                        <span>Tambah User</span>
                    </a>
                    <a href="businesses.php?action=add" class="quick-action-btn">
                        <i class="bi bi-building-add"></i>
                        <span>Tambah Bisnis</span>
                    </a>
                    <a href="menus.php" class="quick-action-btn">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <span>Menu Aplikasi</span>
                    </a>
                    <a href="permissions.php" class="quick-action-btn">
                        <i class="bi bi-shield-lock"></i>
                        <span>Hak Akses Menu</span>
                    </a>
                    <a href="database.php" class="quick-action-btn">
                        <i class="bi bi-database"></i>
                        <span>Database</span>
                    </a>
                </div>
            </div>
            
            <!-- Recent Users -->
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-clock-history me-2"></i>User Terbaru</h5>
                    <a href="index.php?section=user-setup" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                </div>
                <div class="recent-list">
                    <?php if (empty($recentUsers)): ?>
                    <p class="text-muted text-center py-3">Belum ada user</p>
                    <?php else: ?>
                    <?php foreach ($recentUsers as $ru): ?>
                    <div class="recent-item">
                        <div class="recent-avatar">
                            <?php echo strtoupper(substr($ru['full_name'], 0, 1)); ?>
                        </div>
                        <div class="recent-info">
                            <strong><?php echo htmlspecialchars($ru['full_name']); ?></strong>
                            <small><?php echo htmlspecialchars($ru['username']); ?></small>
                        </div>
                        <div class="recent-status">
                            <?php if ($ru['is_active']): ?>
                            <span class="status-dot active"></span>
                            <?php else: ?>
                            <span class="status-dot inactive"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Audit Logs -->
    <div class="row">
        <div class="col-12">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-journal-text me-2"></i>Aktivitas Terakhir</h5>
                    <a href="audit.php" class="btn btn-sm btn-outline-primary">Lihat Log</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>User</th>
                                <th>Aksi</th>
                                <th>Data</th>
                                <th>Alamat IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    Belum ada aktivitas
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($auditLogs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? '-'); ?></td>
                                <td><code><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
                                        <a href="?section=user-setup&step=business&user_id=<?php echo $usr['id']; ?>" class="btn btn-outline-success" title="Assign">
                                            <i class="bi bi-building"></i>
                                        </a>
                                    </div>
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

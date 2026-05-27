<?php
/**
 * Debug Business Status - Diagnose Add Business Issues
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$pdo = $auth->getConnection();
$pageTitle = 'Business Status Debug';

// Detect hosting environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

// Get database prefix for hosting
$dbPrefix = '';
if ($isProduction && defined('DB_USER')) {
    $parts = explode('_', DB_USER);
    if (count($parts) >= 2) {
        $dbPrefix = $parts[0] . '_';
    }
}

// Get all businesses with their complete status
$businessesQuery = $pdo->query("
    SELECT 
        b.*,
        u.full_name as owner_name,
        CASE 
            WHEN b.is_active = 1 THEN 'Active'
            ELSE 'Inactive'
        END as activation_status
    FROM businesses b
    LEFT JOIN users u ON b.owner_id = u.id
    ORDER BY b.created_at DESC
");
$businesses = $businessesQuery->fetchAll(PDO::FETCH_ASSOC);

// Check each business's real status
$businessStatus = [];
foreach ($businesses as $biz) {
    $status = [
        'id' => $biz['id'],
        'name' => $biz['business_name'],
        'code' => $biz['business_code'],
        'database_name_config' => $biz['database_name'],
        'is_active_flag' => (bool)$biz['is_active'],
        'owner' => $biz['owner_name'],
    ];
    
    // Check if config file exists
    $slug = !empty($biz['slug']) ? $biz['slug'] : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $biz['business_name']), '-'));
    $configPath = dirname(dirname(__FILE__)) . '/config/businesses/' . $slug . '.php';
    $status['config_file'] = [
        'path' => $configPath,
        'exists' => file_exists($configPath)
    ];
    
    // Try to connect to the database
    $dbConnected = false;
    $dbExists = false;
    $tableCount = 0;
    $dbError = '';
    
    $actualDbName = $biz['database_name'];
    if ($isProduction && !strpos($actualDbName, $dbPrefix)) {
        $actualDbName = $dbPrefix . str_replace('adf_', '', $actualDbName);
    }
    
    try {
        $testPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $actualDbName, DB_USER, DB_PASS);
        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbConnected = true;
        $dbExists = true;
        $tableCount = (int)$testPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$actualDbName}'")->fetchColumn();
    } catch (Exception $e) {
        $dbError = $e->getMessage();
        // Try to check if database exists without connecting
        try {
            $rootPdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
            $rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $result = $rootPdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$actualDbName}'");
            if ($result->rowCount() > 0) {
                $dbExists = true;
            }
        } catch (Exception $e2) {
            // Silently continue
        }
    }
    
    $status['database'] = [
        'requested_name' => $biz['database_name'],
        'actual_name' => $actualDbName,
        'exists' => $dbExists,
        'connected' => $dbConnected,
        'table_count' => $tableCount,
        'error' => $dbError
    ];
    
    // Determine overall status
    if ($biz['is_active'] && $dbConnected && $status['config_file']['exists']) {
        $status['overall_status'] = '✅ COMPLETE - Ready to use';
        $status['status_class'] = 'success';
    } elseif (!$biz['is_active'] && !$dbConnected && !$status['config_file']['exists']) {
        $status['overall_status'] = '⚠️ INCOMPLETE - Setup not finished';
        $status['status_class'] = 'warning';
    } elseif ($biz['is_active'] && !$dbConnected) {
        $status['overall_status'] = '❌ ERROR - DB missing but marked active';
        $status['status_class'] = 'danger';
    } else {
        $status['overall_status'] = '⚠️ PARTIAL - Some steps done';
        $status['status_class'] = 'info';
    }
    
    $businessStatus[] = $status;
}

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$bizId = (int)($_POST['business_id'] ?? $_GET['business_id'] ?? 0);
$message = '';

if ($action === 'continue_setup' && $bizId) {
    $bizStmt = $pdo->prepare("SELECT id FROM businesses WHERE id = ?");
    $bizStmt->execute([$bizId]);
    if ($bizStmt->rowCount() > 0) {
        header('Location: businesses.php?action=setup&id=' . $bizId . '&step=2');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.debug-card { 
    background: #1e1e2d; 
    border: 1px solid rgba(255,255,255,.06); 
    border-radius: 10px; 
    padding: 1.5rem; 
    margin-bottom: 1.5rem;
}
.status-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
    vertical-align: middle;
}
.status-indicator.success { background: #10b981; }
.status-indicator.error { background: #ef4444; }
.status-indicator.warning { background: #f59e0b; }
.status-indicator.partial { background: #3b82f6; }
.detail-row { 
    display: flex; 
    justify-content: space-between; 
    align-items: center;
    padding: 0.75rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.detail-row:last-child { border-bottom: none; }
.detail-label { 
    font-size: 0.9rem; 
    color: #8b8b9e;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 500;
}
.detail-value { 
    font-size: 0.9rem; 
    color: #e0e0e0;
    font-family: 'Monaco', 'Courier New', monospace;
}
.status-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
}
.status-badge.success { background: rgba(16,185,129,.15); color: #10b981; }
.status-badge.warning { background: rgba(245,158,11,.15); color: #f59e0b; }
.status-badge.danger { background: rgba(239,68,68,.15); color: #ef4444; }
.status-badge.info { background: rgba(59,130,246,.15); color: #3b82f6; }
.issue-box {
    background: rgba(239,68,68,.1);
    border-left: 4px solid #ef4444;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}
.success-box {
    background: rgba(16,185,129,.1);
    border-left: 4px solid #10b981;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}
.warning-box {
    background: rgba(245,158,11,.1);
    border-left: 4px solid #f59e0b;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}
.action-steps {
    background: #151521;
    border: 1px solid rgba(111,66,193,.3);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}
.action-steps ol li {
    margin-bottom: 0.75rem;
    color: #c4c4d4;
}
.action-steps ol li strong {
    color: #8b5cf6;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">🔍 Business Status Debug</h4>
                <a href="businesses.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Businesses
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($businessStatus)): ?>
    <div class="debug-card">
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Belum ada bisnis.</strong> <a href="businesses.php?action=add">Buat bisnis baru</a>
        </div>
    </div>
    <?php else: ?>
    
    <?php foreach ($businessStatus as $status): ?>
    <div class="debug-card">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">
                    <span class="status-indicator <?php echo $status['status_class']; ?>"></span>
                    <?php echo htmlspecialchars($status['name']); ?>
                </h5>
                <small class="text-muted">Code: <code><?php echo htmlspecialchars($status['code']); ?></code></small>
            </div>
            <div class="status-badge <?php echo $status['status_class']; ?>">
                <?php echo $status['overall_status']; ?>
            </div>
        </div>

        <!-- Status Message -->
        <?php 
        $allOk = $status['is_active_flag'] && $status['database']['connected'] && $status['config_file']['exists'];
        if ($allOk):
        ?>
        <div class="success-box">
            ✅ Bisnis ini siap digunakan! Semua komponen sudah lengkap.
        </div>
        <?php elseif ($status['status_class'] === 'warning'): ?>
        <div class="warning-box">
            ⚠️ Bisnis ini belum selesai di-setup. Perlu menyelesaikan langkah-langkah setup.
        </div>
        <?php elseif ($status['status_class'] === 'danger'): ?>
        <div class="issue-box">
            ❌ Ada masalah: Database tidak ditemukan tetapi bisnis sudah ditandai sebagai aktif.
        </div>
        <?php endif; ?>

        <!-- Details -->
        <div style="background: #151521; border-radius: 8px; overflow: hidden; margin-bottom: 1.5rem;">
            <div class="detail-row">
                <span class="detail-label">Owner</span>
                <span class="detail-value"><?php echo htmlspecialchars($status['owner'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Activation Flag</span>
                <span class="detail-value">
                    <?php if ($status['is_active_flag']): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Inactive</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Database</span>
                <span class="detail-value">
                    <?php if ($status['database']['connected']): ?>
                    <span class="badge bg-success">✅ Connected (<?php echo $status['database']['table_count']; ?> tables)</span>
                    <?php elseif ($status['database']['exists']): ?>
                    <span class="badge bg-warning text-dark">⚠️ Exists, Not Connected</span>
                    <?php else: ?>
                    <span class="badge bg-danger">❌ Not Found</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Database Name</span>
                <code class="detail-value"><?php echo htmlspecialchars($status['database']['actual_name']); ?></code>
            </div>
            <div class="detail-row">
                <span class="detail-label">Config File</span>
                <span class="detail-value">
                    <?php if ($status['config_file']['exists']): ?>
                    <span class="badge bg-success">✅ Exists</span>
                    <?php else: ?>
                    <span class="badge bg-danger">❌ Missing</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($status['database']['error']): ?>
            <div class="detail-row">
                <span class="detail-label">Connection Error</span>
                <span class="detail-value text-danger small"><?php echo htmlspecialchars($status['database']['error']); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recommendations -->
        <?php 
        $issues = [];
        $actions = [];
        
        if (!$status['is_active_flag']) {
            $issues[] = "Bisnis belum diaktifkan (flag is_active = 0)";
            $actions[] = "Selesaikan setup wizard";
        }
        if (!$status['database']['connected']) {
            $issues[] = "Database tidak terhubung";
            if (!$status['database']['exists']) {
                $actions[] = "Buat database di cPanel atau gunakan wizard auto-create";
            } else {
                $actions[] = "Periksa kredensial database atau cek permissions";
            }
        }
        if (!$status['config_file']['exists']) {
            $issues[] = "File config bisnis belum ada";
            $actions[] = "Generate config file di setup wizard (Step 4)";
        }
        
        if (!empty($issues)):
        ?>
        <div class="action-steps">
            <strong style="color: #f59e0b; display: block; margin-bottom: 1rem;">🔧 Masalah Yang Terdeteksi:</strong>
            <ul style="color: #c4c4d4; margin-bottom: 1rem;">
                <?php foreach ($issues as $issue): ?>
                <li><?php echo $issue; ?></li>
                <?php endforeach; ?>
            </ul>
            
            <strong style="color: #8b5cf6; display: block; margin-bottom: 1rem;">✅ Langkah Untuk Memperbaiki:</strong>
            <ol>
                <?php foreach ($actions as $action): ?>
                <li><?php echo $action; ?></li>
                <?php endforeach; ?>
                <li><strong>Klik tombol "Continue Setup"</strong> di bawah untuk melanjutkan wizard</li>
            </ol>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="d-flex gap-2">
            <?php if (!$allOk): ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="continue_setup">
                <input type="hidden" name="business_id" value="<?php echo $status['id']; ?>">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-gear me-1"></i> Continue Setup
                </button>
            </form>
            <?php endif; ?>
            <a href="businesses.php?action=edit&id=<?php echo $status['id']; ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <a href="../developer-access.php?dev_access=<?php echo base64_encode($status['database']['actual_name']); ?>" 
               class="btn btn-outline-success" target="_blank">
                <i class="bi bi-box-arrow-up-right me-1"></i> Open
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>

    <!-- General Info -->
    <div class="debug-card" style="margin-top: 2rem;">
        <h6 style="color: #8b5cf6; margin-bottom: 1rem;">ℹ️ Environment Info</h6>
        <div style="background: #151521; border-radius: 8px; padding: 1rem; font-size: 0.9rem;">
            <div style="margin-bottom: 0.5rem;">
                <strong>Environment:</strong> <code><?php echo $isProduction ? 'Production/Hosting' : 'Local/Development'; ?></code>
            </div>
            <div style="margin-bottom: 0.5rem;">
                <strong>Database Host:</strong> <code><?php echo DB_HOST; ?></code>
            </div>
            <div style="margin-bottom: 0.5rem;">
                <strong>Database User:</strong> <code><?php echo DB_USER; ?></code>
            </div>
            <?php if ($dbPrefix): ?>
            <div style="margin-bottom: 0.5rem;">
                <strong>Hosting DB Prefix:</strong> <code><?php echo $dbPrefix; ?></code>
            </div>
            <?php endif; ?>
            <div>
                <strong>Master Database:</strong> <code><?php echo defined('DB_NAME') ? DB_NAME : 'Not defined'; ?></code>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

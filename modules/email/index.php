<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';

$auth = new Auth();
$auth->requireLogin();

$activeBizRaw = (string)($_SESSION['active_business_id'] ?? (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ''));
$activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBizRaw));
if ($activeBizNorm !== 'narayanahotel') {
    http_response_code(403);
    echo 'Menu Email Kantor hanya tersedia untuk bisnis Narayana.';
    exit;
}

$isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
if (!$isDeveloperRole && !$auth->hasPermission('email')) {
    http_response_code(403);
    echo 'Akses ditolak. Hubungi developer untuk pemberian izin email.';
    exit;
}

if (is_file(__DIR__ . '/../../config/email-narayana.php')) {
    require_once __DIR__ . '/../../config/email-narayana.php';
}

function ensureEmailMenuRegistered(): void
{
    try {
        $masterPdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $masterPdo->beginTransaction();

        $menuRow = $masterPdo->prepare('SELECT id FROM menu_items WHERE menu_code = ? LIMIT 1');
        $menuRow->execute(['email']);
        $menuId = (int)($menuRow->fetchColumn() ?: 0);

        if ($menuId <= 0) {
            $insMenu = $masterPdo->prepare("INSERT INTO menu_items (menu_name, menu_code, menu_icon, menu_url, menu_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)");
            $insMenu->execute(['Email Kantor', 'email', 'bi bi-envelope', 'modules/email/index.php', 69]);
            $menuId = (int)$masterPdo->lastInsertId();
        }

        if ($menuId > 0) {
            $bizStmt = $masterPdo->prepare('SELECT id FROM businesses WHERE slug = ? OR LOWER(REPLACE(REPLACE(business_code, "-", ""), "_", "")) = ? LIMIT 1');
            $bizStmt->execute(['narayana-hotel', 'narayanahotel']);
            $bid = (int)($bizStmt->fetchColumn() ?: 0);
            if ($bid > 0) {
                $linkStmt = $masterPdo->prepare('INSERT IGNORE INTO business_menu_config (business_id, menu_id, is_enabled, created_at) VALUES (?, ?, 1, NOW())');
                $linkStmt->execute([$bid, $menuId]);
            }
        }

        $masterPdo->commit();
    } catch (Throwable $e) {
        error_log('email menu register warning: ' . $e->getMessage());
    }
}
ensureEmailMenuRegistered();

$db = Database::getInstance();
$pageTitle = 'Email Kantor';
$currentUser = $auth->getCurrentUser();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$errorMsg = null;
$total = 0;
$messages = [];
$deleteMsg = null;
$emailConfig = EmailHelper::resolveConfig($db);

if ($emailConfig === null) {
    $errorMsg = 'Pengaturan email belum diisi. Klik "Pengaturan Email" di atas untuk mengisi host, user dan password.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $deleteUid = (int)($_POST['uid'] ?? 0);
        try {
            $emailHelper = new EmailHelper($emailConfig);
            $emailHelper->deleteMessage($deleteUid);
            $deleteMsg = 'Email berhasil dihapus.';
        } catch (Throwable $e) {
            $errorMsg = 'Gagal menghapus email: ' . $e->getMessage();
        }
    }

    try {
        $emailHelper = new EmailHelper($emailConfig);
        $result = $emailHelper->listMessages($perPage, $offset);
        $total = $result['total'];
        $messages = $result['messages'];
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

$totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

include '../../includes/header.php';
?>

<style>
    .em-wrap {
        max-width: 1000px;
        margin: 0 auto;
        padding: 14px;
    }

    .em-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
    }

    .em-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid #eef2f7;
        text-decoration: none;
        color: inherit;
    }

    .em-row:hover {
        background: #f8fafc;
    }

    .em-row.unread {
        background: #eff6ff;
        font-weight: 600;
    }

    .em-from {
        width: 220px;
        flex-shrink: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.9rem;
    }

    .em-subject {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.9rem;
    }

    .em-date {
        width: 150px;
        flex-shrink: 0;
        text-align: right;
        font-size: 0.8rem;
        color: #64748b;
    }

    .em-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 14px 16px;
        border-radius: 10px;
        margin-bottom: 14px;
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .em-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .em-pager {
        display: flex;
        gap: 8px;
        justify-content: center;
        padding: 14px;
    }

    .em-pager a {
        padding: 6px 12px;
        border: 1px solid #dbe4ee;
        border-radius: 6px;
        text-decoration: none;
        color: #334155;
        font-size: 0.85rem;
    }
</style>

<div class="em-wrap">
    <a href="<?php echo BASE_URL; ?>/index.php" style="display:inline-block;margin-bottom:12px;text-decoration:none;color:#1e3a8a;font-size:0.9rem;">&larr; Kembali ke Dashboard</a>

    <div class="em-toolbar">
        <div style="font-size:0.85rem;color:#64748b;">Inbox: office@narayanakarimunjawa.com &bull; <?php echo (int)$total; ?> email</div>
        <div style="display:flex;gap:8px;">
            <a href="<?php echo BASE_URL; ?>/modules/email/compose.php" style="text-decoration:none;padding:6px 14px;background:#1e3a8a;border-radius:6px;font-size:0.85rem;color:#fff;font-weight:600;">+ Tulis Email</a>
            <a href="<?php echo BASE_URL; ?>/modules/email/settings.php" style="text-decoration:none;padding:6px 14px;border:1px solid #dbe4ee;border-radius:6px;font-size:0.85rem;color:#334155;">Pengaturan Email</a>
            <a href="<?php echo BASE_URL; ?>/modules/email/index.php" style="text-decoration:none;padding:6px 14px;border:1px solid #dbe4ee;border-radius:6px;font-size:0.85rem;color:#334155;">Refresh</a>
        </div>
    </div>

    <?php if ($deleteMsg): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:0.9rem;"><?php echo htmlspecialchars($deleteMsg); ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="em-error">
            <strong>Gagal memuat email.</strong><br>
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="em-card">
        <?php if (!$errorMsg && empty($messages)): ?>
            <div style="padding:24px;text-align:center;color:#64748b;">Tidak ada email.</div>
        <?php endif; ?>

        <?php foreach ($messages as $m): ?>
            <div class="em-row <?php echo $m['seen'] ? '' : 'unread'; ?>">
                <a href="<?php echo BASE_URL; ?>/modules/email/view.php?uid=<?php echo (int)$m['uid']; ?>" style="display:flex;flex:1;gap:12px;align-items:center;text-decoration:none;color:inherit;min-width:0;">
                    <div class="em-from"><?php echo htmlspecialchars($m['from']); ?></div>
                    <div class="em-subject"><?php echo htmlspecialchars($m['subject']); ?></div>
                    <div class="em-date"><?php echo htmlspecialchars($m['date'] !== '' ? date('d M Y H:i', strtotime($m['date'])) : ''); ?></div>
                </a>
                <form method="post" onsubmit="return confirm('Hapus email ini?');" style="flex-shrink:0;margin:0;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="uid" value="<?php echo (int)$m['uid']; ?>">
                    <button type="submit" title="Hapus" style="background:none;border:none;color:#b91c1c;cursor:pointer;font-size:0.9rem;padding:6px 8px;">&#128465;</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="em-pager">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?page=<?php echo $p; ?>" style="<?php echo $p === $page ? 'background:#1e3a8a;color:#fff;border-color:#1e3a8a;' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/compose-widget.php'; ?>
<?php include '../../includes/footer.php'; ?>

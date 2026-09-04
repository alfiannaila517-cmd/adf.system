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

$db = Database::getInstance();

$uid = (int)($_GET['uid'] ?? 0);
$pageTitle = 'Email Kantor';
$currentUser = $auth->getCurrentUser();

$errorMsg = null;
$mail = null;
$emailConfig = EmailHelper::resolveConfig($db);

if ($emailConfig === null) {
    $errorMsg = 'Pengaturan email belum diisi. Buka menu Pengaturan Email untuk mengisi host, user dan password.';
} else {
    try {
        $emailHelper = new EmailHelper($emailConfig);
        $mail = $emailHelper->getMessageByUid($uid);
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<style>
    .em-wrap {
        max-width: 900px;
        margin: 0 auto;
        padding: 14px;
    }

    .em-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
    }

    .em-meta {
        border-bottom: 1px solid #eef2f7;
        padding-bottom: 12px;
        margin-bottom: 12px;
    }

    .em-meta h2 {
        font-size: 1.1rem;
        margin-bottom: 8px;
        color: #1e293b;
    }

    .em-meta div {
        font-size: 0.85rem;
        color: #475569;
        margin-bottom: 2px;
    }

    .em-back {
        display: inline-block;
        margin-bottom: 12px;
        text-decoration: none;
        color: #1e3a8a;
        font-size: 0.9rem;
    }

    .em-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 14px 16px;
        border-radius: 10px;
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .em-body-frame {
        width: 100%;
        min-height: 400px;
        border: none;
    }
</style>

<div class="em-wrap">
    <a class="em-back" href="<?php echo BASE_URL; ?>/modules/email/index.php">&larr; Kembali ke Inbox</a>

    <?php if ($errorMsg): ?>
        <div class="em-error"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php else: ?>
        <div class="em-card">
            <div class="em-meta">
                <h2><?php echo htmlspecialchars($mail['subject']); ?></h2>
                <div><strong>Dari:</strong> <?php echo htmlspecialchars($mail['from']); ?></div>
                <div><strong>Kepada:</strong> <?php echo htmlspecialchars($mail['to']); ?></div>
                <div><strong>Tanggal:</strong> <?php echo htmlspecialchars($mail['date'] !== '' ? date('d M Y H:i', strtotime($mail['date'])) : ''); ?></div>
            </div>

            <?php if (!empty($mail['body_html'])): ?>
                <iframe class="em-body-frame" sandbox="" srcdoc="<?php echo htmlspecialchars($mail['body_html']); ?>"></iframe>
            <?php else: ?>
                <pre style="white-space: pre-wrap; font-family: inherit; font-size: 0.9rem; color: #1e293b;"><?php echo htmlspecialchars($mail['body_plain']); ?></pre>
            <?php endif; ?>

            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef2f7;">
                <a href="<?php echo BASE_URL; ?>/modules/email/compose.php?reply_uid=<?php echo (int)$uid; ?>"
                   style="text-decoration:none;padding:8px 18px;background:#1e3a8a;color:#fff;border-radius:6px;font-size:0.85rem;font-weight:600;">&#8617; Balas</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>

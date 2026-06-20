<?php

/**
 * Sunsea - Database Center (Master Data Hub)
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

$stats = [
    'customers' => 0,
    'partners' => 0,
    'guides' => 0,
    'tickets' => 0,
    'caterings' => 0,
    'facilities' => 0,
];

try {
    $stats['customers'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active=1")->fetchColumn();
    $stats['partners'] = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_partners WHERE is_active=1")->fetchColumn();
    $stats['guides'] = (int)$pdo->query("SELECT COUNT(*) FROM guides WHERE is_active=1")->fetchColumn();
    $stats['tickets'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE is_active=1")->fetchColumn();
    $stats['caterings'] = (int)$pdo->query("SELECT COUNT(*) FROM caterings WHERE is_active=1")->fetchColumn();
    $stats['facilities'] = (int)$pdo->query("SELECT COUNT(*) FROM facilities WHERE is_active=1")->fetchColumn();
} catch (Exception $e) {
    // Older Sunsea DB may not have all tables yet.
}

$pageTitle = 'Database';
$activePage = 'database';
include 'layout-header.php';
?>

<div class="ss-card" style="margin-bottom:16px;">
    <div class="ss-card-header">
        <div>
            <div class="ss-card-title">Database Master Sunsea</div>
            <div class="ss-card-sub">Pusat data referensi untuk operasional booking, quotation, dan invoice</div>
        </div>
    </div>

    <div class="ss-stats-grid" style="margin-bottom:0;">
        <div class="ss-stat-card">
            <div class="ss-stat-icon ocean"><i data-feather="users"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['customers']; ?></div>
                <div class="ss-stat-label">Pelanggan Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon cyan"><i data-feather="building"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['partners']; ?></div>
                <div class="ss-stat-label">Hotel/Homestay Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon"><i data-feather="compass"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['guides']; ?></div>
                <div class="ss-stat-label">Guide Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon success"><i data-feather="tickets"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['tickets']; ?></div>
                <div class="ss-stat-label">Tiket Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon warning"><i data-feather="coffee"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['caterings']; ?></div>
                <div class="ss-stat-label">Catering Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon success"><i data-feather="tool"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['facilities']; ?></div>
                <div class="ss-stat-label">Fasilitas Tambahan Aktif</div>
            </div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Harga Hotel & Homestay</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Master mitra penginapan, tipe kamar, modal, dan harga jual.</div>
        <a href="partners.php" class="ss-btn ss-btn-primary"><i data-feather="building"></i> Kelola Hotel/Homestay</a>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Nama Pelanggan Detail</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Data identitas pelanggan lengkap untuk kebutuhan dokumen.</div>
        <a href="customers.php" class="ss-btn ss-btn-primary"><i data-feather="users"></i> Kelola Pelanggan</a>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Guide</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Guide darat/laut, status ketersediaan, dan tarif harian.</div>
        <a href="guides.php" class="ss-btn ss-btn-primary"><i data-feather="compass"></i> Kelola Guide</a>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Tiket</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Tiket kapal, BTN, express, fast boat, dengan harga modal dan jual.</div>
        <a href="tickets.php" class="ss-btn ss-btn-primary"><i data-feather="ticket"></i> Kelola Tiket</a>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Catering</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Master vendor catering, menu paket, dan harga porsi.</div>
        <a href="catering.php" class="ss-btn ss-btn-primary"><i data-feather="coffee"></i> Kelola Catering</a>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Fasilitas Tambahan</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Peralatan/layanan tambahan untuk booking ecer maupun paket.</div>
        <a href="facilities.php" class="ss-btn ss-btn-primary"><i data-feather="tool"></i> Kelola Fasilitas</a>
    </div>
</div>

<?php include 'layout-footer.php';

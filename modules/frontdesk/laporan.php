<?php

/**
 * FRONT DESK - LAPORAN HARIAN
 * Occupancy, tamu in-house, check-in/out hari ini & besok, dan order sarapan.
 * PDF (laporan-pdf.php) memakai data yang sama (laporan-data.php) dan bisa langsung dibagikan ke WhatsApp.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Laporan Harian';
require __DIR__ . '/laporan-data.php';
require_once '../../includes/WhatsAppHelper.php';

// WhatsApp gateway diatur (token + tujuan) -> tombol kirim 1 klik; selain itu menu Share perangkat.
$waHelper = new WhatsAppHelper($db);
$waTargetCount = count($waHelper->reportTargets());
$waReady = $waHelper->isConfigured() && $waTargetCount > 0;

$logoUrl = $company['invoice_logo'] ?? $company['logo'] ?? null;
$pdfName = 'Daily-Report-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$company['name']) . '-' . $today . '.pdf';

$fmtD = static fn($v) => $v ? date('d M', strtotime($v)) : '-';

include '../../includes/header.php';
?>

<style>
    /* Selector + .main-content .rp-wrap: menang atas aturan font global header (:is(td, th, button, ...)) */
    body[data-theme] .main-content .rp-wrap {
        max-width: 1400px;
        margin: 0 auto;
        padding-bottom: 1.5rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-card {
        border-radius: 14px;
        background: var(--fd-card);
        border: 1px solid var(--fd-edge);
        box-shadow: var(--fd-shadow);
        padding: 0.85rem 1rem;
        margin-bottom: 0.75rem;
    }

    /* Toolbar */
    body[data-theme] .main-content .rp-wrap .rp-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-toolbar h1 {
        margin: 0;
        font-size: 0.95rem !important;
        font-weight: 700;
        color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-toolbar h1 small {
        display: block;
        font-size: 0.68rem !important;
        font-weight: 500;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-actions {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    body[data-theme] .main-content .rp-wrap .rp-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        height: 32px;
        padding: 0 0.85rem;
        border-radius: 9px;
        border: 1px solid transparent;
        font-size: 0.72rem !important;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        background: linear-gradient(135deg, var(--fd-accent), var(--fd-accent-2));
        box-shadow: 0 4px 10px -4px rgba(29, 78, 216, 0.5);
    }

    body[data-theme] .main-content .rp-wrap .rp-btn.ghost {
        background: var(--fd-tile);
        border-color: var(--fd-line);
        box-shadow: none;
        color: var(--fd-text) !important;
        -webkit-text-fill-color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-btn.wa {
        background: #25d366;
        box-shadow: 0 4px 12px -4px rgba(37, 211, 102, 0.6);
    }

    body[data-theme] .main-content .rp-wrap .rp-btn.wa:hover {
        background: #1ebe5b;
    }

    body[data-theme] .main-content .rp-wrap .rp-btn.wa svg {
        width: 16px;
        height: 16px;
    }

    body[data-theme] .main-content .rp-wrap .rp-btn:disabled {
        opacity: 0.6;
        cursor: wait;
    }

    body[data-theme] .main-content .rp-wrap .rp-btn svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }

    /* Kop + statistik */
    body[data-theme] .main-content .rp-wrap .rp-kop {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding-bottom: 0.75rem;
        margin-bottom: 0.75rem;
        border-bottom: 2px solid var(--fd-accent);
    }

    body[data-theme] .main-content .rp-wrap .rp-kop img,
    body[data-theme] .main-content .rp-wrap .rp-kop .rp-kop-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
        background: #fff;
        box-shadow: 0 0 0 1px var(--fd-line);
    }

    body[data-theme] .main-content .rp-wrap .rp-kop-icon {
        display: grid;
        place-items: center;
        font-size: 1.4rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-kop b {
        display: block;
        font-size: 0.95rem;
        color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-kop small {
        display: block;
        font-size: 0.66rem !important;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-kop .rp-kop-title {
        margin-left: auto;
        text-align: right;
    }

    body[data-theme] .main-content .rp-wrap .rp-kop .rp-kop-title b {
        font-size: 0.8rem;
        letter-spacing: 0.12em;
        color: var(--fd-accent-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-stats {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }

    body[data-theme] .main-content .rp-wrap .rp-stat {
        padding: 0.2rem 0.85rem;
        border-left: 1px solid var(--fd-line);
    }

    body[data-theme] .main-content .rp-wrap .rp-stat:first-child {
        border-left: 0;
        padding-left: 0;
    }

    body[data-theme] .main-content .rp-wrap .rp-stat b {
        display: block;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--fd-text) !important;
        font-variant-numeric: tabular-nums;
    }

    body[data-theme] .main-content .rp-wrap .rp-stat span {
        font-size: 0.56rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-stat.accent b {
        color: var(--fd-accent-text) !important;
    }

    /* Bagian & tabel */
    body[data-theme] .main-content .rp-wrap .rp-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-grid .rp-card {
        margin-bottom: 0;
    }

    body[data-theme] .main-content .rp-wrap .rp-row-gap {
        margin-bottom: 0.75rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-sec-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.55rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-sec-head h3 {
        margin: 0;
        font-size: 0.8rem !important;
        font-weight: 700;
        color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-count {
        min-width: 22px;
        padding: 0.05rem 0.5rem;
        border-radius: 999px;
        background: var(--fd-accent-soft);
        color: var(--fd-accent-text) !important;
        font-size: 0.64rem;
        font-weight: 700;
        text-align: center;
    }

    body[data-theme] .main-content .rp-wrap .rp-table-wrap {
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid var(--fd-line);
    }

    body[data-theme] .main-content .rp-wrap table.rp-table {
        width: 100%;
        border-collapse: collapse;
    }

    body[data-theme] .main-content .rp-wrap .rp-table th {
        padding: 0.5rem 0.65rem;
        background: var(--fd-accent) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
        font-size: 0.56rem !important;
        font-weight: 700;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        text-align: left;
        white-space: nowrap;
    }

    body[data-theme] .main-content .rp-wrap .rp-table td {
        padding: 0.45rem 0.65rem;
        border-top: 1px solid var(--fd-line);
        font-size: 0.74rem !important;
        color: var(--fd-text-2) !important;
        vertical-align: middle;
    }

    body[data-theme] .main-content .rp-wrap .rp-table td.name {
        color: var(--fd-text) !important;
        font-weight: 600;
    }

    body[data-theme] .main-content .rp-wrap .rp-table td small {
        display: block;
        font-size: 0.64rem !important;
        font-weight: 500;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-table tbody tr:hover td {
        background: var(--fd-accent-soft);
    }

    body[data-theme] .main-content .rp-wrap .rp-room {
        display: inline-block;
        min-width: 38px;
        padding: 0.12rem 0.45rem;
        border-radius: 7px;
        background: linear-gradient(135deg, var(--fd-accent), var(--fd-accent-2));
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.68rem;
        font-weight: 700;
        text-align: center;
    }

    body[data-theme] .main-content .rp-wrap .rp-pay {
        display: inline-block;
        padding: 0.1rem 0.5rem;
        border-radius: 999px;
        font-size: 0.6rem;
        font-weight: 700;
    }

    body[data-theme] .main-content .rp-wrap .rp-pay.paid { background: rgba(5, 150, 105, 0.12); color: #047857 !important; }
    body[data-theme] .main-content .rp-wrap .rp-pay.partial { background: rgba(217, 119, 6, 0.14); color: #b45309 !important; }
    body[data-theme] .main-content .rp-wrap .rp-pay.unpaid { background: rgba(220, 38, 38, 0.1); color: #b91c1c !important; }
    body[data-theme="dark"] .main-content .rp-wrap .rp-pay.paid { color: #6ee7b7 !important; }
    body[data-theme="dark"] .main-content .rp-wrap .rp-pay.partial { color: #fbbf24 !important; }
    body[data-theme="dark"] .main-content .rp-wrap .rp-pay.unpaid { color: #fca5a5 !important; }

    body[data-theme] .main-content .rp-wrap .rp-empty {
        padding: 0.85rem;
        border-radius: 10px;
        border: 1px dashed var(--fd-input-border);
        text-align: center;
        font-size: 0.72rem;
        color: var(--fd-muted) !important;
    }

    /* Sarapan */
    body[data-theme] .main-content .rp-wrap .rp-recap {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-bottom: 0.7rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-recap span {
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        background: rgba(217, 119, 6, 0.1);
        border: 1px solid rgba(217, 119, 6, 0.25);
        font-size: 0.68rem;
        font-weight: 600;
        color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-recap span b {
        margin-left: 0.25rem;
        color: #b45309 !important;
    }

    body[data-theme="dark"] .main-content .rp-wrap .rp-recap span b { color: #fbbf24 !important; }

    body[data-theme] .main-content .rp-wrap .rp-bf-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 0.55rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf {
        padding: 0.6rem 0.7rem;
        border-radius: 11px;
        background: var(--fd-tile);
        border: 1px solid var(--fd-line);
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-top {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-top b {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 0.76rem;
        color: var(--fd-text) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-meta {
        margin: 0.3rem 0 0.4rem;
        font-size: 0.64rem;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-items {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-items span {
        padding: 0.1rem 0.45rem;
        border-radius: 6px;
        background: var(--fd-accent-soft);
        font-size: 0.64rem;
        color: var(--fd-text-2) !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-note {
        margin-top: 0.35rem;
        font-size: 0.64rem;
        color: #b45309 !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-bf-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.3rem;
        margin-top: 0.45rem;
    }

    body[data-theme] .main-content .rp-wrap .rp-mini {
        height: 24px;
        padding: 0 0.6rem;
        border-radius: 7px;
        border: 1px solid rgba(37, 99, 235, 0.3);
        background: var(--fd-accent-soft);
        color: var(--fd-accent-text) !important;
        -webkit-text-fill-color: var(--fd-accent-text) !important;
        font-size: 0.64rem !important;
        font-weight: 600;
        cursor: pointer;
    }

    body[data-theme] .main-content .rp-wrap .rp-mini.del {
        border-color: rgba(220, 38, 38, 0.3);
        background: rgba(220, 38, 38, 0.08);
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    body[data-theme="dark"] .main-content .rp-wrap .rp-mini.del {
        color: #fca5a5 !important;
        -webkit-text-fill-color: #fca5a5 !important;
    }

    body[data-theme="dark"] .main-content .rp-wrap .rp-bf-note {
        color: #fbbf24 !important;
    }

    body[data-theme] .main-content .rp-wrap .rp-stamp {
        margin-top: 0.25rem;
        text-align: center;
        font-size: 0.64rem;
        color: var(--fd-muted) !important;
    }

    /* Salinan terang untuk PDF yang dibuat di browser (cadangan bila PDF server gagal) */
    body[data-theme] .main-content .rp-wrap.rp-print {
        --fd-card: #ffffff;
        --fd-tile: #f8fafc;
        --fd-edge: #e2e8f0;
        --fd-line: #e2e8f0;
        --fd-shadow: none;
        --fd-input-border: #cbd5e1;
        --fd-text: #0f172a;
        --fd-text-2: #334155;
        --fd-muted: #64748b;
        --fd-accent: #0f2747;
        --fd-accent-2: #1d4ed8;
        --fd-accent-soft: #eef2ff;
        --fd-accent-text: #1d4ed8;
        position: absolute;
        left: -10000px;
        top: 0;
        width: 1060px;
        max-width: none;
        padding: 0;
        background: #ffffff;
    }

    body[data-theme] .main-content .rp-wrap.rp-print .rp-toolbar,
    body[data-theme] .main-content .rp-wrap.rp-print .rp-bf-actions {
        display: none;
    }

    /* Toast */
    .rp-toast {
        position: fixed;
        left: 50%;
        bottom: 24px;
        z-index: 10070;
        max-width: calc(100% - 32px);
        padding: 0.65rem 1rem;
        border-radius: 12px;
        background: #0f172a;
        color: #fff;
        font-size: 0.78rem;
        line-height: 1.45;
        box-shadow: 0 16px 40px -12px rgba(15, 23, 42, 0.55);
        transform: translate(-50%, 20px);
        opacity: 0;
        transition: opacity 0.2s, transform 0.2s;
        pointer-events: none;
    }

    .rp-toast.show {
        opacity: 1;
        transform: translate(-50%, 0);
    }

    @media (max-width: 900px) {
        body[data-theme] .main-content .rp-wrap .rp-grid {
            grid-template-columns: 1fr;
        }

        body[data-theme] .main-content .rp-wrap .rp-stats {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            row-gap: 0.6rem;
        }

        body[data-theme] .main-content .rp-wrap .rp-stat:nth-child(4) {
            border-left: 0;
            padding-left: 0;
        }

        body[data-theme] .main-content .rp-wrap .rp-kop .rp-kop-title {
            display: none;
        }
    }
</style>

<?php
/** Tabel bagian: $cols = [judul => fungsi isi sel(row)] */
$rpTable = static function (array $rows, array $cols, string $empty): void {
    if (!$rows) {
        echo '<div class="rp-empty">' . htmlspecialchars($empty) . '</div>';
        return;
    }
    echo '<div class="rp-table-wrap"><table class="rp-table"><thead><tr>';
    foreach (array_keys($cols) as $label) echo '<th>' . htmlspecialchars($label) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $fn) echo $fn($r);
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
$h = static fn($v) => htmlspecialchars((string)$v);
$cRoom = static fn($r) => '<td><span class="rp-room">' . htmlspecialchars((string)$r['room_number']) . '</span></td>';
$cType = static fn($r) => '<td>' . htmlspecialchars((string)($r['type_name'] ?: '-')) . '</td>';
$cName = static fn($r) => '<td class="name">' . htmlspecialchars((string)$r['guest_name']) . '</td>';
$cCode = static fn($r) => '<td>' . htmlspecialchars((string)$r['booking_code']) . '</td>';
$cIn = static fn($r) => '<td>' . $fmtD($r['check_in_date']) . '</td>';
$cOut = static fn($r) => '<td>' . $fmtD($r['check_out_date']) . '</td>';
$cPhone = static fn($r) => '<td>' . htmlspecialchars((string)($r['phone'] ?: '-')) . '</td>';
$section = static function (string $title, array $rows, array $cols, string $empty) use ($rpTable): void {
    echo '<div class="rp-card"><div class="rp-sec-head"><h3>' . htmlspecialchars($title) . '</h3><span class="rp-count">' . count($rows) . '</span></div>';
    $rpTable($rows, $cols, $empty);
    echo '</div>';
};
?>

<div class="rp-wrap">
    <!-- Toolbar -->
    <div class="rp-toolbar">
        <h1>Laporan Harian <small><?php echo $h($todayDisplay); ?></small></h1>
        <div class="rp-actions">
            <a class="rp-btn ghost" href="laporan-pdf.php" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><path d="M6 14h12v8H6z" /></svg>
                Lihat & Cetak
            </a>
            <a class="rp-btn" href="laporan-pdf.php?download=1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5" /><path d="M12 15V3" /></svg>
                Unduh PDF
            </a>
            <?php if ($waReady): ?>
                <button type="button" class="rp-btn ghost" id="rpShareWa" title="Bagikan PDF lewat menu Share perangkat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" /></svg>
                    <span>Bagikan manual</span>
                </button>
                <button type="button" class="rp-btn wa" id="rpSendWa" data-count="<?php echo $waTargetCount; ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                    <span>Kirim ke WhatsApp (<?php echo $waTargetCount; ?>)</span>
                </button>
            <?php else: ?>
                <button type="button" class="rp-btn wa" id="rpShareWa">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                    <span>Kirim PDF ke WhatsApp</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Kop & ringkasan -->
    <div class="rp-card">
        <div class="rp-kop">
            <?php if ($logoUrl): ?>
                <img src="<?php echo $h($logoUrl); ?>" alt="Logo">
            <?php else: ?>
                <span class="rp-kop-icon"><?php echo $company['icon']; ?></span>
            <?php endif; ?>
            <div>
                <b><?php echo $h($company['name']); ?></b>
                <small><?php echo $h(implode(' · ', array_filter([$company['address'], $company['phone'], $company['email']]))); ?></small>
            </div>
            <div class="rp-kop-title">
                <b>LAPORAN HARIAN</b>
                <small><?php echo $h($todayDisplay); ?></small>
            </div>
        </div>
        <div class="rp-stats">
            <div class="rp-stat accent"><b><?php echo $occupancyRate; ?>%</b><span>Occupancy · <?php echo $occupiedRooms . '/' . $totalRooms; ?></span></div>
            <div class="rp-stat"><b><?php echo count($inHouseGuests); ?></b><span>In house</span></div>
            <div class="rp-stat"><b><?php echo count($checkInToday); ?></b><span>Check-in hari ini</span></div>
            <div class="rp-stat"><b><?php echo count($checkOutToday); ?></b><span>Check-out hari ini</span></div>
            <div class="rp-stat"><b><?php echo count($arrivalTomorrow); ?></b><span>Tiba besok</span></div>
            <div class="rp-stat"><b><?php echo $breakfastPax; ?></b><span>Pax sarapan</span></div>
        </div>
    </div>

    <?php
    $section('Tamu In-House', $inHouseGuests, [
        'Kamar'  => $cRoom,
        'Tipe'   => $cType,
        'Tamu'   => $cName,
        'Kode'   => $cCode,
        'Masuk'  => $cIn,
        'Keluar' => $cOut,
        'Bayar'  => static fn($r) => '<td><span class="rp-pay ' . $r['pay_state'] . '">' . $payStateLabel[$r['pay_state']] . '</span>' . ($r['balance'] > 0 ? '<small>Sisa Rp ' . number_format($r['balance'], 0, ',', '.') . '</small>' : '') . '</td>',
    ], 'Tidak ada tamu in-house');
    ?>

    <div class="rp-grid rp-row-gap">
        <?php
        $section('Check-in Hari Ini', $checkInToday, ['Kamar' => $cRoom, 'Tipe' => $cType, 'Tamu' => $cName, 'Telepon' => $cPhone, 'Keluar' => $cOut], 'Tidak ada kedatangan hari ini');
        $section('Check-out Hari Ini', $checkOutToday, ['Kamar' => $cRoom, 'Tipe' => $cType, 'Tamu' => $cName, 'Kode' => $cCode, 'Masuk' => $cIn], 'Tidak ada check-out hari ini');
        ?>
    </div>
    <div class="rp-grid rp-row-gap">
        <?php
        $section('Check-out Besok', $checkOutTomorrow, ['Kamar' => $cRoom, 'Tipe' => $cType, 'Tamu' => $cName, 'Telepon' => $cPhone, 'Masuk' => $cIn], 'Tidak ada check-out besok');
        $section('Kedatangan Besok', $arrivalTomorrow, ['Kamar' => $cRoom, 'Tipe' => $cType, 'Tamu' => $cName, 'Telepon' => $cPhone, 'Pax' => static fn($r) => '<td>' . (int)($r['guest_count'] ?: 1) . '</td>', 'Keluar' => $cOut], 'Tidak ada kedatangan besok');
        ?>
    </div>

    <!-- Sarapan -->
    <div class="rp-card">
        <div class="rp-sec-head">
            <h3>Order Sarapan</h3>
            <span class="rp-count" id="rpBfCount"><?php echo count($breakfastOrders); ?></span>
        </div>
        <?php if (!$breakfastOrders): ?>
            <div class="rp-empty">Belum ada order sarapan hari ini</div>
        <?php else: ?>
            <?php if ($menuRecap): ?>
                <div class="rp-recap">
                    <?php foreach ($menuRecap as $menuName => $qty): ?>
                        <span><?php echo $h($menuName); ?><b>×<?php echo (int)$qty; ?></b></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="rp-bf-grid">
                <?php foreach ($breakfastOrders as $order): ?>
                    <div class="rp-bf" id="bf-row-<?php echo (int)$order['id']; ?>">
                        <div class="rp-bf-top">
                            <span class="rp-room"><?php echo $h($order['room_number'] ?: '-'); ?></span>
                            <b title="<?php echo $h($order['guest_name']); ?>"><?php echo $h($order['guest_name']); ?></b>
                        </div>
                        <div class="rp-bf-meta">
                            <?php echo $order['breakfast_time'] ? date('H:i', strtotime($order['breakfast_time'])) : '-'; ?>
                            · <?php echo (int)$order['total_pax']; ?> pax · <?php echo $h($bfLocationLabel($order['location'] ?? '')); ?>
                        </div>
                        <div class="rp-bf-items">
                            <?php foreach ($order['menu_items'] as $item): ?>
                                <span><?php echo (int)($item['quantity'] ?? 1); ?>× <?php echo $h($item['menu_name'] ?? '?'); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($order['special_requests'])): ?>
                            <div class="rp-bf-note">Catatan: <?php echo $h($order['special_requests']); ?></div>
                        <?php endif; ?>
                        <div class="rp-bf-actions">
                            <button type="button" class="rp-mini" onclick="location.href='breakfast.php?edit=<?php echo (int)$order['id']; ?>'">Edit</button>
                            <button type="button" class="rp-mini del" onclick="deleteBreakfastOrder(<?php echo (int)$order['id']; ?>, <?php echo $h(json_encode((string)$order['guest_name'])); ?>)">Hapus</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="rp-stamp">
        Dibuat oleh <?php echo $h($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff'); ?> · <?php echo date('d M Y, H:i'); ?> WIB · ADF System
    </div>
</div>

<div class="rp-toast" id="rpToast"></div>

<script>
    (function() {
        const PDF_URL = 'laporan-pdf.php?download=1';
        const PDF_NAME = <?php echo json_encode($pdfName); ?>;
        const WA_TEXT = <?php echo json_encode($waText, JSON_UNESCAPED_UNICODE); ?>;
        const btn = document.getElementById('rpShareWa');
        const label = btn.querySelector('span');
        const SHARE_LABEL = label.textContent;
        let pdfFile = null;
        let pdfPromise = null;

        function toast(msg, ms) {
            const t = document.getElementById('rpToast');
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(t._h);
            t._h = setTimeout(() => t.classList.remove('show'), ms || 4000);
        }

        // PDF disiapkan di latar belakang sejak halaman dibuka, agar tombol bagikan langsung jalan
        // (browser hanya mengizinkan menu Share sesaat setelah klik).
        function loadPdf() {
            if (!pdfPromise) {
                pdfPromise = fetch(PDF_URL, { credentials: 'same-origin' })
                    .then(async r => {
                        const type = r.headers.get('Content-Type') || '';
                        if (!r.ok || type.indexOf('application/pdf') === -1) {
                            const msg = (await r.text()).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                            throw new Error(msg.slice(0, 180) || ('HTTP ' + r.status));
                        }
                        return r.blob();
                    })
                    .catch(serverErr => {
                        console.warn('PDF server gagal, membuat PDF di browser:', serverErr);
                        return clientPdf().catch(clientErr => {
                            throw new Error(serverErr.message + ' / ' + clientErr.message);
                        });
                    })
                    .then(b => (pdfFile = new File([b], PDF_NAME, { type: 'application/pdf' })))
                    .catch(e => {
                        pdfPromise = null;
                        throw e;
                    });
            }
            return pdfPromise;
        }

        function loadScript(src) {
            return new Promise((resolve, reject) => {
                if (window.html2pdf) return resolve();
                const s = document.createElement('script');
                s.src = src;
                s.onload = resolve;
                s.onerror = () => reject(new Error('library PDF gagal dimuat'));
                document.head.appendChild(s);
            });
        }

        // Cadangan: PDF dibuat di browser dari isi halaman (salinan bertema terang).
        async function clientPdf() {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js');
            const src = document.querySelector('.rp-wrap');
            const copy = src.cloneNode(true);
            copy.classList.add('rp-print');
            copy.removeAttribute('id');
            src.parentNode.appendChild(copy);
            try {
                return await window.html2pdf().set({
                    margin: [8, 8, 10, 8],
                    image: { type: 'jpeg', quality: 0.96 },
                    html2canvas: { scale: 2, backgroundColor: '#ffffff', useCORS: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.rp-bf', '.rp-sec-head'] }
                }).from(copy).outputPdf('blob');
            } finally {
                copy.remove();
            }
        }

        function canShareFile(f) {
            try {
                return !!(navigator.canShare && navigator.canShare({ files: [f] }));
            } catch (e) {
                return false;
            }
        }

        // Cadangan bila perangkat tidak bisa membagikan file: unduh PDF, lalu buka WhatsApp dengan ringkasan.
        function fallback(file) {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(file);
            a.download = PDF_NAME;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 4000);
            window.open('https://wa.me/?text=' + encodeURIComponent(WA_TEXT), '_blank');
            toast('PDF sudah diunduh. Di WhatsApp, pilih kontak lalu lampirkan file "' + PDF_NAME + '".', 7000);
        }

        async function share() {
            btn.disabled = true;
            label.textContent = 'Menyiapkan PDF...';
            try {
                const file = pdfFile || await loadPdf();
                if (canShareFile(file)) {
                    try {
                        await navigator.share({ files: [file], title: 'Laporan Harian', text: WA_TEXT });
                    } catch (e) {
                        if (e.name === 'NotAllowedError') {
                            // Izin klik kedaluwarsa saat menunggu PDF: minta ketuk sekali lagi (PDF sudah siap).
                            label.textContent = 'Ketuk lagi untuk membagikan';
                            btn.disabled = false;
                            return;
                        }
                        if (e.name !== 'AbortError') fallback(file);
                    }
                } else {
                    fallback(file);
                }
            } catch (e) {
                toast('Gagal menyiapkan PDF: ' + e.message, 6000);
            }
            label.textContent = SHARE_LABEL;
            btn.disabled = false;
        }

        btn.addEventListener('click', share);

        const sendBtn = document.getElementById('rpSendWa');
        if (sendBtn) {
            const sendLabel = sendBtn.querySelector('span');
            const sendText = sendLabel.textContent;
            sendBtn.addEventListener('click', () => {
                if (!confirm('Kirim PDF Laporan Harian ke ' + sendBtn.dataset.count + ' tujuan WhatsApp?')) return;
                sendBtn.disabled = true;
                sendLabel.textContent = 'Mengirim…';
                fetch('laporan-wa.php', { method: 'POST', credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(r => {
                        const fails = (r.results || []).filter(x => !x.ok).map(x => x.target + ': ' + x.detail);
                        toast((r.ok ? '✓ ' : '✕ ') + r.detail + (fails.length ? ' — ' + fails.join('; ') : ''), r.ok && !fails.length ? 4000 : 9000);
                    })
                    .catch(() => toast('✕ Gagal menghubungi server', 6000))
                    .finally(() => { sendBtn.disabled = false; sendLabel.textContent = sendText; });
            });
        }
        window.addEventListener('load', () => setTimeout(() => loadPdf().catch(() => {}), 800));
        window.rpToast = toast;
    })();

    function deleteBreakfastOrder(id, guestName) {
        if (!confirm('Hapus order sarapan untuk ' + guestName + '?')) return;
        fetch('../../api/breakfast-order-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: id })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Gagal menghapus');
                const row = document.getElementById('bf-row-' + id);
                if (row) row.remove();
                document.getElementById('rpBfCount').textContent = document.querySelectorAll('.rp-bf').length;
                window.rpToast('Order sarapan dihapus');
            })
            .catch(e => window.rpToast(e.message || 'Gagal menghapus'));
    }
</script>

<?php include '../../includes/footer.php'; ?>

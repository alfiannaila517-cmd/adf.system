<?php

/**
 * Reservasi Cloudbeds yang belum ditempatkan nomor kamar (seperti baris "Unassigned" di kalender Cloudbeds).
 * Tabel cloudbeds_unassigned diisi tiap sinkron (CloudbedsSync::plan). Ditampilkan sebagai strip peringatan di
 * Dashboard & Kalender Front Desk: kamar belum bisa dipakai ADF untuk reservasi ini, jadi bisa terjual dua kali.
 */

function cbIsConfigured($db): bool
{
    try {
        $r = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_api_key'");
        return !empty($r['setting_value']);
    } catch (\Throwable $e) {
        return false;
    }
}

/** @return array<int,array<string,mixed>> */
function cbUnassignedRows($db): array
{
    try {
        if (!$db->fetchOne("SHOW TABLES LIKE 'cloudbeds_unassigned'")) {
            return [];
        }
        return $db->fetchAll("SELECT cb_reservation_id, guest, source, check_in, check_out, room_type, rooms_needed
            FROM cloudbeds_unassigned WHERE check_out >= CURDATE() ORDER BY check_in ASC, guest ASC LIMIT 40") ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/** Lencana jumlah untuk tombol (0 bila tidak ada). */
function cbUnassignedCount($db): int
{
    return count(cbUnassignedRows($db));
}

/**
 * HTML strip peringatan (kosong bila tidak ada reservasi yang belum ditempatkan).
 * @param string $href tautan "Pratinjau" (halaman Cloudbeds → Pratinjau reservasi)
 */
function cbUnassignedStrip($db, string $href = 'cloudbeds.php?preview=1'): string
{
    if (!cbIsConfigured($db)) {
        return '';
    }
    $rows = cbUnassignedRows($db);
    if (!$rows) {
        return '';
    }
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $d = fn($v) => $v ? date('d M', strtotime((string)$v)) : '?';
    $chips = '';
    foreach (array_slice($rows, 0, 8) as $r) {
        $chips .= '<span class="cbu-chip" title="Cloudbeds #' . $esc($r['cb_reservation_id']) . '">'
            . '<b>' . $esc($r['guest'] ?: 'Guest') . '</b>'
            . '<i>' . $esc($d($r['check_in'])) . ' – ' . $esc($d($r['check_out'])) . '</i>'
            . ($r['room_type'] !== '' && $r['room_type'] !== null ? '<em>' . $esc($r['room_type']) . '</em>' : '')
            . ($r['source'] !== '' && $r['source'] !== null ? '<u>' . $esc($r['source']) . '</u>' : '')
            . '</span>';
    }
    $more = count($rows) > 8 ? '<span class="cbu-more">+' . (count($rows) - 8) . '</span>' : '';
    static $cssDone = false;
    $css = '';
    if (!$cssDone) {
        $cssDone = true;
        $css = '<style>
.cbu-strip{margin:0 0 .75rem;padding:.65rem .85rem;border-radius:14px;border:1px solid rgba(245,158,11,.45);background:linear-gradient(135deg,rgba(245,158,11,.14),rgba(245,158,11,.05))}
.cbu-head{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.cbu-ico{width:26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#f59e0b;flex:none}
.cbu-ico svg{width:15px;height:15px;stroke:#fff!important;fill:none!important;stroke-width:2.4}
.cbu-ttl{font-size:.82rem;font-weight:800}
.cbu-sub{font-size:.72rem;flex:1;min-width:200px}
.cbu-link{font-size:.72rem;font-weight:700;padding:.3rem .7rem;border-radius:8px;text-decoration:none;background:#f59e0b;white-space:nowrap}
.cbu-chips{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.55rem}
.cbu-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .6rem;border-radius:999px;font-size:.72rem;border:1px dashed rgba(180,83,9,.55);background:rgba(255,255,255,.55)}
.cbu-chip i,.cbu-chip em,.cbu-chip u{font-style:normal;text-decoration:none;opacity:.9}
.cbu-chip em::before,.cbu-chip u::before{content:"·";margin-right:.4rem}
.cbu-more{align-self:center;font-size:.72rem;font-weight:700}
html body .cbu-strip .cbu-ttl,html body .cbu-strip .cbu-chip,html body .cbu-strip .cbu-chip *,html body .cbu-strip .cbu-more{color:#7c2d12!important;-webkit-text-fill-color:#7c2d12!important}
html body .cbu-strip .cbu-sub{color:#92400e!important;-webkit-text-fill-color:#92400e!important}
html body .cbu-strip a.cbu-link{color:#fff!important;-webkit-text-fill-color:#fff!important}
html body[data-theme="dark"] .cbu-strip{background:linear-gradient(135deg,rgba(245,158,11,.18),rgba(245,158,11,.06))}
html body[data-theme="dark"] .cbu-strip .cbu-ttl,html body[data-theme="dark"] .cbu-strip .cbu-chip,html body[data-theme="dark"] .cbu-strip .cbu-chip *,html body[data-theme="dark"] .cbu-strip .cbu-more{color:#fde68a!important;-webkit-text-fill-color:#fde68a!important}
html body[data-theme="dark"] .cbu-strip .cbu-sub{color:#fcd34d!important;-webkit-text-fill-color:#fcd34d!important}
html body[data-theme="dark"] .cbu-chip{background:rgba(0,0,0,.25)}
.fd-btn .cbu-badge,.cbu-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;margin-left:6px;border-radius:999px;font-size:.66rem;font-weight:800;background:#f59e0b;color:#fff!important;-webkit-text-fill-color:#fff!important}
</style>';
    }
    return $css . '<div class="cbu-strip" role="status">'
        . '<div class="cbu-head">'
        . '<span class="cbu-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>'
        . '<span class="cbu-ttl"><b>' . count($rows) . '</b> reservasi Cloudbeds belum ditempatkan kamar</span>'
        . '<span class="cbu-sub">Belum masuk ADF — kamarnya bisa terjual dua kali. Tempatkan nomor kamar di Cloudbeds, lalu reservasi otomatis masuk.</span>'
        . '<a class="cbu-link" href="' . $esc($href) . '">Pratinjau Cloudbeds</a>'
        . '</div><div class="cbu-chips">' . $chips . $more . '</div></div>';
}

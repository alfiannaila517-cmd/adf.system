<?php
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }
/**
 * Widget Front Desk "hari ini": Reservasi Masuk Hari Ini + Okupansi Hari Ini + Okupansi 7 Hari.
 * Dipakai di Dashboard Front Desk dan dashboard utama (bisnis hotel). Satu widget per halaman (id #fdt).
 */

/** Data widget. Melempar exception bila tabel hotel tidak ada (pemanggil menangkap). */
function fdt_data($db): array
{
    $today = date('Y-m-d');
    $d = [];
    $d['total_rooms'] = max(1, (int)($db->fetchOne("SELECT COUNT(*) c FROM rooms")['c'] ?? 0));
    $d['occupied_rooms'] = (int)($db->fetchOne("SELECT COUNT(DISTINCT room_id) c FROM bookings WHERE status = 'checked_in'")['c'] ?? 0);
    $d['occupancy_rate'] = round($d['occupied_rooms'] / $d['total_rooms'] * 100, 1);
    try {
        $d['blocked_rooms'] = (int)($db->fetchOne("SELECT COUNT(DISTINCT room_id) c FROM room_blocks WHERE status = 'active' AND block_start_date <= ? AND block_end_date > ?", [$today, $today])['c'] ?? 0);
    } catch (\Throwable $e) {
        $d['blocked_rooms'] = 0;
    }
    $d['vacant_rooms'] = max(0, $d['total_rooms'] - $d['occupied_rooms'] - $d['blocked_rooms']);
    $d['arrivals_tomorrow'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM bookings WHERE DATE(check_in_date) = ? AND status IN ('confirmed','pending')", [date('Y-m-d', strtotime('+1 day'))])['c'] ?? 0);

    // Reservasi masuk hari ini (dibuat hari ini, semua sumber), grup digabung
    $sql = function (bool $withSources, bool $withCb) {
        return "SELECT b.id, b.group_id, b.status, b.booking_source, b.created_at,
                   DATE(b.check_in_date) ci, DATE(b.check_out_date) co, b.total_nights, b.final_price, b.paid_amount,
                   g.guest_name, r.room_number"
            . ($withSources ? ", bs.source_name, bs.source_type" : ", NULL AS source_name, NULL AS source_type")
            . ($withCb ? ", (SELECT l.how FROM cloudbeds_booking_links l WHERE l.booking_id = b.id LIMIT 1) AS cb_how" : ", NULL AS cb_how") . "
            FROM bookings b
            LEFT JOIN guests g ON g.id = b.guest_id
            LEFT JOIN rooms r ON r.id = b.room_id"
            . ($withSources ? " LEFT JOIN booking_sources bs ON bs.source_key = b.booking_source" : "") . "
            WHERE DATE(b.created_at) = ?
            ORDER BY b.created_at DESC, b.id DESC LIMIT 60";
    };
    $rows = [];
    foreach ([[true, true], [true, false], [false, false]] as [$ws, $wc]) {
        try {
            $rows = $db->fetchAll($sql($ws, $wc), [$today]) ?: [];
            break;
        } catch (\Throwable $e) {
            // booking_sources / cloudbeds_booking_links belum ada → versi lebih sederhana
        }
    }
    $list = [];
    foreach ($rows as $r) {
        $k = $r['group_id'] ? 'g:' . $r['group_id'] : 'b:' . $r['id'];
        if (!isset($list[$k])) {
            $list[$k] = $r + ['rooms' => [], 'total' => 0.0, 'paid' => 0.0, 'cancel_total' => 0.0, 'n_rooms' => 0, 'all_cancelled' => true];
        }
        $list[$k]['rooms'][] = $r['room_number'] ?: '-';
        $list[$k]['n_rooms']++;
        if ($r['status'] === 'cancelled') {
            $list[$k]['cancel_total'] += (float)$r['final_price'];
        } else {
            $list[$k]['total'] += (float)$r['final_price'];
            $list[$k]['paid'] += (float)$r['paid_amount'];
            $list[$k]['all_cancelled'] = false;
        }
    }
    $d['new_today'] = array_values($list);
    $live = array_filter($d['new_today'], fn($x) => !$x['all_cancelled']);
    $d['new_today_count'] = count($live);
    $d['new_today_value'] = array_sum(array_column($live, 'total'));
    $d['new_today_nights'] = array_sum(array_map(fn($x) => (int)$x['total_nights'] * $x['n_rooms'], $live));
    $d['cancelled_today'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM bookings WHERE status = 'cancelled' AND DATE(updated_at) = ?", [$today])['c'] ?? 0);

    // Prakiraan 7 hari: kamar terpesan per malam
    $fc = $db->fetchAll("SELECT room_id, DATE(check_in_date) ci, DATE(check_out_date) co, status FROM bookings
        WHERE status IN ('pending','confirmed','checked_in') AND DATE(check_in_date) < ? AND (DATE(check_out_date) > ? OR status = 'checked_in')",
        [date('Y-m-d', strtotime('+7 days')), $today]) ?: [];
    $d['forecast'] = [];
    for ($i = 0; $i < 7; $i++) {
        $day = date('Y-m-d', strtotime("+$i days"));
        $rooms = [];
        $arr = 0;
        foreach ($fc as $f) {
            // Tamu in-house yang lewat tanggal check-out tetap menempati kamar hari ini
            $co = ($f['status'] === 'checked_in' && $f['co'] <= $today) ? date('Y-m-d', strtotime($today . ' +1 day')) : $f['co'];
            if ($f['ci'] <= $day && $co > $day) $rooms[(int)$f['room_id']] = true;
            if ($f['ci'] === $day) $arr++;
        }
        $d['forecast'][] = ['date' => $day, 'rooms' => count($rooms), 'arrivals' => $arr, 'pct' => (int)round(count($rooms) / $d['total_rooms'] * 100)];
    }
    return $d;
}

/**
 * Tampilkan widget. $opt: title (judul bagian di atas widget, opsional), link (URL "Buka Front Desk").
 * Chart.js boleh dimuat sebelum atau sesudah widget.
 */
function fdt_render(array $d, array $opt = []): void
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $date = function ($v) use ($bulan) {
        $t = strtotime((string)$v);
        return $t ? date('j', $t) . ' ' . $bulan[(int)date('n', $t)] : '-';
    };
    $rp = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
    $isOta = fn($type, $src) => $type ? $type !== 'direct' : (bool)preg_match('/agoda|booking|tiket|traveloka|airbnb|expedia|pegipegi|ota/i', (string)$src);
    $srcName = fn($name, $key) => $name ?: ucwords(str_replace('_', ' ', (string)($key ?: 'Direct')));
    $initials = function ($name) {
        $w = preg_split('/\s+/', trim((string)$name)) ?: [];
        return strtoupper(substr($w[0] ?? '?', 0, 1) . substr($w[1] ?? '', 0, 1)) ?: '?';
    };
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<style>
    /* #fdt + !important: style.css tema terang memaksa warna teks span/div/td */
    #fdt {
        --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e8edf3; --soft: #f8fafc;
        --card: #ffffff; --brand: #1e3a8a; --accent: #2563eb;
        --ok: #16a34a; --ok-bg: #dcfce7; --warn: #b45309; --warn-bg: #fef3c7; --bad: #dc2626; --bad-bg: #fee2e2;
        --ota: #6d28d9; --ota-bg: #f5f3ff; --shadow: 0 1px 2px rgba(15,23,42,.04), 0 6px 18px -12px rgba(15,23,42,.16);
        color: var(--ink); margin-bottom: 12px;
    }
    body[data-theme="dark"] #fdt {
        --ink: #f1f5f9; --mute: #94a3b8; --faint: #64748b; --line: rgba(148,163,184,.16); --soft: rgba(255,255,255,.03);
        --card: rgba(30,41,59,.72); --brand: #93c5fd; --accent: #60a5fa;
        --ok: #4ade80; --ok-bg: rgba(34,197,94,.14); --warn: #fbbf24; --warn-bg: rgba(245,158,11,.14); --bad: #f87171; --bad-bg: rgba(239,68,68,.14);
        --ota: #c4b5fd; --ota-bg: rgba(139,92,246,.14); --shadow: 0 12px 28px -16px rgba(0,0,0,.7);
    }
    #fdt :is(span, div, li, a, b, small, i) { color: inherit !important; -webkit-text-fill-color: currentColor; }
    #fdt *, #fdt *::before, #fdt *::after { box-sizing: border-box; }
    #fdt a { text-decoration: none; }
    #fdt .t-sec { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 2px 2px 10px; }
    #fdt .t-sec b { font-size: 0.92rem !important; font-weight: 800 !important; color: var(--ink) !important; }
    #fdt .t-sec small { display: block; font-size: 0.68rem !important; color: var(--mute) !important; margin-top: 1px; }
    #fdt .t-open { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 12px; border-radius: 9px; border: 1px solid var(--line); background: var(--card); font-size: 0.72rem !important; font-weight: 700; color: var(--accent) !important; }
    #fdt .t-open:hover { border-color: var(--accent); }
    #fdt .t-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr); gap: 12px; align-items: start; }
    #fdt .t-side { display: flex; flex-direction: column; gap: 12px; }
    #fdt .t-card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); }
    #fdt .t-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px 8px; flex-wrap: wrap; }
    #fdt .t-title { display: flex; align-items: center; gap: 9px; }
    #fdt .t-title b { display: block; font-size: 0.84rem !important; font-weight: 800 !important; color: var(--ink) !important; }
    #fdt .t-title small { display: block; font-size: 0.66rem !important; color: var(--mute) !important; margin-top: 1px; }
    #fdt .t-ic { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; flex-shrink: 0; }
    #fdt .t-ic svg { width: 15px; height: 15px; }
    #fdt .ic-blue { background: #dbeafe; color: #1d4ed8 !important; }
    #fdt .ic-green { background: var(--ok-bg); color: var(--ok) !important; }
    #fdt .ic-violet { background: var(--ota-bg); color: var(--ota) !important; }
    body[data-theme="dark"] #fdt .ic-blue { background: rgba(59,130,246,.16); color: #93c5fd !important; }

    #fdt .t-list { list-style: none; margin: 0; padding: 0 6px 6px; max-height: 420px; overflow-y: auto; }
    #fdt .t-row { display: grid; grid-template-columns: 38px 32px minmax(0, 1fr) auto; align-items: center; gap: 10px; padding: 9px 8px; border-top: 1px solid var(--line); }
    #fdt .t-row:first-child { border-top: 0; }
    #fdt .t-row:hover { background: var(--soft); border-radius: 10px; }
    #fdt .t-time { font-size: 0.66rem !important; font-weight: 700; color: var(--faint) !important; font-variant-numeric: tabular-nums; }
    #fdt .t-av { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-size: 0.66rem !important; font-weight: 800; background: #e0e7ff; color: #3730a3 !important; }
    #fdt .t-av.ota { background: var(--ota-bg); color: var(--ota) !important; }
    body[data-theme="dark"] #fdt .t-av { background: rgba(99,102,241,.18); color: #c7d2fe !important; }
    #fdt .t-main { min-width: 0; }
    #fdt .t-name { font-size: 0.8rem !important; font-weight: 700; color: var(--ink) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #fdt .t-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px; color: var(--mute) !important; }
    #fdt .t-meta span { font-size: 0.68rem !important; }
    #fdt .t-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
    #fdt .t-amt { font-size: 0.8rem !important; font-weight: 800; color: var(--ink) !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
    #fdt .t-row.cancel .t-name, #fdt .t-row.cancel .t-amt { text-decoration: line-through; color: var(--faint) !important; }
    #fdt .t-room { display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 5px; background: var(--brand); color: #fff !important; font-size: 0.66rem !important; font-weight: 800; }
    body[data-theme="dark"] #fdt .t-room { background: #1d4ed8; }
    #fdt .t-chip { display: inline-flex; align-items: center; gap: 3px; padding: 1px 8px; border-radius: 999px; font-size: 0.64rem !important; font-weight: 700; white-space: nowrap; border: 1px solid transparent; }
    #fdt .t-chip.ota { background: var(--ota-bg); color: var(--ota) !important; border-color: rgba(139,92,246,.25); }
    #fdt .t-chip.dir { background: var(--soft); color: var(--mute) !important; border-color: var(--line); }
    #fdt .t-chip.ok { background: var(--ok-bg); color: var(--ok) !important; }
    #fdt .t-chip.warn { background: var(--warn-bg); color: var(--warn) !important; }
    #fdt .t-chip.bad { background: var(--bad-bg); color: var(--bad) !important; }
    #fdt .t-chip.cb { background: #ecfeff; color: #0e7490 !important; border-color: #a5f3fc; }
    body[data-theme="dark"] #fdt .t-chip.cb { background: rgba(6,182,212,.12); color: #67e8f9 !important; border-color: rgba(6,182,212,.3); }
    #fdt .t-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--faint); display: inline-block; }
    #fdt .t-sum { display: flex; gap: 5px; flex-wrap: wrap; }
    #fdt .t-empty { padding: 26px 12px 30px; text-align: center; color: var(--mute) !important; font-size: 0.74rem !important; }
    #fdt .t-empty svg { width: 28px; height: 28px; color: var(--faint) !important; margin-bottom: 6px; }

    #fdt .t-occ { display: grid; grid-template-columns: 116px 1fr; gap: 16px; align-items: center; padding: 2px 14px 14px; }
    #fdt .t-ring { position: relative; width: 116px; height: 116px; }
    #fdt .t-ring canvas { width: 116px !important; height: 116px !important; }
    #fdt .t-ring-c { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
    #fdt .t-ring-c b { font-size: 1.2rem !important; font-weight: 800 !important; color: var(--ink) !important; line-height: 1; }
    #fdt .t-ring-c small { font-size: 0.62rem !important; color: var(--mute) !important; margin-top: 2px; }
    #fdt .t-legend { display: flex; flex-direction: column; gap: 8px; }
    #fdt .t-legend div { display: flex; align-items: center; gap: 8px; font-size: 0.74rem !important; color: var(--mute) !important; }
    #fdt .t-legend i { width: 9px; height: 9px; border-radius: 2px; flex-shrink: 0; }
    #fdt .t-legend b { margin-left: auto; color: var(--ink) !important; font-weight: 800; font-variant-numeric: tabular-nums; }

    #fdt .t-fc { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; padding: 2px 14px 14px; }
    #fdt .t-fc-col { display: flex; flex-direction: column; align-items: center; gap: 5px; }
    #fdt .t-fc-pct { font-size: 0.64rem !important; font-weight: 800; color: var(--ink) !important; font-variant-numeric: tabular-nums; }
    #fdt .t-fc-track { width: 100%; max-width: 26px; height: 78px; border-radius: 7px; background: var(--soft); border: 1px solid var(--line); display: flex; align-items: flex-end; overflow: hidden; }
    #fdt .t-fc-fill { width: 100%; border-radius: 5px 5px 0 0; background: linear-gradient(180deg, #60a5fa, #2563eb); min-height: 3px; }
    #fdt .t-fc-fill.hi { background: linear-gradient(180deg, #4ade80, #16a34a); }
    #fdt .t-fc-fill.lo { background: linear-gradient(180deg, #fcd34d, #f59e0b); }
    #fdt .t-fc-day { font-size: 0.62rem !important; font-weight: 700; color: var(--mute) !important; line-height: 1.15; text-align: center; }
    #fdt .t-fc-day small { display: block; font-weight: 600; color: var(--faint) !important; font-size: 0.58rem !important; }
    #fdt .t-fc-col.today .t-fc-day { color: var(--accent) !important; }

    @media (max-width: 1180px) {
        #fdt .t-grid { grid-template-columns: minmax(0, 1fr); }
        #fdt .t-side { display: grid; grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 860px) {
        #fdt .t-side { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 560px) {
        #fdt .t-row { grid-template-columns: 32px minmax(0, 1fr); }
        #fdt .t-row .t-time { display: none; }
        #fdt .t-right { grid-column: 2; flex-direction: row; align-items: center; gap: 6px; }
        #fdt .t-occ { grid-template-columns: 1fr; justify-items: center; }
        #fdt .t-legend { width: 100%; }
    }
</style>
<div id="fdt">
    <?php if (!empty($opt['title'])): ?>
        <div class="t-sec">
            <div>
                <b><?php echo $h($opt['title']); ?></b>
                <small><?php echo $hari[(int)date('w')] . ', ' . date('j') . ' ' . $bulan[(int)date('n')] . ' ' . date('Y'); ?> · reservasi masuk &amp; okupansi</small>
            </div>
            <?php if (!empty($opt['link'])): ?><a class="t-open" href="<?php echo $h($opt['link']); ?>">Buka Front Desk →</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="t-grid">
        <!-- Reservasi masuk hari ini -->
        <div class="t-card">
            <div class="t-head">
                <div class="t-title">
                    <span class="t-ic ic-violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                    <div>
                        <b>Reservasi Masuk Hari Ini</b>
                        <small>Semua booking yang dibuat hari ini, dari sistem maupun Cloudbeds/OTA</small>
                    </div>
                </div>
                <div class="t-sum">
                    <span class="t-chip ok"><?php echo (int)$d['new_today_count']; ?> masuk · <?php echo $rp($d['new_today_value']); ?></span>
                    <?php if ($d['cancelled_today']): ?><span class="t-chip bad"><?php echo (int)$d['cancelled_today']; ?> batal</span><?php endif; ?>
                </div>
            </div>
            <?php if (!empty($d['new_today'])): ?>
                <ul class="t-list">
                    <?php foreach ($d['new_today'] as $nr):
                        $ota = $isOta($nr['source_type'], $nr['booking_source']);
                        $cancel = $nr['all_cancelled'];
                        $rooms = array_slice(array_unique($nr['rooms']), 0, 4);
                        $balance = max(0, $nr['total'] - $nr['paid']);
                    ?>
                        <li class="t-row<?php echo $cancel ? ' cancel' : ''; ?>">
                            <span class="t-time"><?php echo date('H:i', strtotime($nr['created_at'])); ?></span>
                            <span class="t-av<?php echo $ota ? ' ota' : ''; ?>"><?php echo $h($initials($nr['guest_name'])); ?></span>
                            <div class="t-main">
                                <div class="t-name"><?php echo $h($nr['guest_name'] ?: 'Tamu'); ?></div>
                                <div class="t-meta">
                                    <?php foreach ($rooms as $rm): ?><span class="t-room"><?php echo $h($rm); ?></span><?php endforeach; ?>
                                    <?php if ($nr['n_rooms'] > count($rooms)): ?><span>+<?php echo $nr['n_rooms'] - count($rooms); ?></span><?php endif; ?>
                                    <span><?php echo $date($nr['ci']) . ' → ' . $date($nr['co']); ?></span>
                                    <span class="t-dot"></span>
                                    <span><?php echo (int)$nr['total_nights']; ?> malam</span>
                                    <span class="t-chip <?php echo $ota ? 'ota' : 'dir'; ?>"><?php echo $h($srcName($nr['source_name'], $nr['booking_source'])); ?></span>
                                    <?php if ($nr['cb_how'] === 'push'): ?><span class="t-chip cb" title="Sudah dikirim ke Cloudbeds">✓ Cloudbeds</span>
                                    <?php elseif ($nr['cb_how']): ?><span class="t-chip cb" title="Masuk dari Cloudbeds">via Cloudbeds</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="t-right">
                                <div class="t-amt"><?php echo $rp($cancel ? $nr['cancel_total'] : $nr['total']); ?></div>
                                <?php if ($cancel): ?>
                                    <span class="t-chip bad">Dibatalkan</span>
                                <?php elseif ($balance <= 0 && $nr['total'] > 0): ?>
                                    <span class="t-chip ok">Lunas</span>
                                <?php elseif ($nr['paid'] > 0): ?>
                                    <span class="t-chip warn">DP · sisa <?php echo $rp($balance); ?></span>
                                <?php else: ?>
                                    <span class="t-chip <?php echo $ota ? 'dir' : 'warn'; ?>"><?php echo $ota ? 'Bayar via OTA/hotel' : 'Belum bayar'; ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="t-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <div>Belum ada reservasi masuk hari ini.</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="t-side">
            <!-- Okupansi hari ini -->
            <div class="t-card">
                <div class="t-head">
                    <div class="t-title">
                        <span class="t-ic ic-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.2 15.9A10 10 0 1 1 8 2.8"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></span>
                        <div>
                            <b>Okupansi Hari Ini</b>
                            <small><?php echo (int)$d['total_rooms']; ?> kamar</small>
                        </div>
                    </div>
                </div>
                <div class="t-occ">
                    <div class="t-ring">
                        <canvas id="fdtOccChart" width="116" height="116"></canvas>
                        <div class="t-ring-c"><b><?php echo $d['occupancy_rate']; ?>%</b><small>terisi</small></div>
                    </div>
                    <div class="t-legend">
                        <div><i style="background:#2563eb"></i>Terisi <b><?php echo (int)$d['occupied_rooms']; ?></b></div>
                        <div><i style="background:#cbd5e1"></i>Kosong <b><?php echo (int)$d['vacant_rooms']; ?></b></div>
                        <div><i style="background:#f59e0b"></i>Diblok <b><?php echo (int)$d['blocked_rooms']; ?></b></div>
                        <div><i style="background:#8b5cf6"></i>Datang besok <b><?php echo (int)$d['arrivals_tomorrow']; ?></b></div>
                    </div>
                </div>
            </div>

            <!-- Okupansi 7 hari -->
            <div class="t-card">
                <div class="t-head">
                    <div class="t-title">
                        <span class="t-ic ic-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16v-4M12 16V8M17 16v-7"/></svg></span>
                        <div>
                            <b>Okupansi 7 Hari</b>
                            <small>Kamar terpesan per malam</small>
                        </div>
                    </div>
                </div>
                <div class="t-fc">
                    <?php foreach ($d['forecast'] as $i => $fc):
                        $lvl = $fc['pct'] >= 80 ? 'hi' : ($fc['pct'] < 40 ? 'lo' : '');
                        $t = strtotime($fc['date']);
                    ?>
                        <div class="t-fc-col<?php echo $i === 0 ? ' today' : ''; ?>" title="<?php echo $fc['rooms'] . ' kamar terpesan · ' . $fc['arrivals'] . ' kedatangan'; ?>">
                            <span class="t-fc-pct"><?php echo $fc['pct']; ?>%</span>
                            <div class="t-fc-track"><div class="t-fc-fill <?php echo $lvl; ?>" style="height:<?php echo max(3, min(100, $fc['pct'])); ?>%"></div></div>
                            <span class="t-fc-day"><?php echo $i === 0 ? 'Hari ini' : mb_substr($hari[(int)date('w', $t)], 0, 3); ?><small><?php echo date('j', $t) . ' ' . $bulan[(int)date('n', $t)]; ?></small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    (function() {
        // Cincin okupansi (Chart.js bisa dimuat sesudah widget → tunggu halaman selesai dimuat)
        function draw() {
            var el = document.getElementById('fdtOccChart');
            if (!el || !window.Chart || el.dataset.drawn) return;
            el.dataset.drawn = '1';
            var dark = document.body.getAttribute('data-theme') === 'dark';
            new Chart(el, {
                type: 'doughnut',
                data: {
                    labels: ['Terisi', 'Kosong', 'Diblok'],
                    datasets: [{
                        data: [<?php echo (int)$d['occupied_rooms']; ?>, <?php echo (int)$d['vacant_rooms']; ?>, <?php echo (int)$d['blocked_rooms']; ?>],
                        backgroundColor: ['#2563eb', dark ? 'rgba(148,163,184,.25)' : '#e2e8f0', '#f59e0b'],
                        borderWidth: 0,
                        borderRadius: 4,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: false,
                    cutout: '76%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8, callbacks: { label: function(c) { return ' ' + c.label + ': ' + c.parsed + ' kamar'; } } }
                    },
                    animation: { duration: 700, easing: 'easeOutQuart' }
                }
            });
        }
        if (document.readyState === 'complete') draw();
        else window.addEventListener('load', draw);
    })();
</script>
<?php
}

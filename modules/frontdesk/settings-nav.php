<?php
/**
 * Menu ikon Pengaturan Front Desk (dipakai settings.php, whatsapp.php, cloudbeds.php).
 * Set $sxActiveKey sebelum include: rooms | breakfast_menu | ota_fees | invoice_settings | whatsapp | cloudbeds
 */
$sxActiveKey = $sxActiveKey ?? '';
$sxItems = [
    ['key' => 'rooms', 'link' => 'settings.php?tab=rooms', 'title' => 'Room & Tipe', 'sub' => 'Kamar, tipe & harga dasar', 'a' => '#6366f1', 'b' => '#4338ca',
        'icon' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>'],
    ['key' => 'breakfast_menu', 'link' => 'settings.php?tab=breakfast_menu', 'title' => 'Breakfast Menu', 'sub' => 'Menu & portal sarapan', 'a' => '#f59e0b', 'b' => '#b45309',
        'icon' => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'],
    ['key' => 'ota_fees', 'link' => 'settings.php?tab=ota_fees', 'title' => 'OTA Fees', 'sub' => 'Sumber booking & komisi', 'a' => '#10b981', 'b' => '#047857',
        'icon' => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>'],
    ['key' => 'invoice_settings', 'link' => 'settings.php?tab=invoice_settings', 'title' => 'Invoice Settings', 'sub' => 'Format & identitas invoice', 'a' => '#ec4899', 'b' => '#be185d',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
    ['key' => 'whatsapp', 'link' => 'whatsapp.php', 'title' => 'WhatsApp', 'sub' => 'Pesan otomatis ke tamu', 'a' => '#22c55e', 'b' => '#15803d',
        'icon' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'],
    ['key' => 'cloudbeds', 'link' => 'cloudbeds.php', 'title' => 'Cloudbeds', 'sub' => 'Sinkron channel manager', 'a' => '#8b5cf6', 'b' => '#6d28d9',
        'icon' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'],
];
?>
<style>
    .sx-menu { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0 0 1.5rem; }
    .sx-menu .sx-tile { position: relative; display: flex; flex-direction: column; gap: 10px; padding: 14px 14px 13px; text-decoration: none !important; color: var(--text-primary, #1e293b) !important;
        background: var(--bg-secondary, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 16px; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease; }
    .sx-menu .sx-tile:hover { transform: translateY(-2px); border-color: rgba(99, 102, 241, .55); box-shadow: 0 10px 24px rgba(15, 23, 42, .18); }
    .sx-menu .sx-ico { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, var(--sx-a, #6366f1), var(--sx-b, #4338ca)); box-shadow: 0 6px 14px rgba(0, 0, 0, .22); }
    /* Ikon selalu putih (menimpa warna global tema) */
    .sx-menu .sx-ico svg { width: 20px; height: 20px; fill: none !important; stroke: #fff !important; color: #fff !important; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .sx-menu .sx-ico svg * { stroke: #fff !important; fill: none !important; }
    .sx-menu .sx-txt strong { display: block; font-size: .92rem; font-weight: 700; line-height: 1.2; color: var(--text-primary, #1e293b) !important; }
    .sx-menu .sx-txt small { display: block; margin-top: 3px; font-size: .74rem; color: var(--text-secondary, #64748b) !important; line-height: 1.3; }
    .sx-menu .sx-tile.is-active { border-color: var(--primary, #6366f1); background: linear-gradient(180deg, rgba(99, 102, 241, .14), rgba(99, 102, 241, .04)); box-shadow: 0 0 0 1px var(--primary, #6366f1) inset, 0 10px 24px rgba(99, 102, 241, .18); }
    .sx-menu .sx-tile.is-active::after { content: ''; position: absolute; left: 50%; bottom: -1px; width: 34px; height: 3px; transform: translateX(-50%); border-radius: 3px 3px 0 0; background: var(--primary, #6366f1); }
    @media (max-width: 700px) {
        .sx-menu { grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .sx-menu .sx-tile { flex-direction: row; align-items: center; padding: 10px 12px; gap: 10px; }
        .sx-menu .sx-ico { width: 36px; height: 36px; border-radius: 10px; flex: none; }
        .sx-menu .sx-txt small { display: none; }
    }
</style>
<nav class="sx-menu" aria-label="Menu pengaturan">
    <?php foreach ($sxItems as $sx): $sxActive = $sxActiveKey === $sx['key']; ?>
        <a class="sx-tile<?php echo $sxActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($sx['link']); ?>"<?php echo $sxActive ? ' aria-current="page"' : ''; ?> style="--sx-a: <?php echo $sx['a']; ?>; --sx-b: <?php echo $sx['b']; ?>;">
            <span class="sx-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $sx['icon']; ?></svg></span>
            <span class="sx-txt"><strong><?php echo htmlspecialchars($sx['title']); ?></strong><small><?php echo htmlspecialchars($sx['sub']); ?></small></span>
        </a>
    <?php endforeach; ?>
</nav>
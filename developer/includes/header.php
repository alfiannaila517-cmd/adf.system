<?php
// Sidebar Developer Panel: dikelompokkan supaya mudah dipahami.
// [file, label, ikon, file lain yang ikut menandai menu aktif]
$devCurrent = basename($_SERVER['PHP_SELF']);
$devSection = $_GET['section'] ?? '';
$devNav = [
    'Utama' => [
        ['index.php', 'Dashboard', 'speedometer2', []],
        ['businesses.php', 'Bisnis', 'building', ['debug-business-status.php']],
    ],
    'Akses & User' => [
        ['index.php?section=user-setup', 'User & Akses Bisnis', 'person-plus', []],
        ['permissions.php', 'Hak Akses Menu', 'shield-lock', []],
        ['owner-access.php', 'Akses Owner', 'eye', []],
        ['staff-accounts.php', 'Akun Staff Portal', 'person-badge', []],
        ['menus.php', 'Daftar Menu Aplikasi', 'grid-3x3-gap', []],
    ],
    'Sistem' => [
        ['developer-settings.php', 'Pengaturan & Branding', 'sliders', ['settings.php']],
        ['database.php', 'Database', 'database', []],
        ['audit.php', 'Audit Log', 'journal-text', []],
    ],
    'Khusus Narayana' => [
        ['web-settings.php', 'Website Narayana', 'globe', []],
        ['design.php', 'Design Tools', 'palette', ['design-room-image.php']],
    ],
];
$devIsActive = static function (array $item) use ($devCurrent, $devSection): bool {
    [$href, , , $also] = $item;
    if ($href === 'index.php?section=user-setup') {
        return $devCurrent === 'index.php' && $devSection === 'user-setup';
    }
    if ($href === 'index.php') {
        return $devCurrent === 'index.php' && $devSection !== 'user-setup';
    }
    return $devCurrent === $href || in_array($devCurrent, $also, true);
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Developer Panel'); ?> · ADF Developer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dev-primary: #6d4ae0;
            --dev-secondary: #8b5cf6;
            --dev-success: #10b981;
            --dev-warning: #f59e0b;
            --dev-danger: #ef4444;
            --dev-info: #3b82f6;
            --dev-dark: #1f2333;
            --dev-darker: #161925;
            --dev-light: #f8f9fb;
            --dev-border: #e8eaf0;
            --dev-muted: #6b7280;
            --sidebar-width: 228px;
        }

        * { font-family: 'Inter', sans-serif; }

        body {
            background: #f4f5f8;
            min-height: 100vh;
            font-size: 13px;
            color: var(--dev-dark);
        }

        /* ── Sidebar ─────────────────────────────── */
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: var(--sidebar-width);
            background: var(--dev-darker);
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform .2s;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 16px 16px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }

        .sidebar-header .mark {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 14px;
        }

        .sidebar-header .logo { color: #fff; font-weight: 700; font-size: 14px; line-height: 1.1; }
        .sidebar-header .logo small { display: block; color: rgba(255, 255, 255, .45); font-size: 10.5px; font-weight: 500; }

        .sidebar-menu { flex: 1; overflow-y: auto; padding: 8px 8px 12px; }
        .sidebar-menu::-webkit-scrollbar { width: 4px; }
        .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, .12); border-radius: 4px; }

        .menu-section {
            padding: 12px 10px 4px;
            color: rgba(255, 255, 255, .35);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 7px 10px;
            margin: 1px 0;
            border-radius: 7px;
            color: rgba(255, 255, 255, .68);
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 500;
        }

        .sidebar-menu a i { font-size: 14px; width: 16px; text-align: center; opacity: .85; }
        .sidebar-menu a:hover { background: rgba(255, 255, 255, .05); color: #fff; }
        .sidebar-menu a.active { background: rgba(109, 74, 224, .28); color: #fff; }
        .sidebar-menu a .ext { margin-left: auto; font-size: 11px; opacity: .5; }

        .sidebar-footer { padding: 10px 8px; border-top: 1px solid rgba(255, 255, 255, .06); }
        .sidebar-footer a {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 7px;
            color: #f87171; text-decoration: none; font-size: 12.5px; font-weight: 500;
        }
        .sidebar-footer a:hover { background: rgba(248, 113, 113, .08); }

        .sidebar-backdrop { display: none; }

        /* ── Main ─────────────────────────────── */
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }

        .top-navbar {
            background: #fff;
            height: 54px;
            padding: 0 24px;
            border-bottom: 1px solid var(--dev-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-left { display: flex; align-items: center; gap: 10px; }
        .navbar-left h4 { margin: 0; font-weight: 700; font-size: 15px; color: var(--dev-dark); }
        .nav-toggle { display: none; border: 0; background: none; font-size: 20px; padding: 0; color: var(--dev-dark); }

        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-right .store-link {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 10px;
            border: 1px solid var(--dev-border); border-radius: 7px; font-size: 12px; font-weight: 500;
            color: var(--dev-dark); text-decoration: none;
        }
        .navbar-right .store-link:hover { background: var(--dev-light); }

        .user-dropdown { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .user-avatar {
            width: 30px; height: 30px; border-radius: 8px;
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: #fff; display: grid; place-items: center; font-weight: 600; font-size: 12px;
        }
        .user-info { text-align: right; line-height: 1.2; }
        .user-info .name { font-weight: 600; font-size: 12px; color: var(--dev-dark); }
        .user-info .role { font-size: 10.5px; color: var(--dev-muted); }

        /* Isi halaman: padding seragam di semua halaman */
        .main-content > .container-fluid,
        .main-content > .p-4,
        .main-content > .content-wrapper { padding: 20px 24px !important; }

        /* ── Kartu & statistik ─────────────────────────────── */
        .welcome-card {
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            border-radius: 12px;
            padding: 18px 22px;
            color: #fff;
        }
        .welcome-card h2 { font-weight: 700; font-size: 18px; }
        .welcome-card p, .welcome-card .text-muted { color: rgba(255, 255, 255, .8) !important; font-size: 12.5px; }

        .stat-card {
            background: #fff;
            border: 1px solid var(--dev-border);
            border-radius: 12px;
            padding: 14px 16px;
            position: relative;
            height: 100%;
        }
        .stat-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: grid; place-items: center; font-size: 15px; margin-bottom: 8px;
        }
        .stat-users .stat-icon { background: rgba(109, 74, 224, .12); color: var(--dev-primary); }
        .stat-businesses .stat-icon { background: rgba(59, 130, 246, .12); color: var(--dev-info); }
        .stat-active .stat-icon { background: rgba(16, 185, 129, .12); color: var(--dev-success); }
        .stat-menus .stat-icon { background: rgba(245, 158, 11, .12); color: var(--dev-warning); }
        .stat-info h3 { font-size: 20px; font-weight: 700; margin-bottom: 2px; color: var(--dev-dark); }
        .stat-info p { color: var(--dev-muted); margin: 0; font-size: 11.5px; }
        .stat-link { position: absolute; bottom: 12px; right: 14px; font-size: 11.5px; color: var(--dev-primary); text-decoration: none; font-weight: 500; }

        .content-card { background: #fff; border: 1px solid var(--dev-border); border-radius: 12px; overflow: hidden; }
        .card-header-custom {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 16px; border-bottom: 1px solid var(--dev-border);
        }
        .card-header-custom h5 { margin: 0; font-weight: 600; font-size: 13.5px; color: var(--dev-dark); }

        .card { border: 1px solid var(--dev-border); border-radius: 12px; }
        .card-header { background: #fff; border-bottom: 1px solid var(--dev-border); padding: 10px 16px; font-size: 13px; }
        .card-body { padding: 14px 16px; }
        .card-title { font-size: 14px; }

        /* ── Tabel ─────────────────────────────── */
        .table { margin: 0; font-size: 12.5px; }
        .table th {
            background: var(--dev-light); font-weight: 600; font-size: 10.5px; color: var(--dev-muted);
            text-transform: uppercase; letter-spacing: .04em; padding: 8px 12px; border: none; white-space: nowrap;
        }
        .table td { padding: 8px 12px; vertical-align: middle; border-color: #f0f1f5; }
        .table-sm th, .table-sm td { padding: 6px 10px; }

        /* ── Quick actions & daftar ─────────────────────────────── */
        .quick-actions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 12px; }
        .quick-action-btn {
            display: flex; align-items: center; gap: 8px; padding: 10px 12px;
            background: var(--dev-light); border: 1px solid var(--dev-border); border-radius: 9px;
            text-decoration: none; color: var(--dev-dark); font-size: 12px; font-weight: 500;
        }
        .quick-action-btn:hover { border-color: var(--dev-primary); color: var(--dev-primary); }
        .quick-action-btn i { font-size: 15px; margin: 0; }

        .recent-list { padding: 4px 0; }
        .recent-item { display: flex; align-items: center; padding: 8px 16px; }
        .recent-item:hover { background: var(--dev-light); }
        .recent-avatar {
            width: 30px; height: 30px; border-radius: 8px;
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: #fff; display: grid; place-items: center; font-weight: 600; font-size: 12px; margin-right: 10px;
        }
        .recent-info { flex: 1; }
        .recent-info strong { display: block; font-size: 12.5px; color: var(--dev-dark); }
        .recent-info small { color: var(--dev-muted); font-size: 11px; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .status-dot.active { background: var(--dev-success); }
        .status-dot.inactive { background: var(--dev-danger); }

        /* ── Form & tombol (compact) ─────────────────────────────── */
        .form-label, .form-label-sm { font-weight: 500; font-size: 12px; color: var(--dev-dark); margin-bottom: 4px; }
        .form-control, .form-select { border-radius: 7px; padding: 6px 10px; font-size: 12.5px; border: 1px solid #dfe2ea; }
        .form-control-sm, .form-select-sm { font-size: 12px; padding: 4px 8px; height: 30px; }
        .form-control:focus, .form-select:focus { border-color: var(--dev-primary); box-shadow: 0 0 0 3px rgba(109, 74, 224, .12); }

        .btn { border-radius: 7px; padding: 6px 12px; font-size: 12.5px; font-weight: 500; }
        .btn-sm, .btn-group-sm .btn { padding: 3px 9px; font-size: 11.5px; }
        .btn-lg { padding: 8px 16px; font-size: 13.5px; }
        .btn-primary { background: var(--dev-primary); border-color: var(--dev-primary); }
        .btn-primary:hover { background: var(--dev-secondary); border-color: var(--dev-secondary); }
        .btn-success { background: var(--dev-success); border-color: var(--dev-success); }
        .badge { font-weight: 600; font-size: 10.5px; }
        .alert { padding: 9px 14px; font-size: 12.5px; border-radius: 9px; }
        h1, .h1 { font-size: 20px; } h2, .h2 { font-size: 18px; } h3, .h3 { font-size: 16px; }
        h4, .h4 { font-size: 15px; } h5, .h5 { font-size: 13.5px; } h6, .h6 { font-size: 12.5px; }

        /* ── HP / tablet: sidebar jadi laci ─────────────────────────────── */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .sidebar.show + .sidebar-backdrop { display: block; position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1030; }
            .main-content { margin-left: 0; }
            .nav-toggle { display: inline-block; }
            .top-navbar { padding: 0 14px; }
            .user-info, .navbar-right .store-link span { display: none; }
            .main-content > .container-fluid,
            .main-content > .p-4,
            .main-content > .content-wrapper { padding: 14px !important; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="devSidebar">
        <div class="sidebar-header">
            <div class="mark"><i class="bi bi-code-slash"></i></div>
            <div class="logo">ADF System<small>Developer Panel</small></div>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($devNav as $group => $items): ?>
                <div class="menu-section"><?php echo htmlspecialchars($group); ?></div>
                <?php foreach ($items as $item): ?>
                    <a href="<?php echo htmlspecialchars($item[0]); ?>" class="<?php echo $devIsActive($item) ? 'active' : ''; ?>">
                        <i class="bi bi-<?php echo $item[2]; ?>"></i><?php echo htmlspecialchars($item[1]); ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($group === 'Utama'): ?>
                    <a href="https://adfsystem.store/admin/index.php" target="_blank" rel="noopener">
                        <i class="bi bi-shop"></i>ADF Store<i class="bi bi-box-arrow-up-right ext"></i>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
        </div>
    </aside>
    <div class="sidebar-backdrop" onclick="document.getElementById('devSidebar').classList.remove('show')"></div>

    <!-- Main Content -->
    <main class="main-content">
        <div class="top-navbar">
            <div class="navbar-left">
                <button type="button" class="nav-toggle" onclick="document.getElementById('devSidebar').classList.toggle('show')" aria-label="Menu"><i class="bi bi-list"></i></button>
                <h4><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?></h4>
            </div>
            <div class="navbar-right">
                <a href="https://adfsystem.store/admin/index.php" target="_blank" rel="noopener" class="store-link"><i class="bi bi-shop"></i><span>ADF Store</span></a>
                <div class="user-dropdown dropdown">
                    <div data-bs-toggle="dropdown" class="d-flex align-items-center gap-2">
                        <div class="user-info">
                            <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? 'Developer'); ?></div>
                            <div class="role">Developer</div>
                        </div>
                        <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'] ?? 'D', 0, 1)); ?></div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end" style="font-size:12.5px;">
                        <li><a class="dropdown-item" href="https://adfsystem.store/admin/index.php" target="_blank" rel="noopener"><i class="bi bi-shop me-2"></i>ADF Store</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

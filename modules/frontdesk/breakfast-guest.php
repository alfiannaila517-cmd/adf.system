<?php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

$previewLogoUrl = '';
try {
    $db = Database::getInstance();
    $portalLogoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", ['breakfast_portal_logo_path']);
    $portalLogoPath = $portalLogoRow['setting_value'] ?? '';
    if ($portalLogoPath) {
        $previewLogoUrl = (strpos($portalLogoPath, 'http') === 0)
            ? $portalLogoPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($portalLogoPath, '/');
    }
} catch (Throwable $e) {
    $previewLogoUrl = '';
}

$token = trim((string)($_GET['t'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Breakfast Menu Selection</title>
    <meta property="og:type" content="website">
    <meta property="og:title" content="Breakfast Menu Selection">
    <meta property="og:description" content="Please select your breakfast menu using this portal.">
    <?php if ($previewLogoUrl !== ''): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($previewLogoUrl); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($previewLogoUrl); ?>">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="Breakfast Menu Selection">
    <meta name="twitter:description" content="Please select your breakfast menu using this portal.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Manrope', 'Segoe UI', Tahoma, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(125, 211, 252, 0.35), transparent 32%),
                radial-gradient(circle at top right, rgba(147, 197, 253, 0.28), transparent 28%),
                linear-gradient(160deg, #eff6ff, #dbeafe 55%, #e0f2fe);
            color: #0f172a;
            min-height: 100vh;
            padding: 16px;
        }
        .wrap { max-width: 900px; margin: 0 auto; }
        .card {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(96, 165, 250, 0.22);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.12);
            backdrop-filter: blur(14px);
            padding: 16px;
            margin-bottom: 16px;
        }
        .header-card {
            background:
                radial-gradient(circle at 90% 8%, rgba(255, 255, 255, 0.45), transparent 30%),
                radial-gradient(circle at 8% 80%, rgba(186, 230, 253, 0.28), transparent 35%),
                linear-gradient(135deg, rgba(22, 78, 99, 0.98), rgba(3, 105, 161, 0.93) 52%, rgba(14, 116, 144, 0.9));
            color: white;
            border: none;
            border-radius: 16px;
            padding: 16px 16px 14px;
            box-shadow: 0 14px 36px rgba(12, 74, 110, 0.32);
        }
        .header-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .header-brand { min-width: 0; }
        .header-logo {
            height: 46px;
            max-width: 170px;
            object-fit: contain;
            display: none;
            background: rgba(255,255,255,0.94);
            border-radius: 10px;
            padding: 5px 8px;
            border: 1px solid rgba(255,255,255,0.45);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
        }
        .header-logo.show { display: block; }
        .portal-preview-hero {
            position: absolute;
            width: 1px;
            height: 1px;
            left: -9999px;
            top: -9999px;
            overflow: hidden;
        }
        .header-card .muted-white { color: #eff6ff; }
        h1 {
            margin: 0;
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.24rem;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: none;
        }
        .header-subtitle { font-size: 0.8rem; color: #e0f2fe; margin-top: 3px; letter-spacing: .35px; }
        .header-divider { margin-top: 8px; height: 1px; background: linear-gradient(90deg, rgba(219, 234, 254, 0.15), rgba(219, 234, 254, 0.75), rgba(219, 234, 254, 0.15)); }
        .muted { color: #475569; font-size: 0.9rem; }
        .muted-white { color: #ffffff; font-size: 0.92rem; font-weight: 500; text-shadow: 0 1px 2px rgba(15, 23, 42, 0.22); }
        .meta { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
        .meta-item { background: rgba(255,255,255,0.15); border-radius: 10px; padding: 10px; }
        .meta-item-light { background: rgba(255,255,255,0.88); border: 1px solid rgba(96, 165, 250, 0.22); border-radius: 12px; padding: 10px 12px; }
        .meta-lbl { font-size: 0.65rem; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-lbl-dark { font-size: 0.65rem; color: #1d4ed8; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-val { margin-top: 4px; font-weight: 700; font-size: 0.98rem; color: #0f172a; }
        .section-title { font-size: 1.1rem; font-weight: 800; margin: 0 0 12px; color: #1f2937; display: flex; align-items: center; gap: 8px; }
        .section-icon { font-size: 1.3rem; }
        
        /* Moka-style menu cards */
        .menu-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: stretch; }
        .menu-item {
            border: 1px solid rgba(96, 165, 250, 0.18);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.82);
            overflow: hidden;
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        .menu-item:hover { border-color: rgba(59, 130, 246, 0.55); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(59, 130, 246, 0.12); }
        .menu-item.selected { border-color: #38bdf8; background: linear-gradient(135deg, rgba(224, 242, 254, 0.92), rgba(191, 219, 254, 0.88)); }
        .menu-item.locked { cursor: default; }
        .menu-item .menu-check { display: none; }
        
        .menu-img-wrap {
            width: 100%;
            height: 176px;
            background: linear-gradient(135deg, rgba(191, 219, 254, 0.28), rgba(147, 197, 253, 0.2));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 0;
        }
        .menu-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            border-radius: 0;
        }
        .menu-img-placeholder { font-size: 2.2rem; }
        
        .menu-content { padding: 10px 11px 11px; display: flex; flex-direction: column; flex: 1; }
        .menu-name {
            font-weight: 700;
            font-size: 0.96rem;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.28;
            line-clamp: 2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .menu-desc {
            font-size: 0.76rem;
            color: #64748b;
            line-height: 1.35;
            margin-bottom: 8px;
            line-clamp: 4;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 40px;
        }
        .menu-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; }
        .menu-cat { 
            font-size: 0.65rem; 
            padding: 3px 8px; 
            background: #fef3c7; 
            color: #d97706; 
            border-radius: 20px; 
            font-weight: 600;
            text-transform: uppercase;
        }
        .menu-price { 
            font-weight: 800; 
            font-size: 1rem; 
            color: #059669; 
        }
        .menu-price.free { color: #10b981; }
        .menu-note-wrap { display: block; margin-top: 8px; }
        .menu-note-label {
            display: block;
            font-size: 0.68rem;
            color: #334155;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: .2px;
        }
        .menu-note-input {
            display: block;
            width: 100%;
            border: 1px solid rgba(148, 163, 184, 0.32);
            border-radius: 8px;
            background: rgba(255,255,255,0.92);
            color: #0f172a;
            font-size: 0.72rem;
            padding: 7px 8px;
            line-height: 1.35;
        }
        .menu-note-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.12); }
        .menu-note-input:disabled {
            background: rgba(241, 245, 249, 0.82);
            color: #94a3b8;
            cursor: not-allowed;
        }
        .menu-note-readonly { margin-top: 8px; font-size: 0.72rem; color: #475569; background: rgba(241, 245, 249, 0.8); border-radius: 8px; padding: 6px 8px; }
        
        .quota-box {
            background: linear-gradient(135deg, rgba(224, 242, 254, 0.9), rgba(191, 219, 254, 0.82));
            border: 1px solid rgba(59, 130, 246, 0.22);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .quota-label { font-size: 0.85rem; font-weight: 600; color: #1d4ed8; }
        .quota-count { font-size: 1.2rem; font-weight: 800; color: #1d4ed8; }
        .quota-extra { font-size: 0.75rem; color: #0f766e; font-weight: 600; }
        
        textarea {
            width: 100%;
            min-height: 80px;
            border: 1px solid rgba(96, 165, 250, 0.25);
            border-radius: 12px;
            padding: 12px;
            font-family: inherit;
            font-size: 0.9rem;
            background: rgba(255,255,255,0.75);
            transition: border-color 0.2s;
        }
        textarea:focus { outline: none; border-color: #38bdf8; }
        
        .actions { display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            flex: 1;
            transition: all 0.2s;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #38bdf8, #2563eb); 
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(37, 99, 235, 0.28); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-spot {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.24);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .btn-spot:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(22, 163, 74, 0.3); }
        .btn-spot:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-wa {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.24);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-wa:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(22, 163, 74, 0.3); }

        .btn-ghost {
            background: rgba(255,255,255,0.82);
            color: #0f172a;
            border: 1px solid rgba(148, 163, 184, 0.22);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        }
        .btn-ghost:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12); }

        .wa-float {
            position: fixed;
            right: 16px;
            bottom: 18px;
            z-index: 9998;
            height: 54px;
            min-width: 54px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.5);
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.96), rgba(22, 163, 74, 0.96));
            color: #fff;
            text-decoration: none;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 14px;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.2px;
            box-shadow: 0 12px 24px rgba(21, 128, 61, 0.34);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .wa-float.show { display: inline-flex; }
        .wa-float:hover { transform: translateY(-2px); box-shadow: 0 16px 28px rgba(21, 128, 61, 0.4); }
        .wa-float-icon {
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: rgba(255,255,255,0.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 900;
        }
        .wa-float-text { white-space: nowrap; }
        
        .msg { margin-top: 12px; font-size: 0.9rem; font-weight: 600; padding: 10px; border-radius: 8px; }
        .ok { background: #d1fae5; color: #059669; }
        .err { background: #fee2e2; color: #dc2626; }
        .hidden { display: none; }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }
        .field-group label {
            display: block;
            font-size: 0.74rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
        }
        .field-control {
            width: 100%;
            border: 1px solid rgba(96, 165, 250, 0.28);
            border-radius: 10px;
            padding: 10px 11px;
            background: rgba(255,255,255,0.8);
            color: #0f172a;
            font-size: 0.86rem;
        }
        select.field-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding-right: 34px;
            background-image:
                linear-gradient(45deg, transparent 50%, #1d4ed8 50%),
                linear-gradient(135deg, #1d4ed8 50%, transparent 50%);
            background-position:
                calc(100% - 18px) calc(50% - 2px),
                calc(100% - 12px) calc(50% - 2px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
        }
        .field-control:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.12);
        }
        .required-mark { color: #dc2626; margin-left: 4px; }

        .quota-popup {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }
        .quota-popup.show { display: flex; }
        .quota-modal {
            width: min(94vw, 720px);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(241,245,249,0.98));
            border: 1px solid rgba(248, 113, 113, 0.28);
            border-radius: 22px;
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.28);
            overflow: hidden;
        }
        .quota-modal-head {
            padding: 18px 18px 14px;
            background: linear-gradient(135deg, rgba(127, 29, 29, 0.98), rgba(220, 38, 38, 0.96));
            color: #fff;
        }
        .quota-modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: .2px;
        }
        .quota-modal-title .badge {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .quota-modal-body {
            padding: 18px;
            color: #0f172a;
        }
        .quota-popup-text {
            font-size: 0.95rem;
            line-height: 1.55;
            font-weight: 600;
            color: #334155;
        }
        .quota-popup-highlight {
            margin-top: 12px;
            background: rgba(254, 226, 226, 0.8);
            border: 1px solid rgba(248, 113, 113, 0.22);
            border-radius: 14px;
            padding: 14px;
            color: #991b1b;
            font-weight: 800;
            font-size: 1.08rem;
        }
        .quota-popup-note {
            margin-top: 10px;
            color: #475569;
            font-size: 0.84rem;
            line-height: 1.45;
        }
        .quota-popup-actions {
            display: flex;
            gap: 10px;
            padding: 0 18px 18px;
            flex-wrap: wrap;
        }
        .quota-popup-actions .btn {
            flex: 1;
            min-width: 140px;
        }
        .quota-popup-close {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            border: 1px solid rgba(185, 28, 28, 0.65);
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 800;
            padding: 12px 14px;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(220, 38, 38, 0.22);
        }
        .quota-popup-close:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(220, 38, 38, 0.28); }
        .quota-popup-ok {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 800;
            padding: 12px 14px;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(22, 163, 74, 0.22);
        }
        .quota-popup-ok:hover { transform: translateY(-1px); }

        .cart-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cart-empty {
            background: rgba(255,255,255,0.78);
            border: 1px dashed rgba(96, 165, 250, 0.28);
            border-radius: 12px;
            padding: 14px;
            color: #64748b;
            font-size: 0.85rem;
        }
        .cart-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            background: rgba(255,255,255,0.84);
            border: 1px solid rgba(96, 165, 250, 0.18);
            border-radius: 14px;
            padding: 12px;
        }
        .cart-main { min-width: 0; }
        .cart-name { font-weight: 800; color: #0f172a; font-size: 0.92rem; }
        .cart-meta { margin-top: 3px; color: #1d4ed8; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .2px; }
        .cart-note { margin-top: 6px; font-size: 0.78rem; color: #475569; }
        .cart-footer {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .cart-summary {
            font-size: 0.82rem;
            color: #334155;
            font-weight: 700;
        }
        .cart-summary strong { color: #0f172a; }
        
        .info-box {
            border: 1px dashed rgba(96, 165, 250, 0.5);
            border-radius: 12px;
            background: rgba(239, 246, 255, 0.8);
            padding: 14px;
            font-size: 0.9rem;
            color: #1e3a8a;
            white-space: pre-wrap;
        }
        .media-link {
            display: inline-block;
            margin-top: 8px;
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }
        
        .drink-section { margin-top: 20px; }

        .meta-stack { 
            grid-template-columns: 1fr; 
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            font-size: 0.95rem;
        }
        .meta-item-light { 
            background: transparent; 
            border: none; 
            padding: 0 !important; 
            display: flex; 
            flex-direction: column; 
            gap: 2px;
        }
        .meta-lbl-dark { 
            font-size: 0.7rem; 
            text-transform: capitalize !important; 
            letter-spacing: 0;
            font-weight: 600;
        }
        .meta-val { 
            margin-top: 0 !important; 
            font-weight: 700 !important; 
            font-size: 1rem !important; 
        }
        .header-card .meta-lbl-dark { color: rgba(219, 234, 254, 0.92); }
        .header-card .meta-val { color: #ffffff; text-shadow: 0 1px 2px rgba(15, 23, 42, 0.25); }
        .drink-section { 
            margin-top: 24px; 
            padding-top: 20px; 
            border-top: 2px solid rgba(59, 130, 246, 0.25);
        }
        
        @media (max-width: 600px) {
            .menu-grid { grid-template-columns: repeat(2, 1fr); }
            .quota-box { flex-direction: column; text-align: center; }
            .header-logo { height: 40px; max-width: 130px; }
            .header-top { align-items: flex-start; }
            .field-grid { grid-template-columns: 1fr; }
            .menu-img-wrap { height: 150px; }
            .menu-name { font-size: 0.92rem; }
            .menu-desc { font-size: 0.74rem; min-height: 38px; }
            .wa-float {
                right: 12px;
                bottom: 14px;
                height: 50px;
                min-width: 50px;
                padding: 0 12px;
            }
            .wa-float-text { display: none; }
        }

        @media (max-width: 430px) {
            .menu-grid { grid-template-columns: 1fr; }
            .menu-img-wrap { height: 188px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div id="quotaPopup" class="quota-popup" role="alertdialog" aria-modal="true" aria-labelledby="quotaPopupTitle" aria-describedby="quotaPopupText">
        <div class="quota-modal">
            <div class="quota-modal-head">
                <div class="quota-modal-title" id="quotaPopupTitle"><span class="badge">!</span> Extra Breakfast Detected</div>
            </div>
            <div class="quota-modal-body">
                <div class="quota-popup-text" id="quotaPopupText"></div>
                <div class="quota-popup-highlight" id="quotaPopupHighlight"></div>
                <div class="quota-popup-note" id="quotaPopupNote">Choose OK to keep extra items, or Close to automatically return to the allowed breakfast quota.</div>
            </div>
            <div class="quota-popup-actions">
                <button type="button" class="quota-popup-ok" id="quotaPopupOk">OK, keep extra</button>
                <button type="button" class="quota-popup-close" id="quotaPopupClose">Close, back to quota</button>
            </div>
        </div>
    </div>

    <div class="card" id="headerCard">
        <div class="header-card">
            <div class="header-top">
                <div class="header-brand">
                    <h1>Narayana Breakfast Selection</h1>
                    <div class="header-subtitle">Elegant Morning Dining Experience</div>
                </div>
                <img id="portalLogo" class="header-logo" alt="Narayana Logo">
            </div>
            <div class="header-divider"></div>
            <div class="muted-white" id="stateText">Loading details...</div>
            <div class="meta hidden" id="metaBox"></div>
        </div>
    </div>

    <div class="card hidden" id="infoCard">
        <div class="section-title"><span class="section-icon">ℹ️</span> Information</div>
        <div class="info-box" id="infoText"></div>
        <a class="media-link hidden" id="infoMedia" target="_blank">Open attachment</a>
    </div>

    <div class="card hidden" id="mainCard">
        <div class="section-title"><span class="section-icon">🍽️</span> Main Course</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Main Course</div>
                <div class="quota-count"><span id="mainQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="mainSelected">0</div>
            </div>
            <div id="mainExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="mainGrid"></div>
    </div>

    <div class="card hidden drink-section" id="drinkCard">
        <div class="section-title"><span class="section-icon">🥤</span> Beverages</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Beverages</div>
                <div class="quota-count"><span id="drinkQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="drinkSelected">0</div>
            </div>
            <div id="drinkExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="drinkGrid"></div>
    </div>

    <div class="card hidden" id="childCard">
        <div class="section-title"><span class="section-icon">👶</span> Kids / Fruit</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Kids / Fruit</div>
                <div class="quota-count"><span id="childQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="childSelected">0</div>
            </div>
            <div id="childExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="childGrid"></div>
    </div>

    <div class="card hidden" id="cartCard">
        <div class="section-title"><span class="section-icon">🧺</span> Keranjang Pilihan</div>
        <div class="cart-items" id="cartList"></div>
        <div class="cart-footer">
            <div class="cart-summary" id="cartSummaryText">Belum ada menu dipilih.</div>
            <button type="button" class="btn btn-ghost" id="btnContinueDetails">Fix Selection & Continue</button>
        </div>
    </div>

    <div class="card hidden" id="submitCard">
        <div class="section-title"><span class="section-icon">📝</span> Additional Notes</div>
        <div class="field-grid" id="breakfastFieldGrid">
            <div class="field-group">
                <label for="breakfastTime">Breakfast Time<span class="required-mark">*</span></label>
                <select class="field-control" id="breakfastTime" required></select>
            </div>
            <div class="field-group">
                <label for="serviceType">Service Type<span class="required-mark">*</span></label>
                <select class="field-control" id="serviceType" required>
                    <option value="">Select service</option>
                    <option value="restaurant">Restaurant</option>
                    <option value="room_service">Room Service</option>
                    <option value="take_away">Take Away</option>
                </select>
            </div>
            <div class="field-group">
                <label for="breakfastLocation">Breakfast Location<span class="required-mark">*</span></label>
                <input class="field-control" type="text" id="breakfastLocation" maxlength="120" placeholder="Example: Main Restaurant" required>
            </div>
        </div>
        <textarea id="notes" placeholder="Example: no spicy food / egg allergy / others"></textarea>
        <div class="actions">
            <button class="btn btn-primary" id="btnSubmit">Submit Breakfast Selection</button>
            <button class="btn btn-spot" id="btnOnTheSpot" type="button">ON THE SPOT</button>
            <a class="btn btn-wa hidden" id="btnWaFo" target="_blank" rel="noopener noreferrer">WhatsApp Front Office</a>
        </div>
        <div class="msg" id="submitMsg"></div>
    </div>

    <a class="wa-float" id="waFloatBtn" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp Front Office">
        <span class="wa-float-icon">WA</span>
        <span class="wa-float-text">Front Office</span>
    </a>
</div>

<script>
(function () {
    var token = <?php echo json_encode($token); ?>;
    var API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/breakfast-guest-portal.php'); ?>;
    var BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
    var FO_WA_NUMBER = '081222228590';
    var payload = null;
    var portalLogoEl = document.getElementById('portalLogo');
    var quotaPopupEl = document.getElementById('quotaPopup');
    var quotaPopupTextEl = document.getElementById('quotaPopupText');
    var quotaPopupHighlightEl = document.getElementById('quotaPopupHighlight');
    var quotaPopupNoteEl = document.getElementById('quotaPopupNote');
    var quotaPopupCloseEl = document.getElementById('quotaPopupClose');
    var quotaPopupOkEl = document.getElementById('quotaPopupOk');

    var mainGrid = document.getElementById('mainGrid');
    var childGrid = document.getElementById('childGrid');
    var drinkGrid = document.getElementById('drinkGrid');
    var waFoBtn = document.getElementById('btnWaFo');
    var waFloatBtn = document.getElementById('waFloatBtn');
    var onTheSpotBtn = document.getElementById('btnOnTheSpot');
    var breakfastTimeEl = document.getElementById('breakfastTime');
    var serviceTypeEl = document.getElementById('serviceType');
    var breakfastLocationEl = document.getElementById('breakfastLocation');

    function pad2(n) {
        return n < 10 ? ('0' + n) : String(n);
    }

    function buildTimeLabel(h, m) {
        return pad2(h) + ':' + pad2(m);
    }

    function fillBreakfastTimeOptions() {
        if (!breakfastTimeEl) return;
        var out = ['<option value="">Select time</option>'];
        for (var mins = (6 * 60) + 30; mins <= (10 * 60); mins += 30) {
            var h = Math.floor(mins / 60);
            var m = mins % 60;
            var val = buildTimeLabel(h, m);
            out.push('<option value="' + val + '">' + val + '</option>');
        }
        breakfastTimeEl.innerHTML = out.join('');
    }

    function normalizePhoneToWa(phone) {
        var num = String(phone || '').replace(/\D+/g, '');
        if (!num) return '';
        if (num.indexOf('0') === 0) return '62' + num.slice(1);
        if (num.indexOf('62') === 0) return num;
        return num;
    }

    function buildWaFoLink() {
        var waNum = normalizePhoneToWa(FO_WA_NUMBER);
        if (!waNum) return '';
        var msg = 'Hello Front Office, I want to request changes for my breakfast menu selection.';
        return 'https://wa.me/' + waNum + '?text=' + encodeURIComponent(msg);
    }

    function setState(text, isErr) {
        var el = document.getElementById('stateText');
        el.textContent = text;
        el.className = isErr ? 'muted-white err' : 'muted-white';
    }

    function openCards() {
        document.getElementById('metaBox').classList.remove('hidden');
        document.getElementById('mainCard').classList.add('hidden');
        document.getElementById('drinkCard').classList.add('hidden');
        document.getElementById('childCard').classList.add('hidden');
        document.getElementById('submitCard').classList.add('hidden');

        var mainMenus = payload.view_main_menus || [];
        var drinkMenus = payload.view_drink_menus || [];
        var childMenus = payload.view_child_menus || [];

        if (mainMenus.length > 0) {
            document.getElementById('mainCard').classList.remove('hidden');
        }
        
        if (drinkMenus.length > 0 && (payload.is_locked || (payload.max_drink || 0) > 0)) {
            document.getElementById('drinkCard').classList.remove('hidden');
        }
        
        if (childMenus.length > 0 && (payload.is_locked || (payload.max_child || 0) > 0)) {
            document.getElementById('childCard').classList.remove('hidden');
        }
        if ((payload.wa_info_text || '').trim() || (payload.wa_media_url || '').trim()) {
            document.getElementById('infoCard').classList.remove('hidden');
        }

        if (payload.is_locked) {
            var submitTitle = document.querySelector('#submitCard .section-title');
            if (submitTitle) submitTitle.innerHTML = '<span class="section-icon">🔒</span> Submitted Menu';
            var notes = document.getElementById('notes');
            notes.disabled = true;
            notes.value = 'This breakfast selection has already been submitted. Please contact Front Office for changes.';
            var btn = document.getElementById('btnSubmit');
            btn.disabled = true;
            btn.textContent = 'Submitted';
            var msg = document.getElementById('submitMsg');
            msg.textContent = 'This link is read-only. Menu changes can only be made by Front Office.';
            msg.className = 'msg ok';

            var submitBtn = document.getElementById('btnSubmit');
            if (submitBtn) submitBtn.style.display = 'none';
            if (onTheSpotBtn) onTheSpotBtn.style.display = 'none';

            if (breakfastTimeEl) breakfastTimeEl.disabled = true;
            if (serviceTypeEl) serviceTypeEl.disabled = true;
            if (breakfastLocationEl) breakfastLocationEl.disabled = true;

            if (waFoBtn) {
                var waLink = buildWaFoLink();
                waFoBtn.href = waLink;
                waFoBtn.classList.remove('hidden');
                if (waFloatBtn) {
                    waFloatBtn.href = waLink;
                    waFloatBtn.classList.add('show');
                }
            }
        } else if (waFoBtn) {
            waFoBtn.classList.add('hidden');
            if (waFloatBtn) waFloatBtn.classList.remove('show');
            if (onTheSpotBtn) onTheSpotBtn.style.display = 'inline-flex';
            if (breakfastTimeEl) breakfastTimeEl.disabled = false;
            if (serviceTypeEl) serviceTypeEl.disabled = false;
            if (breakfastLocationEl) breakfastLocationEl.disabled = false;
        }
    }

    function filterSelectedMenus(list, selectedIds) {
        if (!Array.isArray(list)) return [];
        if (!payload || !payload.is_locked) return list;
        var selectedMap = {};
        (selectedIds || []).forEach(function (id) {
            selectedMap[String(parseInt(id, 10))] = true;
        });
        return list.filter(function (m) {
            return !!selectedMap[String(parseInt(m.id, 10))];
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatDate(v) {
        if (!v) return '-';
        var p = String(v).split('-');
        if (p.length !== 3) return v;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function formatTime(v) {
        var val = String(v || '').trim();
        if (!val) return '-';
        return val.slice(0, 5);
    }

    function formatService(v) {
        var map = {
            restaurant: 'Restaurant',
            room_service: 'Room Service',
            take_away: 'Take Away'
        };
        return map[String(v || '').trim()] || (String(v || '').trim() || '-');
    }

    function renderMeta() {
        var meta = document.getElementById('metaBox');
        if (!meta) return;

        meta.className = 'meta meta-stack';
        meta.innerHTML = [
            ['Guest', payload.guest_name || '-'],
            ['Room', Array.isArray(payload.room_number) ? payload.room_number.join(', ') : '-'],
            ['Date', formatDate(payload.breakfast_date)],
            ['Breakfast Time', formatTime(payload.breakfast_time)],
            ['Service', formatService(payload.breakfast_service)],
            ['Location', payload.breakfast_location || '-'],
            ['Expires', payload.expires_at || '-']
        ].map(function (it) {
            return '<div class="meta-item-light"><div class="meta-lbl-dark">' + esc(it[0]) + '</div><div class="meta-val">' + esc(it[1]) + '</div></div>';
        }).join('');

        document.getElementById('mainQuotaText').textContent = String(payload.max_main || 0);
        document.getElementById('drinkQuotaText').textContent = String(payload.max_drink || 0);
        document.getElementById('childQuotaText').textContent = String(payload.max_child || 0);

        var infoText = (payload.wa_info_text || '').trim();
        var infoMedia = (payload.wa_media_url || '').trim();
        document.getElementById('infoText').textContent = infoText || 'No additional information.';
        if (infoMedia) {
            var mediaEl = document.getElementById('infoMedia');
            mediaEl.href = infoMedia;
            mediaEl.classList.remove('hidden');
        }

        if (portalLogoEl) {
            var logoUrl = (payload.portal_logo_url || '').trim();
            if (logoUrl) {
                portalLogoEl.src = logoUrl;
                portalLogoEl.classList.add('show');
            } else {
                portalLogoEl.classList.remove('show');
                portalLogoEl.removeAttribute('src');
            }
        }

        if (breakfastTimeEl) {
            var timeVal = (payload.breakfast_time || '').toString().slice(0, 5);
            breakfastTimeEl.value = timeVal || '07:00';
            if (!breakfastTimeEl.value) {
                breakfastTimeEl.value = '07:00';
            }
        }
        if (serviceTypeEl) {
            serviceTypeEl.value = payload.breakfast_service || 'restaurant';
        }
        if (breakfastLocationEl) {
            breakfastLocationEl.value = payload.breakfast_location || 'Main Restaurant';
        }
    }

    function menuCard(item, group) {
        var locked = !!payload.is_locked;
        var price = parseFloat(item.price || 0);
        var free = String(item.is_free) === '1' || item.is_free === 1 || item.is_free === true;
        var noteMap = group === 'main'
            ? (payload.selected_main_notes || {})
            : (group === 'drink' ? (payload.selected_drink_notes || {}) : (payload.selected_child_notes || {}));
        var noteVal = String(noteMap[String(item.id)] || noteMap[item.id] || '');
        var imgUrl = item.image_url || '';
        var desc = item.description || '';
        var hasImg = imgUrl && imgUrl.trim() !== '';
        var checked = item.pre_selected ? 'checked' : '';
        var resolvedImg = imgUrl ? (/^https?:\/\//i.test(imgUrl) ? imgUrl : BASE_URL + '/' + imgUrl.replace(/^\/+/, '')) : '';
        
        var imgHtml = '';
        if (hasImg) {
            imgHtml = '<div class="menu-img-wrap"><img class="menu-img" src="' + esc(resolvedImg) + '" alt="' + esc(item.menu_name) + '" onerror="this.parentElement.innerHTML=\'<span class=\'menu-img-placeholder\'>🍽️</span>\'"></div>';
        } else {
            imgHtml = '<div class="menu-img-wrap"><span class="menu-img-placeholder">🍽️</span></div>';
        }

        if (locked) {
            var noteHtmlLocked = noteVal
                ? '<div class="menu-note-readonly">Request: ' + esc(noteVal) + '</div>'
                : '';
            return '<div class="menu-item locked' + (item.pre_selected ? ' selected' : '') + '">' +
                '<input type="checkbox" class="menu-check" data-group="' + group + '" data-menu-name="' + esc(item.menu_name) + '" data-menu-category="' + esc(item.category || '-') + '" data-menu-price="' + price + '" data-menu-free="' + (free ? '1' : '0') + '" value="' + item.id + '" ' + checked + ' disabled>' +
                imgHtml +
                '<div class="menu-content">' +
                '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
                '<div class="menu-desc">' + esc(desc) + '</div>' +
                '<div class="menu-footer">' +
                '<span class="menu-cat">' + esc(item.category || '-') + '</span>' +
                '<span class="menu-price ' + (free ? 'free' : '') + '">' + (free ? 'FREE' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</span>' +
                '</div>' +
                noteHtmlLocked +
                '</div>' +
                '</div>';
        }

        var noteHtmlEdit = '<div class="menu-note-wrap">' +
            '<label class="menu-note-label">Menu note (optional)</label>' +
            '<input class="menu-note-input" type="text" data-group="' + group + '" data-menu-id="' + item.id + '" value="' + esc(noteVal) + '"' +
            ' placeholder="Special request (e.g. spicy / medium / no onion)" onclick="event.stopPropagation()" onfocus="event.stopPropagation()">' +
            '</div>';
        
        return '<label class="menu-item' + (item.pre_selected ? ' selected' : '') + '">' +
            '<input type="checkbox" class="menu-check" data-group="' + group + '" data-menu-name="' + esc(item.menu_name) + '" data-menu-category="' + esc(item.category || '-') + '" data-menu-price="' + price + '" data-menu-free="' + (free ? '1' : '0') + '" value="' + item.id + '" ' + checked + '>' +
            imgHtml +
            '<div class="menu-content">' +
            '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
            '<div class="menu-desc">' + esc(desc) + '</div>' +
            '<div class="menu-footer">' +
            '<span class="menu-cat">' + esc(item.category || '-') + '</span>' +
            '<span class="menu-price ' + (free ? 'free' : '') + '">' + (free ? 'FREE' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</span>' +
            '</div>' +
            noteHtmlEdit +
            '</div>' +
            '</label>';
    }

    function refreshQuotaInfo(group, max, target, infoTargetId, extraUnitPrice) {
        var checks = Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]'));
        var selected = checks.filter(function (c) { return c.checked; });
        target.textContent = String(selected.length);
        var extraCount = Math.max(0, selected.length - max);
        var infoEl = document.getElementById(infoTargetId);
        if (!infoEl) return;
        if (extraCount <= 0) {
            infoEl.textContent = '';
            return;
        }
        var est = Math.max(0, extraUnitPrice || 0) * extraCount;
        var estText = est > 0 ? (' (est. +' + est.toLocaleString('id-ID') + ')') : '';
        infoEl.textContent = 'Extra ' + extraCount + ' item(s)' + estText + ' will be charged at Front Desk.';
        if (max <= 0) {
            infoEl.textContent = '';
        }
    }

    function attachNoteAutoSelect() {
        document.addEventListener('input', function (ev) {
            var input = ev.target;
            if (!input.classList || !input.classList.contains('menu-note-input')) return;
            var menuItem = input.closest('.menu-item');
            if (!menuItem) return;
            var check = menuItem.querySelector('.menu-check');
            if (!check || check.disabled) return;
            if (String(input.value || '').trim() !== '' && !check.checked) {
                check.checked = true;
                menuItem.classList.add('selected');
                var changeEvt = document.createEvent('Event');
                changeEvt.initEvent('change', true, true);
                check.dispatchEvent(changeEvt);
            }
            refreshSelectionUI();
        });
    }

    function getCheckedItems(group) {
        return Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]:checked'));
    }

    function getSelectedCartItems() {
        var groups = ['main', 'drink', 'child'];
        var items = [];
        groups.forEach(function (group) {
            getCheckedItems(group).forEach(function (cb) {
                var id = parseInt(cb.value, 10);
                if (!Number.isFinite(id) || id <= 0) return;
                var noteInput = document.querySelector('.menu-note-input[data-group="' + group + '"][data-menu-id="' + id + '"]');
                items.push({
                    id: id,
                    group: group,
                    name: String(cb.dataset.menuName || '-'),
                    category: String(cb.dataset.menuCategory || '-'),
                    price: parseFloat(cb.dataset.menuPrice || '0') || 0,
                    free: String(cb.dataset.menuFree || '0') === '1',
                    note: noteInput ? String(noteInput.value || '').trim() : ''
                });
            });
        });
        return items;
    }

    function renderCart() {
        var items = getSelectedCartItems();
        var cartCard = document.getElementById('cartCard');
        var cartList = document.getElementById('cartList');
        var cartSummaryText = document.getElementById('cartSummaryText');
        var submitCard = document.getElementById('submitCard');
        if (!cartCard || !cartList || !cartSummaryText) return;

        if (!items.length) {
            cartCard.classList.add('hidden');
            cartList.innerHTML = '';
            cartSummaryText.textContent = 'Belum ada menu dipilih.';
            if (submitCard && !payload.is_locked) {
                submitCard.classList.add('hidden');
            }
            return;
        }

        cartCard.classList.remove('hidden');
        if (submitCard) {
            submitCard.classList.remove('hidden');
        }
        cartSummaryText.innerHTML = '<strong>' + items.length + '</strong> item di keranjang. Silakan cek lagi sebelum lanjut isi detail.';
        cartList.innerHTML = items.map(function (item) {
            var priceText = item.free ? 'FREE' : 'Rp ' + Math.round(item.price).toLocaleString('id-ID');
            var noteHtml = item.note ? '<div class="cart-note">Note: ' + esc(item.note) + '</div>' : '';
            return '<div class="cart-item">' +
                '<div class="cart-main">' +
                    '<div class="cart-name">' + esc(item.name) + '</div>' +
                    '<div class="cart-meta">' + esc(item.group) + ' · ' + esc(item.category) + ' · ' + esc(priceText) + '</div>' +
                    noteHtml +
                '</div>' +
            '</div>';
        }).join('');
    }

    function countOverQuota() {
        if (!payload || payload.is_locked) return { total: 0, details: [] };
        var groups = [
            ['main', parseInt(payload.max_main || 0, 10)],
            ['drink', parseInt(payload.max_drink || 0, 10)],
            ['child', parseInt(payload.max_child || 0, 10)]
        ];
        var details = [];
        var total = 0;
        groups.forEach(function (pair) {
            var group = pair[0];
            var max = pair[1];
            var extra = Math.max(0, getCheckedItems(group).length - max);
            if (extra > 0) {
                details.push({ group: group, extra: extra });
                total += extra;
            }
        });
        return { total: total, details: details };
    }

    function showQuotaPopup() {
        if (!payload || payload.is_locked) {
            if (quotaPopupEl) quotaPopupEl.classList.remove('show');
            return;
        }
        var over = countOverQuota();
        if (!over.total) {
            quotaPopupEl.classList.remove('show');
            return;
        }

        var extraMain = Math.max(0, getCheckedItems('main').length - parseInt(payload.max_main || 0, 10));
        var extraDrink = Math.max(0, getCheckedItems('drink').length - parseInt(payload.max_drink || 0, 10));
        var extraChild = Math.max(0, getCheckedItems('child').length - parseInt(payload.max_child || 0, 10));
        var lines = [];
        if (extraMain > 0) lines.push(extraMain + ' extra main');
        if (extraDrink > 0) lines.push(extraDrink + ' extra drink');
        if (extraChild > 0) lines.push(extraChild + ' extra kids/fruit');

        var totalExtra = over.total;
        var estTotal = totalExtra * 75000;
        if (quotaPopupTextEl) quotaPopupTextEl.textContent = 'You selected more than the included breakfast allowance.';
        if (quotaPopupHighlightEl) quotaPopupHighlightEl.textContent = 'Extra charge: Rp ' + estTotal.toLocaleString('id-ID') + ' (' + lines.join(', ') + ')';
        if (quotaPopupNoteEl) quotaPopupNoteEl.textContent = 'OK = keep these extra items. Close = trim back to the allowed quota.';
        quotaPopupEl.classList.add('show');
    }

    function enforceQuotaLimits() {
        var groups = [
            ['main', parseInt(payload.max_main || 0, 10)],
            ['drink', parseInt(payload.max_drink || 0, 10)],
            ['child', parseInt(payload.max_child || 0, 10)]
        ];
        groups.forEach(function (pair) {
            var group = pair[0];
            var max = pair[1];
            var checked = getCheckedItems(group);
            if (checked.length <= max) return;
            checked.slice(max).forEach(function (cb) {
                cb.checked = false;
                var card = cb.closest('.menu-item');
                if (card) card.classList.remove('selected');
            });
        });
        refreshSelectionUI();
    }

    function refreshSelectionUI() {
        renderCart();
        showQuotaPopup();
    }

    function attachQuotaHandlers() {
        document.addEventListener('change', function (ev) {
            var el = ev.target;
            if (!el.classList.contains('menu-check')) return;

            var menuItem = el.closest('.menu-item');
            if (menuItem) {
                menuItem.classList.toggle('selected', !!el.checked);
            }

            refreshSelectionUI();

            var group = el.dataset.group;
            if (group === 'main') {
                refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            } else if (group === 'drink') {
                refreshQuotaInfo('drink', parseInt(payload.max_drink || 0, 10), document.getElementById('drinkSelected'), 'drinkExtraInfo', parseFloat(payload.extra_drink_price || 0));
            } else {
                refreshQuotaInfo('child', parseInt(payload.max_child || 0, 10), document.getElementById('childSelected'), 'childExtraInfo', parseFloat(payload.extra_child_price || 0));
            }
        });

        document.addEventListener('click', function (ev) {
            if (ev.target.id === 'btnContinueDetails') {
                ev.preventDefault();
                var submitCard = document.getElementById('submitCard');
                if (submitCard) {
                    submitCard.classList.remove('hidden');
                    submitCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                if (breakfastTimeEl) breakfastTimeEl.focus();
            }
        });
    }

    function selectedWithNotes(group) {
        var ids = [];
        var notes = {};
        Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]:checked')).forEach(function (c) {
            var id = parseInt(c.value, 10);
            if (!Number.isFinite(id) || id <= 0) return;
            ids.push(id);
            var noteInput = document.querySelector('.menu-note-input[data-group="' + group + '"][data-menu-id="' + id + '"]');
            var note = noteInput ? String(noteInput.value || '').trim() : '';
            if (note) {
                notes[String(id)] = note;
            }
        });
        return { ids: ids, notes: notes };
    }

    async function loadLink() {
        if (!token) {
            setState('Token is missing.', true);
            return;
        }
        try {
            var res = await fetch(API + '?action=get_link&token=' + encodeURIComponent(token));
            var json = await res.json();
            if (!json.success) {
                setState(json.message || 'Invalid link.', true);
                return;
            }

            payload = json.data || {};
            payload.view_main_menus = filterSelectedMenus(payload.main_menus || [], payload.selected_main_ids || []);
            payload.view_drink_menus = filterSelectedMenus(payload.drink_menus || [], payload.selected_drink_ids || []);
            payload.view_child_menus = filterSelectedMenus(payload.child_menus || [], payload.selected_child_ids || []);

            setState(
                payload.is_locked
                    ? ((parseInt(payload.on_the_spot || 0, 10) === 1)
                        ? 'ON The Spot selected. Please come to restaurant in the morning.'
                        : 'Selection already submitted. This link is read-only.')
                    : 'Please choose items based on your allowance.',
                false
            );
            renderMeta();
            openCards();

            mainGrid.innerHTML = (payload.view_main_menus || []).map(function (m) { return menuCard(m, 'main'); }).join('');
            drinkGrid.innerHTML = (payload.view_drink_menus || []).map(function (m) { return menuCard(m, 'drink'); }).join('');
            childGrid.innerHTML = (payload.view_child_menus || []).map(function (m) { return menuCard(m, 'child'); }).join('');
            
            refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            refreshQuotaInfo('drink', parseInt(payload.max_drink || 0, 10), document.getElementById('drinkSelected'), 'drinkExtraInfo', parseFloat(payload.extra_drink_price || 0));
            refreshQuotaInfo('child', parseInt(payload.max_child || 0, 10), document.getElementById('childSelected'), 'childExtraInfo', parseFloat(payload.extra_child_price || 0));
            refreshSelectionUI();
        } catch (err) {
            setState('Failed to load link: ' + err.message, true);
        }
    }

    async function submitChoice(onTheSpot) {
        var msgEl = document.getElementById('submitMsg');
        msgEl.textContent = '';
        msgEl.className = 'msg';

        var mainPicked = selectedWithNotes('main');
        var drinkPicked = selectedWithNotes('drink');
        var childPicked = selectedWithNotes('child');
        var selectedMain = mainPicked.ids;
        var selectedDrink = drinkPicked.ids;
        var selectedChild = childPicked.ids;
        var breakfastTime = (breakfastTimeEl && breakfastTimeEl.value) ? String(breakfastTimeEl.value).trim() : '';
        var serviceType = (serviceTypeEl && serviceTypeEl.value) ? String(serviceTypeEl.value).trim() : '';
        var breakfastLocation = (breakfastLocationEl && breakfastLocationEl.value) ? String(breakfastLocationEl.value).trim() : '';

        if (onTheSpot) {
            if (!breakfastTime) breakfastTime = '07:00';
            serviceType = 'restaurant';
            if (!breakfastLocation) breakfastLocation = 'Main Restaurant';
            if (breakfastTimeEl && !breakfastTimeEl.value) breakfastTimeEl.value = breakfastTime;
            if (serviceTypeEl) serviceTypeEl.value = serviceType;
            if (breakfastLocationEl && !breakfastLocationEl.value) breakfastLocationEl.value = breakfastLocation;
        }

        if (!breakfastTime) {
            msgEl.textContent = 'Breakfast time is required.';
            msgEl.classList.add('err');
            return;
        }
        if (!serviceType) {
            msgEl.textContent = 'Service type is required.';
            msgEl.classList.add('err');
            return;
        }
        if (!breakfastLocation) {
            msgEl.textContent = 'Breakfast location is required.';
            msgEl.classList.add('err');
            return;
        }

        if (!onTheSpot && (selectedMain.length + selectedDrink.length + selectedChild.length === 0)) {
            msgEl.textContent = 'Please select at least 1 item.';
            msgEl.classList.add('err');
            return;
        }

        var body = {
            action: 'submit_link',
            token: token,
            selected_main: selectedMain,
            selected_main_notes: mainPicked.notes,
            selected_drink: selectedDrink,
            selected_drink_notes: drinkPicked.notes,
            selected_child: selectedChild,
            selected_child_notes: childPicked.notes,
            breakfast_time: breakfastTime,
            service_type: serviceType,
            breakfast_location: breakfastLocation,
            on_the_spot: onTheSpot ? 1 : 0,
            special_requests: (document.getElementById('notes').value || '').trim()
        };

        if (onTheSpot) {
            body.selected_main = [];
            body.selected_main_notes = {};
            body.selected_drink = [];
            body.selected_drink_notes = {};
            body.selected_child = [];
            body.selected_child_notes = {};
            body.special_requests = ((body.special_requests ? body.special_requests + ' ' : '') + '[ON THE SPOT]').trim();
        }

        var btn = document.getElementById('btnSubmit');
        var spotBtn = document.getElementById('btnOnTheSpot');
        btn.disabled = true;
        if (spotBtn) spotBtn.disabled = true;
        btn.textContent = onTheSpot ? 'Submitting ON The Spot...' : 'Submitting...';

        try {
            var res = await fetch(API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });
            var json = await res.json();
            if (!json.success) {
                msgEl.textContent = json.message || 'Failed to submit selection.';
                msgEl.classList.add('err');
                btn.disabled = false;
                if (spotBtn) spotBtn.disabled = false;
                btn.textContent = 'Submit Breakfast Selection';
                return;
            }

            msgEl.textContent = onTheSpot
                ? 'Thank you. ON The Spot selected. Please come to restaurant in the morning and choose menu directly.'
                : 'Thank you. Your breakfast selection has been submitted.';
            if (json.data && json.data.extra_total_price && json.data.extra_total_price > 0) {
                msgEl.textContent += ' Extra charge: Rp ' + Math.round(json.data.extra_total_price).toLocaleString('id-ID') + ' (pay at Front Desk).';
            }
            msgEl.classList.add('ok');
            btn.textContent = 'Submitted';
            await loadLink();
        } catch (err) {
            msgEl.textContent = 'Connection error: ' + err.message;
            msgEl.classList.add('err');
            btn.disabled = false;
            if (spotBtn) spotBtn.disabled = false;
            btn.textContent = 'Submit Breakfast Selection';
        }
    }

    attachQuotaHandlers();
    attachNoteAutoSelect();
    fillBreakfastTimeOptions();
    if (quotaPopupOkEl) {
        quotaPopupOkEl.addEventListener('click', function () {
            if (quotaPopupEl) quotaPopupEl.classList.remove('show');
        });
    }
    if (quotaPopupCloseEl) {
        quotaPopupCloseEl.addEventListener('click', function () {
            enforceQuotaLimits();
            if (quotaPopupEl) quotaPopupEl.classList.remove('show');
        });
    }
    loadLink();
    document.getElementById('btnSubmit').addEventListener('click', function () { submitChoice(false); });
    document.getElementById('btnOnTheSpot').addEventListener('click', function () { submitChoice(true); });
})();
</script>
</body>
</html>

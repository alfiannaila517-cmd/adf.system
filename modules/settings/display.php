<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan Tampilan';

// Get current settings
$settings = [];
$result = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($result as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Ensure user_preferences table exists
try {
    $db->fetchOne("SELECT 1 FROM user_preferences LIMIT 1");
    // Drop foreign key constraint if it exists (causes errors in multi-DB setup)
    try {
        $db->getConnection()->exec("ALTER TABLE user_preferences DROP FOREIGN KEY user_preferences_ibfk_1");
    } catch (Exception $fkErr) {
        // FK doesn't exist — fine
    }
} catch (Exception $e) {
    // Table doesn't exist, create it (no FK to avoid errors on fresh DBs)
    try {
        $db->getConnection()->exec("
            CREATE TABLE IF NOT EXISTS user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                branch_id VARCHAR(50) NOT NULL DEFAULT '',
                theme VARCHAR(50) DEFAULT 'dark',
                language VARCHAR(20) DEFAULT 'id',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_branch (user_id, branch_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $createError) {
        error_log('Failed to create user_preferences table: ' . $createError->getMessage());
    }
}

// Get current user preferences (theme & language)
$preferences = $db->fetchOne(
    "SELECT * FROM user_preferences WHERE user_id = ?",
    [$currentUser['id']]
);
$currentTheme = $preferences['theme'] ?? 'dark';
$currentLanguage = $preferences['language'] ?? 'id';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->getConnection()->beginTransaction();
        
        // Update display settings
        $updates = [
            'currency_symbol' => $_POST['currency_symbol'],
            'currency_position' => $_POST['currency_position'],
            'date_format' => $_POST['date_format'],
            'timezone' => $_POST['timezone']
        ];
        
        foreach ($updates as $key => $value) {
            $db->update('settings', 
                ['setting_value' => $value], 
                'setting_key = :key', 
                ['key' => $key]
            );
        }
        
        // Update user preferences (theme & language)
        // Tanpa pilihan tema di form (mis. tema "system" dari menu foto user) → pertahankan yang tersimpan
        $theme = $_POST['theme'] ?? $currentTheme;
        $language = $_POST['language'] ?? 'id';
        
        // Debug log
        error_log("Saving theme: $theme for user {$currentUser['id']}");
        
        try {
            // Check if user has existing preferences
            $existing = $db->fetchOne(
                "SELECT id FROM user_preferences WHERE user_id = ?",
                [$currentUser['id']]
            );
            
            if ($existing) {
                // Update existing
                $db->update('user_preferences', [
                    'theme' => $theme,
                    'language' => $language,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'user_id = :user_id', ['user_id' => $currentUser['id']]);
                error_log("Updated theme preference for user {$currentUser['id']} to $theme");
            } else {
                // Insert new
                $db->insert('user_preferences', [
                    'user_id' => $currentUser['id'],
                    'theme' => $theme,
                    'language' => $language
                ]);
                error_log("Inserted new theme preference for user {$currentUser['id']} as $theme");
            }
        } catch (Exception $prefError) {
            error_log('Failed to update user_preferences: ' . $prefError->getMessage());
            throw $prefError;
        }
        
        // Don't update global session for theme (it's now per-business)
        // Each business will load its own theme from database
        
        // Update session language immediately so it takes effect
        $_SESSION['user_language'] = $language;
        
        $db->getConnection()->commit();
        
        // Use translated message based on selected language  
        if ($language === 'en') {
            setFlashMessage('success', 'Display settings for ' . BUSINESS_NAME . ' have been updated successfully!');
        } else {
            setFlashMessage('success', 'Pengaturan tampilan untuk ' . BUSINESS_NAME . ' berhasil diperbarui!');
        }
        header('Location: display.php?saved=1');
        exit;
    } catch (Exception $e) {
        $db->getConnection()->rollBack();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Sample numbers for preview
$sampleAmount = 1500000;
$sampleDate = date('Y-m-d');

include '../../includes/header.php';

// Show success message if just saved AND force apply theme
if (isset($_GET['saved'])) {
    echo '<script>
        // Force reload theme from body attribute
        setTimeout(function() {
            const currentTheme = document.body.getAttribute("data-theme");
            console.log("Applied theme after save:", currentTheme);
            
            const successDiv = document.createElement("div");
            successDiv.style.cssText = "position: fixed; top: 20px; right: 20px; background: var(--success); color: white; padding: 1rem 1.5rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); z-index: 9999; animation: slideIn 0.3s ease-out;";
            successDiv.innerHTML = "<div style=\\"display: flex; align-items: center; gap: 0.5rem;\\"><i data-feather=\\"check-circle\\" style=\\"width: 16px; height: 16px;\\"></i><span>Pengaturan berhasil disimpan! Tema: " + currentTheme + "</span></div>";
            document.body.appendChild(successDiv);
            feather.replace();
            setTimeout(() => {
                successDiv.style.animation = "slideOut 0.3s ease-out";
                setTimeout(() => successDiv.remove(), 300);
            }, 3000);
        }, 100);
    </script>';
}
?>

<div style="max-width: 900px;">
    <div style="margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>

    <div class="card">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                <i data-feather="monitor" style="width: 18px; height: 18px; margin-right: 0.5rem;"></i>
                Pengaturan Format Tampilan
            </h3>
        </div>
        
        <form method="POST" style="padding: 1rem;">
            <!-- Theme & Language Settings -->
            <div style="padding: 1rem; background: var(--bg-tertiary); border-radius: 0.5rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.938rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary);">
                    <?php echo __('display.theme_language'); ?>
                </h4>
                
                <!-- Theme Selection -->
                <div style="margin-bottom: 1rem;">
                    <label class="form-label">Tema Tampilan</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.75rem;">
                        <!-- Light Theme -->
                        <label class="theme-option <?php echo $currentTheme === 'light' ? 'active' : ''; ?>" data-theme="light">
                            <input type="radio" name="theme" value="light" <?php echo $currentTheme === 'light' ? 'checked' : ''; ?> style="display: none;">
                            <div style="padding: 1rem; border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg); cursor: pointer; transition: all 0.3s;">
                                <div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.625rem;">
                                    <div style="width: 32px; height: 32px; border-radius: var(--radius-md); background: linear-gradient(135deg, #fff, #f8fafc); border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;">
                                        <i data-feather="sun" style="width: 16px; height: 16px; color: #f59e0b;"></i>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.875rem;">Tema Terang</div>
                                        <div style="font-size: 0.688rem; color: var(--text-muted);">Mode siang hari</div>
                                    </div>
                                </div>
                            </div>
                        </label>
                        
                        <!-- Dark Theme -->
                        <label class="theme-option <?php echo $currentTheme === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                            <input type="radio" name="theme" value="dark" <?php echo $currentTheme === 'dark' ? 'checked' : ''; ?> style="display: none;">
                            <div style="padding: 1rem; border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg); cursor: pointer; transition: all 0.3s;">
                                <div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.625rem;">
                                    <div style="width: 32px; height: 32px; border-radius: var(--radius-md); background: linear-gradient(135deg, #1e293b, #0f172a); display: flex; align-items: center; justify-content: center;">
                                        <i data-feather="moon" style="width: 16px; height: 16px; color: #6366f1;"></i>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.875rem;">Tema Gelap</div>
                                        <div style="font-size: 0.688rem; color: var(--text-muted);">Mode malam hari</div>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Language Selection: pilihan langsung dari layar (kartu) -->
                <div>
                    <label class="form-label">
                        <i data-feather="globe" style="width: 14px; height: 14px;"></i>
                        <?php echo __('display.language_label'); ?>
                    </label>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin: 0.15rem 0 0.6rem;"><?php echo __('display.language_hint'); ?></div>
                    <div class="lang-grid" id="langGrid">
                        <?php foreach (langSupported() as $__lc => $__li): $__on = $currentLanguage === $__lc; ?>
                            <label class="lang-card<?php echo $__on ? ' active' : ''; ?>" data-lang="<?php echo $__lc; ?>">
                                <input type="radio" name="language" value="<?php echo $__lc; ?>" <?php echo $__on ? 'checked' : ''; ?> style="display:none;">
                                <span class="lang-flag"><?php echo $__li['flag']; ?></span>
                                <span class="lang-txt"><strong><?php echo htmlspecialchars($__li['name']); ?></strong><small><?php echo strtoupper($__lc); ?></small></span>
                                <span class="lang-check"><i data-feather="check"></i></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <style>
                    .lang-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; max-width: 560px; }
                    .lang-card { position: relative; display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1rem; border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg, 12px); cursor: pointer; transition: all .2s ease; background: transparent; }
                    .lang-card:hover { border-color: var(--primary-light, #60a5fa); transform: translateY(-1px); }
                    .lang-card.active { border-color: var(--primary-color, #2563eb); background: rgba(37, 99, 235, .08); }
                    .lang-flag { font-size: 1.7rem; line-height: 1; }
                    .lang-txt { display: flex; flex-direction: column; min-width: 0; }
                    .lang-txt strong { font-size: .9rem; color: var(--text-primary); }
                    .lang-txt small { font-size: .7rem; color: var(--text-muted); letter-spacing: .06em; }
                    .lang-check { margin-left: auto; width: 22px; height: 22px; border-radius: 50%; display: none; align-items: center; justify-content: center; background: var(--primary-color, #2563eb); color: #fff; }
                    .lang-card.active .lang-check { display: inline-flex; }
                    .lang-check svg { width: 13px; height: 13px; stroke: #fff; }
                </style>
                <script>
                    (function() {
                        var grid = document.getElementById('langGrid');
                        if (!grid) return;
                        grid.addEventListener('click', function(e) {
                            var card = e.target.closest('.lang-card');
                            if (!card || card.classList.contains('active')) return;
                            var code = card.getAttribute('data-lang');
                            var radio = card.querySelector('input[type=radio]');
                            if (radio) radio.checked = true;
                            grid.querySelectorAll('.lang-card').forEach(function(c) { c.classList.toggle('active', c === card); });
                            var fd = new FormData();
                            fd.append('language', code);
                            fetch('<?php echo BASE_URL; ?>/api/set-language.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function(r) { return r.json(); })
                                .then(function(d) {
                                    if (d && d.success) { location.reload(); } else { alert((d && d.message) || '<?php echo addslashes(__('user_menu.language_failed')); ?>'); }
                                })
                                .catch(function() { alert('<?php echo addslashes(__('user_menu.server_failed')); ?>'); });
                        });
                    })();
                </script>
            </div>
            <!-- Currency Settings -->
            <div style="padding: 1rem; background: var(--bg-tertiary); border-radius: 0.5rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.938rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary);">
                    Pengaturan Mata Uang
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Simbol Mata Uang</label>
                        <select name="currency_symbol" class="form-control" id="currencySymbol">
                            <option value="Rp" <?php echo ($settings['currency_symbol'] ?? 'Rp') === 'Rp' ? 'selected' : ''; ?>>Rp (Rupiah)</option>
                            <option value="$" <?php echo ($settings['currency_symbol'] ?? '') === '$' ? 'selected' : ''; ?>>$ (Dollar)</option>
                            <option value="€" <?php echo ($settings['currency_symbol'] ?? '') === '€' ? 'selected' : ''; ?>>€ (Euro)</option>
                            <option value="£" <?php echo ($settings['currency_symbol'] ?? '') === '£' ? 'selected' : ''; ?>>£ (Pound)</option>
                            <option value="¥" <?php echo ($settings['currency_symbol'] ?? '') === '¥' ? 'selected' : ''; ?>>¥ (Yen)</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Posisi Simbol</label>
                        <select name="currency_position" class="form-control" id="currencyPosition">
                            <option value="before" <?php echo ($settings['currency_position'] ?? 'before') === 'before' ? 'selected' : ''; ?>>Sebelum angka (Rp 1,500,000)</option>
                            <option value="after" <?php echo ($settings['currency_position'] ?? '') === 'after' ? 'selected' : ''; ?>>Setelah angka (1,500,000 Rp)</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                    <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Preview:</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);" id="currencyPreview">
                        <?php 
                        $pos = $settings['currency_position'] ?? 'before';
                        $sym = $settings['currency_symbol'] ?? 'Rp';
                        echo $pos === 'before' ? $sym . ' ' . number_format($sampleAmount) : number_format($sampleAmount) . ' ' . $sym;
                        ?>
                    </div>
                </div>
            </div>

            <!-- Date Format Settings -->
            <div style="padding: 1rem; background: var(--bg-tertiary); border-radius: 0.5rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.938rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary);">
                    Format Tanggal
                </h4>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Format Tanggal</label>
                    <select name="date_format" class="form-control" id="dateFormat">
                        <option value="d/m/Y" <?php echo ($settings['date_format'] ?? 'd/m/Y') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (<?php echo date('d/m/Y'); ?>)</option>
                        <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (<?php echo date('m/d/Y'); ?>)</option>
                        <option value="Y-m-d" <?php echo ($settings['date_format'] ?? '') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (<?php echo date('Y-m-d'); ?>)</option>
                        <option value="d-m-Y" <?php echo ($settings['date_format'] ?? '') === 'd-m-Y' ? 'selected' : ''; ?>>DD-MM-YYYY (<?php echo date('d-m-Y'); ?>)</option>
                        <option value="d M Y" <?php echo ($settings['date_format'] ?? '') === 'd M Y' ? 'selected' : ''; ?>>DD MMM YYYY (<?php echo date('d M Y'); ?>)</option>
                        <option value="F d, Y" <?php echo ($settings['date_format'] ?? '') === 'F d, Y' ? 'selected' : ''; ?>>MMMM DD, YYYY (<?php echo date('F d, Y'); ?>)</option>
                    </select>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                    <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Preview:</div>
                    <div style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary);" id="datePreview">
                        <?php echo date($settings['date_format'] ?? 'd/m/Y'); ?>
                    </div>
                </div>
            </div>

            <!-- Timezone Settings -->
            <div style="padding: 1rem; background: var(--bg-tertiary); border-radius: 0.5rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.938rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary);">
                    Zona Waktu
                </h4>
                
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-control">
                        <option value="Asia/Makassar" <?php echo ($settings['timezone'] ?? 'Asia/Makassar') === 'Asia/Makassar' ? 'selected' : ''; ?>>WITA (Makassar, Bali)</option>
                        <option value="Asia/Jakarta" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jakarta' ? 'selected' : ''; ?>>WIB (Jakarta, Medan)</option>
                        <option value="Asia/Jayapura" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jayapura' ? 'selected' : ''; ?>>WIT (Jayapura, Manado)</option>
                        <option value="Asia/Singapore" <?php echo ($settings['timezone'] ?? '') === 'Asia/Singapore' ? 'selected' : ''; ?>>Singapore (SGT)</option>
                        <option value="UTC" <?php echo ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC (Universal)</option>
                    </select>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                    <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Current Time:</div>
                    <div style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary);">
                        <?php 
                        date_default_timezone_set($settings['timezone'] ?? 'Asia/Makassar');
                        echo date('H:i:s'); 
                        ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 0.5rem; padding-top: 0.75rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan Pengaturan
                </button>
                <a href="index.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<style>
.theme-option > div {
    transition: all 0.3s;
}

.theme-option:hover > div {
    border-color: var(--primary-color);
    transform: translateY(-2px);
}

.theme-option.active > div {
    border-color: var(--primary-color);
    background: rgba(99, 102, 241, 0.05);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

@keyframes slideIn {
    from { transform: translateX(400px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(400px); opacity: 0; }
}
</style>

<script>
    feather.replace();
    
    // Apply current theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        const currentTheme = '<?php echo $currentTheme; ?>';
        document.body.setAttribute('data-theme', currentTheme);
        console.log('Theme applied on page load:', currentTheme);
    });
    
    // Theme selection with instant preview
    document.querySelectorAll('.theme-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.theme-option').forEach(opt => opt.classList.remove('active'));
            this.classList.add('active');
            this.querySelector('input[type="radio"]').checked = true;
            
            const selectedTheme = this.querySelector('input[type="radio"]').value;
            document.body.setAttribute('data-theme', selectedTheme);
            console.log('Theme preview:', selectedTheme);
        });
    });
    
    // Live preview for currency
    const currencySymbol = document.getElementById('currencySymbol');
    const currencyPosition = document.getElementById('currencyPosition');
    const currencyPreview = document.getElementById('currencyPreview');
    
    function updateCurrencyPreview() {
        if (!currencySymbol || !currencyPosition || !currencyPreview) return;
        
        const symbol = currencySymbol.value;
        const position = currencyPosition.value;
        const amount = '1,500,000';
        
        if (position === 'before') {
            currencyPreview.textContent = symbol + ' ' + amount;
        } else {
            currencyPreview.textContent = amount + ' ' + symbol;
        }
    }
    
    if (currencySymbol && currencyPosition) {
        currencySymbol.addEventListener('change', updateCurrencyPreview);
        currencyPosition.addEventListener('change', updateCurrencyPreview);
    }
    
    // Live preview for date format
    const dateFormat = document.getElementById('dateFormat');
    const datePreview = document.getElementById('datePreview');
    const dateFormats = {
        'd/m/Y': '<?php echo date('d/m/Y'); ?>',
        'm/d/Y': '<?php echo date('m/d/Y'); ?>',
        'Y-m-d': '<?php echo date('Y-m-d'); ?>',
        'd-m-Y': '<?php echo date('d-m-Y'); ?>',
        'd M Y': '<?php echo date('d M Y'); ?>',
        'F d, Y': '<?php echo date('F d, Y'); ?>'
    };
    
    if (dateFormat && datePreview) {
        dateFormat.addEventListener('change', function() {
            datePreview.textContent = dateFormats[this.value];
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>

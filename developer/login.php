<?php
/**
 * Developer Panel - Login darurat.
 * Jalur utama: ADF Store → menu Developer (tiket sekali pakai, lihat sso.php).
 * Jalur ini tetap ada sebagai cadangan, dengan: batas percobaan (5x / 15 menit),
 * pesan error umum, dan kode verifikasi ke email akun developer.
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once __DIR__ . '/includes/sso_lib.php';

$auth = new DevAuth();

// Already logged in? Go to dashboard
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['dev_csrf'])) {
    $_SESSION['dev_csrf'] = bin2hex(random_bytes(32));
}
$csrfOk = hash_equals($_SESSION['dev_csrf'], (string) ($_POST['csrf'] ?? ''));

$error = '';
$step = !empty($_SESSION['dev_pending']) ? 'otp' : 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['step'] ?? '') === 'cancel') {
        unset($_SESSION['dev_pending']);
        $step = 'password';
    } elseif ($step === 'otp') {
        $pendingName = (string) $_SESSION['dev_pending']['username'];
        [$ok, $msg] = dev_sec_otp_verify(preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($ok) {
            $res = $auth->getConnection()->prepare("SELECT u.*, r.role_code FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.is_active = 1 AND r.role_code = 'developer'");
            $res->execute([(int) $_SESSION['dev_pending']['user_id']]);
            $devUser = $res->fetch(PDO::FETCH_ASSOC);
            if ($devUser) {
                dev_sec_clear_fails($pendingName);
                $auth->completeLogin($devUser);
                header('Location: index.php');
                exit;
            }
            unset($_SESSION['dev_pending']);
            $error = 'Akun tidak lagi aktif.';
        } else {
            dev_sec_record_fail($pendingName);
            if (dev_sec_is_locked($pendingName)) {
                unset($_SESSION['dev_pending']);
            }
            $error = $msg;
        }
        $step = !empty($_SESSION['dev_pending']) ? 'otp' : 'password';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Username dan password harus diisi';
        } elseif (dev_sec_is_locked($username)) {
            $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
        } else {
            $result = $auth->verifyCredentials($username, $password);
            if (!$result['success']) {
                dev_sec_record_fail($username);
                $error = $result['message'];
            } elseif (dev_sec_otp_bypassed()) {
                // Mode darurat (file adf-otp-disabled.txt dibuat lewat cPanel): tanpa kode email.
                dev_sec_clear_fails($username);
                $auth->completeLogin($result['user']);
                header('Location: index.php');
                exit;
            } else {
                session_regenerate_id(true);
                if (dev_sec_otp_send($result['user'])) {
                    $step = 'otp';
                } else {
                    unset($_SESSION['dev_pending']);
                    $error = 'Kode verifikasi tidak bisa dikirim (email akun developer belum diisi / SMTP bermasalah). Masuk lewat ADF Store.';
                }
            }
        }
    }
}
$csrf = $_SESSION['dev_csrf'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Login - ADF System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --dev-primary: #6f42c1;
            --dev-secondary: #8b5cf6;
            --dev-dark: #1a1a2e;
            --dev-darker: #16162a;
        }
        
        body {
            background: linear-gradient(135deg, var(--dev-dark) 0%, var(--dev-darker) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header .dev-badge {
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .login-header h1 {
            color: white;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .login-header p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }
        
        .form-label {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .form-control {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 14px 18px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--dev-primary);
            box-shadow: 0 0 0 3px rgba(111, 66, 193, 0.25);
            color: white;
        }
        
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-right: none;
            color: rgba(255, 255, 255, 0.6);
            border-radius: 12px 0 0 12px;
        }
        
        .input-group .form-control {
            border-radius: 0 12px 12px 0;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(111, 66, 193, 0.4);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 12px 16px;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s;
        }
        
        .back-link a:hover {
            color: white;
        }
        
        .terminal-effect {
            font-family: 'Consolas', 'Monaco', monospace;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: #00ff00;
            border-left: 3px solid var(--dev-primary);
        }
        
        .terminal-effect span {
            color: var(--dev-secondary);
        }
        
        /* Eye icon toggle button styling */
        #togglePassword {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        #togglePassword:hover {
            background-color: rgba(111, 66, 193, 0.2);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <span class="dev-badge"><i class="bi bi-code-slash"></i> Developer Access</span>
            <h1>ADF System</h1>
            <p>Developer Control Panel</p>
        </div>
        
        <div class="terminal-effect">
            <span>$</span> Connecting to system... <i class="bi bi-check-circle-fill text-success"></i>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($step === 'otp'): ?>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <p style="font-size:13px;color:rgba(255,255,255,.7);">Kode 6 digit sudah dikirim ke email akun developer. Berlaku 10 menit.</p>
            <div class="mb-4">
                <label class="form-label">Kode Verifikasi</label>
                <input type="text" class="form-control" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code" style="font-size:22px;letter-spacing:8px;text-align:center;">
            </div>
            <button type="submit" class="btn btn-login">
                <i class="bi bi-shield-check me-2"></i>Verifikasi &amp; Masuk
            </button>
        </form>
        <form method="POST" action="" style="text-align:center;margin-top:10px;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="step" value="cancel">
            <button type="submit" style="background:none;border:0;color:rgba(255,255,255,.6);font-size:12px;text-decoration:underline;">Batal / ganti akun</button>
        </form>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" name="username" placeholder="Enter username" required autofocus autocomplete="username">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="passwordField" name="password" placeholder="Enter password" required autocomplete="current-password">
                    <button type="button" class="btn btn-outline-light" id="togglePassword" style="border: 1px solid rgba(255,255,255,0.15); border-left: none; border-radius: 0 12px 12px 0;">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>Lanjut
            </button>
        </form>
        <p style="font-size:12px;color:rgba(255,255,255,.55);text-align:center;margin:14px 0 0;">
            Disarankan masuk lewat <a href="https://adfsystem.store/admin/" style="color:#a78bfa;">ADF Store</a> → menu Developer.
        </p>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="../login.php"><i class="bi bi-arrow-left me-1"></i>Back to Main Login</a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword') && document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('passwordField');
            const icon = this.querySelector('i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    </script>
</body>
</html>

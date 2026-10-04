<?php
/**
 * Developer Authentication & Authorization
 * Only users with 'developer' role can access developer panel
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

class DevAuth {
    private $pdo;
    private $user = null;
    
    public function __construct() {
        $this->startSession();
        
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed");
        }
    }
    
    private function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('DEV_SESSION');
            session_start();
        }
    }
    
    /**
     * Cek username + password developer TANPA membuat sesi (dipakai login 2 langkah).
     * Pesan error sengaja umum supaya tidak membocorkan username mana yang terdaftar.
     * @return array{success: bool, message: string, user?: array}
     */
    public function verifyCredentials($username, $password) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.*, r.role_code 
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE u.username = ? AND u.is_active = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $generic = ['success' => false, 'message' => 'Username atau password salah.'];
            if (!$user || $user['role_code'] !== 'developer') {
                password_verify((string) $password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
                return $generic;
            }

            // Password bcrypt; hash md5 lama masih diterima sekali lalu langsung di-upgrade ke bcrypt.
            if (password_verify($password, $user['password'])) {
                // ok
            } elseif (hash_equals((string) $user['password'], md5($password))) {
                try {
                    $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
                } catch (Exception $e) {
                }
            } else {
                return $generic;
            }
            return ['success' => true, 'message' => 'OK', 'user' => $user];
        } catch (PDOException $e) {
            error_log('DevAuth verifyCredentials: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan database.'];
        }
    }

    /** Buat sesi developer (setelah lolos verifikasi email, atau lewat tiket SSO ADF Store). */
    public function completeLogin(array $user, $via = 'login') {
        session_regenerate_id(true);
        unset($_SESSION['dev_pending']);
        $_SESSION['dev_user_id'] = $user['id'];
        $_SESSION['dev_username'] = $user['username'];
        $_SESSION['dev_full_name'] = $user['full_name'];
        $_SESSION['dev_logged_in'] = true;
        $_SESSION['dev_login_time'] = time();
        $_SESSION['dev_last_activity_update'] = time();

        try {
            $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        } catch (Exception $e) {
            // Ignore if column doesn't exist
        }
        $this->logAction($via === 'sso' ? 'login_sso' : 'login', 'users', $user['id']);
    }

    /**
     * Authenticate developer login (satu langkah; dipertahankan untuk kompatibilitas).
     */
    public function login($username, $password) {
        $result = $this->verifyCredentials($username, $password);
        if (!$result['success']) {
            return $result;
        }
        $this->completeLogin($result['user']);
        return ['success' => true, 'message' => 'Login berhasil', 'user' => $result['user']];
    }
    
    /**
     * Check if developer is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['dev_logged_in']) && $_SESSION['dev_logged_in'] === true;
    }
    
    /**
     * Require login - redirect if not logged in
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
        
        // Update last activity (every 5 minutes to reduce DB load)
        $lastUpdate = $_SESSION['dev_last_activity_update'] ?? 0;
        if (time() - $lastUpdate > 300) { // 5 minutes
            try {
                $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$_SESSION['dev_user_id']]);
                $_SESSION['dev_last_activity_update'] = time();
            } catch (Exception $e) {}
        }
    }
    
    /**
     * Get current developer user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        if ($this->user === null) {
            $stmt = $this->pdo->prepare("
                SELECT u.*, r.role_code, r.role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['dev_user_id']]);
            $this->user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $this->user;
    }
    
    /**
     * Logout developer
     */
    public function logout() {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Log action to audit
     */
    public function logAction($action, $entityType = null, $entityId = null, $oldValue = null, $newValue = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['dev_user_id'] ?? null,
                $action,
                $entityType,
                $entityId,
                $oldValue ? json_encode($oldValue) : null,
                $newValue ? json_encode($newValue) : null,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {
            // Silently fail - don't break main operation
        }
    }
}

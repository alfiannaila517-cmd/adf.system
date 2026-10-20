<?php

declare(strict_types=1);

/**
 * Staff Portal sistem lama (modules/payroll/staff-portal.php?b=<slug>): akun login karyawan per bisnis.
 * Tersimpan di DATABASE BISNIS: staff_accounts (employee_id → payroll_employees.id, email = username, password_hash).
 * Panel developer lama juga menyimpan plain_password (teks asli); Console tidak — kolom itu dikosongkan.
 */
final class StaffPortal
{
    /** Database bisnis lama untuk bisnis Console ini (db_name), atau null bila belum terhubung. */
    public static function db(array $biz): ?PDO
    {
        if (!Legacy::linked($biz) || empty($biz['db_name'])) {
            return null;
        }
        return Db::legacyBusiness((string)$biz['db_name']);
    }

    /** Alamat portal untuk dibagikan ke staf. */
    public static function url(array $biz): string
    {
        $st = Legacy::db()->prepare('SELECT slug FROM businesses WHERE id = ?');
        $st->execute([(int)$biz['legacy_business_id']]);
        $base = rtrim((string)Secrets::get('LEGACY_BASE_URL', 'https://staging.adfsystem.online'), '/');
        return $base . '/modules/payroll/staff-portal.php?b=' . rawurlencode((string)$st->fetchColumn());
    }

    /** Pastikan tabel akun ada (bentuk sama dengan developer/staff-accounts.php). */
    private static function ensureTable(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS staff_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            plain_password VARCHAR(255) NULL,
            last_login DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emp (employee_id),
            KEY idx_emp (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Karyawan aktif + akun portalnya.
     * @return array<int, array> kunci = payroll_employees.id
     */
    public static function employees(PDO $db): array
    {
        if (!$db->query("SHOW TABLES LIKE 'payroll_employees'")->fetch()) {
            return [];
        }
        self::ensureTable($db);
        $rows = $db->query('SELECT e.id, e.employee_code, e.full_name, e.position, e.department, e.phone, e.is_active,
                sa.id AS account_id, sa.email AS username, sa.last_login
            FROM payroll_employees e LEFT JOIN staff_accounts sa ON sa.employee_id = e.id
            WHERE e.is_active = 1 ORDER BY e.full_name')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** Buat akun portal untuk karyawan. */
    public static function create(PDO $db, int $employeeId, string $username, string $password): void
    {
        self::ensureTable($db);
        $username = trim($username);
        if (mb_strlen($username) < 3) {
            throw new RuntimeException('Username portal minimal 3 karakter.');
        }
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('Password portal minimal 6 karakter.');
        }
        $emp = $db->prepare('SELECT id FROM payroll_employees WHERE id = ? AND is_active = 1');
        $emp->execute([$employeeId]);
        if (!$emp->fetchColumn()) {
            throw new RuntimeException('Karyawan tidak ditemukan / tidak aktif.');
        }
        $dup = $db->prepare('SELECT employee_id FROM staff_accounts WHERE LOWER(email) = LOWER(?) OR employee_id = ?');
        $dup->execute([$username, $employeeId]);
        if ($dup->fetchColumn() !== false) {
            throw new RuntimeException('Username sudah dipakai, atau karyawan ini sudah punya akun.');
        }
        $db->prepare('INSERT INTO staff_accounts (employee_id, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$employeeId, $username, password_hash($password, PASSWORD_DEFAULT)]);
    }

    /** Password baru (plain_password lama dikosongkan supaya panel lama tidak menampilkan password usang). */
    public static function resetPassword(PDO $db, int $employeeId, string $password): void
    {
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('Password portal minimal 6 karakter.');
        }
        $hasPlain = (bool)$db->query("SHOW COLUMNS FROM staff_accounts LIKE 'plain_password'")->fetch();
        $st = $db->prepare('UPDATE staff_accounts SET password_hash = ?' . ($hasPlain ? ', plain_password = NULL' : '') . ' WHERE employee_id = ?');
        $st->execute([password_hash($password, PASSWORD_DEFAULT), $employeeId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Karyawan ini belum punya akun portal.');
        }
    }

    public static function delete(PDO $db, int $employeeId): void
    {
        $db->prepare('DELETE FROM staff_accounts WHERE employee_id = ?')->execute([$employeeId]);
    }
}

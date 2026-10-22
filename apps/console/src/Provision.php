<?php

declare(strict_types=1);

/**
 * Bisnis baru di Console → otomatis dibuat di sistem lama (meniru developer/businesses.php: register + setup_database),
 * tanpa langkah manual:
 *   1. baris businesses di master (+ 3 akun kas awal), 2. CREATE DATABASE, 3. tabel (salin struktur dari bisnis
 *   sejenis yang sudah jalan; travel = database/sunsea-setup.sql) + data awal panel lama, 4. config/businesses/<slug>.php,
 *   5. aktifkan, hubungkan ke Console, kirim menu & akses.
 * Hanya database lokal berawalan LEGACY_DB_PREFIX (lihat Db::legacyBusiness).
 */
final class Provision
{
    /** Jenis Console → business_type sistem lama (enum: hotel, restaurant, retail, manufacture, tourism, travel_bureau, other). */
    private const TYPE_MAP = ['hotel' => 'hotel', 'restaurant' => 'restaurant', 'travel' => 'travel_bureau', 'manufacture' => 'manufacture'];

    /** Tema bawaan per jenis (sama dengan panel developer lama). */
    private const THEME = [
        'hotel' => ['#4338ca', '#1e1b4b', '🏨'],
        'restaurant' => ['#dc2626', '#7f1d1d', '🍽️'],
        'manufacture' => ['#4f46e5', '#312e81', '🏭'],
        'travel_bureau' => ['#0EA5E9', '#0C4A6E', '🌊'],
        'other' => ['#059669', '#065f46', '🏢'],
    ];

    public static function can(array $biz): bool
    {
        return empty($biz['legacy_business_id']) && $biz['type'] !== 'website' && empty($biz['hosted_elsewhere'])
            && Db::legacyMaster() !== null && Legacy::appDir() !== null;
    }

    /** @return string[] laporan langkah */
    public static function run(array $biz): array
    {
        if (!self::can($biz)) {
            throw new RuntimeException('Bisnis ini sudah terhubung, berjenis website, atau sistem lama belum diatur di server ini.');
        }
        $m = Legacy::db();
        $log = [];
        $slug = (string)$biz['slug'];
        $type = self::TYPE_MAP[$biz['type']] ?? 'other';
        $suffix = str_replace('-', '_', $slug);
        $realDb = (string)Secrets::get('LEGACY_DB_PREFIX', 'adfb2574_') . $suffix;
        $sdb = null;

        // 1. Daftar di master (atau pakai yang sudah ada dengan slug sama)
        $st = $m->prepare('SELECT * FROM businesses WHERE slug = ?');
        $st->execute([$slug]);
        $lb = $st->fetch();
        if ($lb) {
            $lbid = (int)$lb['id'];
            $realDb = Legacy::businessDbName($lb);
            $log[] = "bisnis '{$slug}' sudah ada di sistem — dipakai";
        } else {
            $code = substr(strtoupper(preg_replace('/[^A-Z0-9]/i', '', $slug)), 0, 26) ?: 'BIZ';
            for ($i = 2, $base = $code; $m->query('SELECT 1 FROM businesses WHERE business_code = ' . $m->quote($code))->fetchColumn(); $i++) {
                $code = $base . $i;
            }
            $m->prepare('INSERT INTO businesses (business_code, slug, business_name, business_type, database_name, owner_id, description, addon_domain, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
                ->execute([$code, $slug, $biz['name'], $type, $realDb, self::ownerId($biz), $biz['notes'] ?: null, Legacy::normalizeDomain((string)$biz['domain']) ?: null]);
            $lbid = (int)$m->lastInsertId();
            $cash = $m->prepare('INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, ?, ?, 0, ?, ?, 1)');
            $cash->execute([$lbid, 'Petty Cash', 'cash', 1, 'Uang cash dari tamu / operasional']);
            $cash->execute([$lbid, 'Bank', 'bank', 0, 'Rekening bank utama bisnis']);
            $cash->execute([$lbid, 'Kas Modal Owner', 'owner_capital', 0, 'Modal operasional dari owner']);
            $log[] = "terdaftar di sistem (kode {$code})";
        }

        // 2. Database
        $m->exec("CREATE DATABASE IF NOT EXISTS `{$realDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $sdb = Db::legacyBusiness($realDb);
        $log[] = "database {$realDb} siap";

        // 3. Tabel + data awal
        $log[] = $type === 'travel_bureau' ? self::sunseaSchema($sdb, $biz) : self::cloneSchema($sdb, $biz, $realDb);
        self::seed($sdb, $biz, $type);

        // 4. File config (dibaca sistem lama untuk mengenali bisnis & database-nya)
        $file = Legacy::appDir() . '/config/businesses/' . basename($slug) . '.php';
        if (!is_file($file)) {
            [$c1, $c2, $icon] = self::THEME[$type] ?? self::THEME['other'];
            $cfg = [
                'business_id' => $slug, 'name' => $biz['name'], 'business_type' => $type, 'database' => 'adf_' . $suffix, 'logo' => '',
                'enabled_modules' => ['auth'], 'theme' => ['color_primary' => $c1, 'color_secondary' => $c2, 'icon' => $icon],
                'cashbook_columns' => [], 'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true],
            ];
            if (file_put_contents($file, "<?php\n// Dibuat otomatis oleh ADF Console " . date('Y-m-d H:i') . "\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false) {
                throw new RuntimeException("Tidak bisa menulis {$file}");
            }
            $log[] = 'file config dibuat';
        }

        // 5. Aktifkan, hubungkan, kirim menu & akses
        $m->prepare('UPDATE businesses SET is_active = 1 WHERE id = ?')->execute([$lbid]);
        Auth::db()->prepare('UPDATE businesses SET legacy_business_id = ?, db_name = ? WHERE id = ?')->execute([$lbid, $realDb, (int)$biz['id']]);
        $fresh = Biz::business((int)$biz['id']);
        Access::ensureRoles($fresh);
        $log = array_merge($log, Legacy::exportModules($fresh), Legacy::export($fresh));
        $log[] = 'aktif & terhubung';
        return $log;
    }

    /** owner_id wajib di tabel lama: owner grup → anggota berperan pemilik → akun developer. */
    private static function ownerId(array $biz): int
    {
        $st = Auth::db()->prepare('SELECT p.legacy_user_id FROM client_owners co JOIN people p ON p.id = co.person_id WHERE co.client_id = ? AND p.legacy_user_id IS NOT NULL LIMIT 1');
        $st->execute([(int)$biz['client_id']]);
        $id = (int)$st->fetchColumn();
        if (!$id) {
            $id = (int)Legacy::db()->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_code = 'developer' ORDER BY u.id LIMIT 1")->fetchColumn();
        }
        return $id ?: 1;
    }

    /** Salin struktur semua tabel dari bisnis sejenis yang sudah terhubung (tanpa data). */
    private static function cloneSchema(PDO $sdb, array $biz, string $realDb): string
    {
        $st = Auth::db()->prepare('SELECT db_name FROM businesses WHERE legacy_business_id IS NOT NULL AND db_name IS NOT NULL AND id <> ? ORDER BY type = ? DESC, type = \'hotel\' DESC LIMIT 1');
        $st->execute([(int)$biz['id'], (string)$biz['type']]);
        $tpl = (string)$st->fetchColumn();
        if ($tpl === '' || $tpl === $realDb) {
            return 'belum ada bisnis contoh — tabel dibuat sendiri oleh sistem saat dibuka';
        }
        Db::legacyBusiness($tpl); // validasi awalan nama
        $have = array_flip($sdb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $n = 0;
        $skipped = [];
        foreach (Legacy::db()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . Legacy::db()->quote($tpl) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (isset($have[$t])) {
                continue;
            }
            try {
                $sdb->exec("CREATE TABLE `{$t}` LIKE `{$tpl}`.`{$t}`");
                $n++;
            } catch (Throwable) {
                $skipped[] = $t; // tabel contoh rusak/tidak terbaca — dibuat sendiri oleh sistem saat dibutuhkan
            }
        }
        return "{$n} tabel disalin strukturnya dari {$tpl} (tanpa data)" . ($skipped ? ' · dilewati: ' . implode(', ', $skipped) : '');
    }

    /** Travel bureau: setup Sunsea milik sistem lama (sama seperti panel developer). */
    private static function sunseaSchema(PDO $sdb, array $biz): string
    {
        $file = Legacy::appDir() . '/database/sunsea-setup.sql';
        if (!is_file($file)) {
            throw new RuntimeException('database/sunsea-setup.sql tidak ditemukan di kode sistem lama');
        }
        $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $sdb->exec($stmt);
        }
        try {
            $sdb->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'company_name'")->execute([$biz['name']]);
        } catch (Throwable) {
        }
        return 'tabel travel dibuat dari setup Sunsea';
    }

    /** Data awal seperti panel developer lama (INSERT IGNORE; dilewati bila tabel/kolom berbeda). */
    private static function seed(PDO $sdb, array $biz, string $type): void
    {
        $branch = strtolower(str_replace(' ', '-', (string)$biz['name']));
        $q = fn (string $v) => $sdb->quote($v);
        $try = function (string $sql) use ($sdb): void {
            try {
                $sdb->exec($sql);
            } catch (Throwable) {
                // struktur tabel di bisnis contoh bisa sedikit berbeda — data awal ini tidak wajib
            }
        };
        $try("INSERT IGNORE INTO roles (id, role_name, role_code, description, is_system_role) VALUES
            (1,'Admin','admin','System administrator',1),(2,'Manager','manager','Business manager',1),(3,'Staff','staff','Regular staff',1),
            (4,'Developer','developer','System developer',1),(5,'Owner','owner','Business owner',1)");
        $b = $q($branch);
        $try("INSERT IGNORE INTO divisions (id, branch_id, division_code, division_name, division_type) VALUES
            (1,{$b},'KITCHEN','Kitchen','both'),(2,{$b},'BAR','Bar','both'),(3,{$b},'RESTO','Resto','both'),(4,{$b},'HOUSEKEEPING','Housekeeping','expense'),
            (5,{$b},'HOTEL','Hotel','income'),(6,{$b},'GARDENER','Gardener','expense'),(7,{$b},'OTHERS','Lain-lain','both'),(8,{$b},'PC','Petty Cash','both')");
        $try("INSERT IGNORE INTO categories (branch_id, division_id, category_name, category_type, description) VALUES
            ({$b},1,'Food Sales','income','Revenue from food sales'),({$b},1,'Food Supplies','expense','Purchase of food ingredients'),
            ({$b},2,'Beverage Sales','income','Revenue from beverage sales'),({$b},2,'Beverage Inventory','expense','Purchase of beverages'),
            ({$b},3,'Room Rental','income','Room rental income'),({$b},3,'Staff Salary','expense','Employee salaries'),
            ({$b},4,'Housekeeping Service','income','Cleaning services'),({$b},4,'Room Supplies','expense','Room cleaning supplies'),
            ({$b},5,'Hotel Income','income','Hotel revenue'),({$b},5,'Hotel Expense','expense','Hotel operational expenses'),
            ({$b},7,'Other Income','income','Miscellaneous income'),({$b},7,'Other Expense','expense','Miscellaneous expenses')");
        $try('INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
            (\'business_name\',' . $q((string)$biz['name']) . ',\'string\',\'Business name\'),(\'business_type\',' . $q($type) . ',\'string\',\'Business type\'),
            (\'currency\',\'IDR\',\'string\',\'Currency\'),(\'timezone\',\'Asia/Jakarta\',\'string\',\'Timezone\'),(\'date_format\',\'d/m/Y\',\'string\',\'Date format\'),
            (\'fiscal_year_start\',\'01\',\'string\',\'Fiscal year start month\'),(\'show_running_balance\',\'1\',\'boolean\',\'Show running balance\'),
            (\'show_daily_total\',\'1\',\'boolean\',\'Show daily total\'),(\'enable_shift\',\'1\',\'boolean\',\'Enable shift\'),(\'enable_approval\',\'0\',\'boolean\',\'Require approval\'),
            (\'min_kas_awal\',\'0\',\'string\',\'Minimum kas awal\'),(\'kas_awal_default\',\'0\',\'string\',\'Default kas awal\'),(\'print_receipt\',\'1\',\'boolean\',\'Enable print receipt\')');
        if ($type === 'hotel') {
            $try("INSERT IGNORE INTO room_types (id, type_name, base_price, max_occupancy, description, color_code) VALUES
                (1,'Standard',500000,2,'Standard room','#6366f1'),(2,'Deluxe',750000,2,'Deluxe room','#10b981'),(3,'Suite',1200000,4,'Suite room','#f59e0b')");
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Jembatan Console ↔ sistem lama (database master adf_system / adfb2574_adf, lihat Db::legacyMaster()).
 *
 *   import()   tabel lama → Console (bisnis, modul, orang, peran + pengecualian) — hasilnya harus identik dengan izin lama
 *   desired()  baris tabel lama yang SEHARUSNYA ada menurut Console (dipakai pratinjau, cek beda, dan nanti ekspor)
 *   diff()     bandingkan desired() dengan isi tabel lama sekarang
 *
 * Aturan izin sistem lama (includes/auth.php hasPermission): menu boleh dibuka bila ada baris can_view = 1;
 * baris yang tidak ada = tidak boleh; KECUALI peran developer/admin/owner tanpa satu baris pun di bisnis itu = boleh semua.
 * User berperan developer (Tim ADF) tidak pernah diimpor maupun diubah oleh jembatan ini.
 */
final class Legacy
{
    /**
     * Bisnis yang di kode lama (includes/auth.php hasPermission) selalu membuka PO Internal & Stok Masuk untuk semua orang.
     * Diimpor sebagai izin Lihat minimal supaya Console menunjukkan akses yang sebenarnya; di VPS aturan mati itu
     * dimatikan (ADF_CONSOLE_ACCESS di config/local-db-config.php, branch vps-legacy) sehingga Console yang mengatur.
     */
    private const PO_ALWAYS_BIZ = ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet'];

    /** Peran global lama yang boleh Owner Login (owner-login.php). */
    private const OWNER_LOGIN_ROLES = ['owner', 'admin'];

    public static function db(): PDO
    {
        return Db::legacyMaster() ?? throw new RuntimeException('LEGACY_MASTER_DB belum diatur di secrets/legacy.env');
    }

    /** Jenis bisnis lama → jenis Console. */
    public static function mapType(string $legacyType, string $slug): string
    {
        if (str_contains($slug, 'gudang')) {
            return 'warehouse';
        }
        return match ($legacyType) {
            'hotel' => 'hotel',
            'restaurant' => 'restaurant',
            'manufacture' => 'manufacture',
            'contractor' => 'contractor',
            'tourism', 'travel_bureau' => 'travel',
            default => 'other',
        };
    }

    /* ------------------------------------------------------------------ baca tabel lama */

    /**
     * Kondisi akses di tabel lama untuk satu bisnis lama (developer dilewati).
     * @return array<int, array{username:string, name:string, email:string, role:string, active:bool, assigned:bool, rows:int, levels:array<string,int>, inexact:string[], unknown:string[]}>
     */
    public static function current(int $legacyBizId): array
    {
        $db = self::db();
        $out = [];
        $users = $db->prepare('SELECT u.id, u.username, u.full_name, u.email, u.is_active, COALESCE(r.role_code, \'staff\') AS role_code
            FROM users u LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id IN (SELECT user_id FROM user_business_assignment WHERE business_id = ?)
               OR u.id IN (SELECT user_id FROM user_menu_permissions WHERE business_id = ?)');
        $users->execute([$legacyBizId, $legacyBizId]);
        $assigned = $db->prepare('SELECT user_id FROM user_business_assignment WHERE business_id = ?');
        $assigned->execute([$legacyBizId]);
        $assignedIds = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));

        foreach ($users->fetchAll() as $u) {
            if ($u['role_code'] === 'developer') {
                continue;
            }
            $out[(int)$u['id']] = [
                'username' => (string)$u['username'], 'name' => (string)($u['full_name'] ?: $u['username']), 'email' => trim((string)$u['email']),
                'role' => (string)$u['role_code'], 'active' => (bool)$u['is_active'], 'assigned' => in_array((int)$u['id'], $assignedIds, true),
                'rows' => 0, 'levels' => array_fill_keys(array_keys(Catalog::MODULES), 0), 'inexact' => [], 'unknown' => [],
            ];
        }
        $perm = $db->prepare('SELECT user_id, menu_code, can_view, can_create, can_edit, can_delete FROM user_menu_permissions WHERE business_id = ?');
        $perm->execute([$legacyBizId]);
        foreach ($perm->fetchAll() as $p) {
            $uid = (int)$p['user_id'];
            if (!isset($out[$uid])) {
                continue;
            }
            $out[$uid]['rows']++;
            $code = (string)$p['menu_code'];
            $lv = Catalog::levelFromFlags((int)$p['can_view'], (int)$p['can_create'], (int)$p['can_edit'], (int)$p['can_delete'], $exact);
            if (!isset(Catalog::MODULES[$code])) {
                if ($lv > 0) {
                    $out[$uid]['unknown'][] = $code;
                }
                continue;
            }
            if (!$exact) {
                $out[$uid]['inexact'][] = $code;
            }
            $out[$uid]['levels'][$code] = $lv;
        }
        // Celah lama: developer/admin/owner tanpa baris izin sama sekali = boleh semua menu
        // Aturan mati lama (includes/auth.php): PO Internal & Stok Masuk selalu boleh DILIHAT siapa pun di 3 bisnis ini
        $st = $db->prepare('SELECT slug FROM businesses WHERE id = ?');
        $st->execute([$legacyBizId]);
        $poAlways = in_array(preg_replace('/[^a-z0-9]/', '', strtolower((string)$st->fetchColumn())), self::PO_ALWAYS_BIZ, true);
        foreach ($out as &$u) {
            if ($u['rows'] === 0 && in_array($u['role'], ['admin', 'owner'], true)) {
                $u['levels'] = array_fill_keys(array_keys(Catalog::MODULES), Catalog::MAX_LEVEL);
            }
            if ($poAlways) {
                foreach (['procurement_po', 'procurement_stock'] as $code) {
                    $u['levels'][$code] = max(1, $u['levels'][$code]);
                }
            }
        }
        return $out;
    }

    /** Modul yang menyala di bisnis lama (business_menu_config). */
    public static function enabledModules(int $legacyBizId): array
    {
        $st = self::db()->prepare('SELECT mi.menu_code FROM business_menu_config bmc JOIN menu_items mi ON mi.id = bmc.menu_id WHERE bmc.business_id = ? AND bmc.is_enabled = 1');
        $st->execute([$legacyBizId]);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn ($c) => isset(Catalog::MODULES[$c])));
    }

    /* ------------------------------------------------------------------ impor */

    /**
     * Impor bisnis lama (berdasarkan slug) ke client Console $clientName. $dryRun = jalankan lalu batalkan.
     * @return string[] laporan
     */
    public static function import(array $slugs, string $clientName, bool $dryRun): array
    {
        $con = Auth::db();
        $log = [];
        $con->beginTransaction();
        try {
            $clientId = self::ensureClient($clientName, $log);
            foreach ($slugs as $slug) {
                $st = self::db()->prepare('SELECT * FROM businesses WHERE slug = ?');
                $st->execute([$slug]);
                $lb = $st->fetch();
                if (!$lb) {
                    $log[] = "!! bisnis lama '{$slug}' tidak ada — dilewati";
                    continue;
                }
                $biz = self::ensureBusiness($lb, $clientId, $log);
                $people = self::current((int)$lb['id']);

                // Modul: yang menyala di sistem lama + yang masih diberi izin ke seseorang (supaya tidak ada izin yang hilang)
                $mods = self::enabledModules((int)$lb['id']);
                foreach ($people as $p) {
                    if ($p['assigned']) {
                        $mods = array_merge($mods, array_keys(array_filter($p['levels'])));
                    }
                }
                // Pilihan Modul & Fitur yang sudah diatur di Console dipertahankan saat impor ulang (mis. hari pindahan)
                $current = Biz::modules($biz);
                if ($current['custom']) {
                    $log[] = '   modul: pilihan Console dipertahankan';
                } else {
                    Biz::saveModules($biz, array_values(array_unique($mods)), array_keys(array_filter($current['feature'])));
                }
                Access::ensureRoles($biz);
                $roleOf = self::deriveRoles($biz, array_filter($people, fn ($p) => $p['assigned']), $log);
                $roles = Access::roles((int)$biz['id']);

                $keep = [];
                foreach ($people as $uid => $p) {
                    if (!$p['assigned']) {
                        $log[] = "   - {$p['username']}: punya baris izin tapi tidak ditugaskan ke bisnis ini — dilewati (sama seperti sistem lama: tidak bisa memilih bisnis ini)";
                        continue;
                    }
                    $personId = self::ensurePerson($uid, $p);
                    $roleId = $roleOf[$p['role']];
                    $over = self::overrides($roles[$roleId], $p['levels']);
                    $portalOwner = in_array($p['role'], self::OWNER_LOGIN_ROLES, true);
                    Access::setMembership((int)$biz['id'], $personId, $roleId, $portalOwner, false);
                    $con->prepare('UPDATE memberships SET overrides = ? WHERE business_id = ? AND person_id = ?')
                        ->execute([$over ? json_encode($over) : null, (int)$biz['id'], $personId]);
                    $keep[] = $personId;
                    $log[] = sprintf('   + %-12s %-8s → %s%s%s', $p['username'], "({$p['role']})", $roles[$roleId]['name'], $over ? ' +' . count($over) . ' pengecualian' : '', $portalOwner ? ' · portal owner' : '')
                        . ($p['inexact'] ? ' · DIBULATKAN: ' . implode(',', $p['inexact']) : '') . ($p['unknown'] ? ' · menu di luar katalog: ' . implode(',', $p['unknown']) : '');
                }
                // Orang hasil impor sebelumnya yang sudah tidak punya akses di sistem lama
                $st = $con->prepare('SELECT m.person_id FROM memberships m JOIN people p ON p.id = m.person_id WHERE m.business_id = ? AND p.legacy_user_id IS NOT NULL');
                $st->execute([(int)$biz['id']]);
                foreach (array_diff(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $keep) as $gone) {
                    Access::removeMembership((int)$biz['id'], $gone);
                    $log[] = "   - orang #{$gone} sudah tidak punya akses di sistem lama — akses Console dicabut";
                }
            }
            $dryRun ? $con->rollBack() : $con->commit();
            $log[] = $dryRun ? '(UJI COBA — tidak ada yang disimpan)' : 'Tersimpan.';
        } catch (Throwable $e) {
            $con->rollBack();
            throw $e;
        }
        return $log;
    }

    private static function ensureClient(string $name, array &$log): int
    {
        $con = Auth::db();
        $st = $con->prepare('SELECT id FROM clients WHERE name = ? AND is_demo = 0 LIMIT 1');
        $st->execute([$name]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
        $con->prepare('INSERT INTO clients (name, notes) VALUES (?, ?)')->execute([$name, 'Dibuat oleh impor dari sistem lama']);
        $log[] = "Client baru: {$name}";
        return (int)$con->lastInsertId();
    }

    private static function ensureBusiness(array $lb, int $clientId, array &$log): array
    {
        $con = Auth::db();
        $st = $con->prepare('SELECT id FROM businesses WHERE legacy_business_id = ? OR slug = ? ORDER BY legacy_business_id IS NULL LIMIT 1');
        $st->execute([(int)$lb['id'], $lb['slug']]);
        $id = (int)$st->fetchColumn();
        $type = self::mapType((string)$lb['business_type'], (string)$lb['slug']);
        if ($id) {
            $con->prepare('UPDATE businesses SET legacy_business_id = ?, db_name = COALESCE(db_name, ?) WHERE id = ?')->execute([(int)$lb['id'], $lb['database_name'], $id]);
            $log[] = "Bisnis {$lb['business_name']}: sudah ada (#{$id}), diperbarui";
        } else {
            $con->prepare('INSERT INTO businesses (client_id, slug, name, type, status, db_name, domain, legacy_business_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$clientId, $lb['slug'], $lb['business_name'], $type, $lb['is_active'] ? 'active' : 'suspended', $lb['database_name'], $lb['addon_domain'] ?: null, (int)$lb['id']]);
            $id = (int)$con->lastInsertId();
            $log[] = "Bisnis {$lb['business_name']}: dibuat (#{$id}, jenis {$type}, database {$lb['database_name']})";
        }
        return Biz::business($id);
    }

    private static function ensurePerson(int $legacyUserId, array $p): int
    {
        $con = Auth::db();
        $st = $con->prepare('SELECT id FROM people WHERE legacy_user_id = ? OR username = ? ORDER BY legacy_user_id IS NULL LIMIT 1');
        $st->execute([$legacyUserId, $p['username']]);
        $id = (int)$st->fetchColumn();
        // Email wajib unik di Console; email kosong / dipakai orang lain → alamat pengganti yang jelas palsu
        $email = strtolower($p['email']);
        $taken = $con->prepare('SELECT id FROM people WHERE email = ? AND id <> ?');
        $taken->execute([$email, $id]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $taken->fetchColumn()) {
            $email = strtolower($p['username']) . '@legacy.invalid';
        }
        $status = $p['active'] ? 'active' : 'disabled';
        if ($id) {
            $con->prepare('UPDATE people SET name = ?, email = ?, username = ?, legacy_user_id = ?, legacy_role = ?, status = ? WHERE id = ?')
                ->execute([$p['name'], $email, $p['username'], $legacyUserId, $p['role'], $status, $id]);
            return $id;
        }
        $con->prepare('INSERT INTO people (name, email, username, legacy_user_id, legacy_role, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$p['name'], $email, $p['username'], $legacyUserId, $p['role'], $status]);
        return (int)$con->lastInsertId();
    }

    /** Peran global lama → peran bisnis Console: [kode, nama, keterangan]. */
    private const ROLE_MAP = [
        'owner' => ['owner_limited', 'Owner (terbatas)', 'Pemilik dengan izin tertentu (dari sistem lama) + portal owner'],
        'admin' => ['admin', 'Admin', 'Admin bisnis (dari sistem lama) + portal owner'],
        'manager' => ['manager', 'Manager', 'Dari sistem lama'],
        'staff' => ['staff', 'Staff', 'Dari sistem lama'],
    ];

    /**
     * Peran dibentuk dari izin nyata: tiap peran global lama yang dipakai di bisnis ini menjadi satu peran bisnis
     * berisi izin yang paling banyak dipakai orang berperan itu (orang lain berperan sama yang berbeda → pengecualian).
     * Owner dengan izin penuh masuk peran Owner bawaan; owner dengan izin terbatas → "Owner (terbatas)".
     * @return array<string,int> peran lama → id peran Console
     */
    private static function deriveRoles(array $biz, array $people, array &$log): array
    {
        $con = Auth::db();
        $roles = Access::roles((int)$biz['id']);
        $byCode = array_column(array_map(fn ($r) => ['code' => $r['code'], 'id' => (int)$r['id']], $roles), 'id', 'code');
        $groups = [];
        foreach ($people as $p) {
            $groups[$p['role']][] = $p['levels'];
        }
        $out = [];
        $sort = 100;
        foreach ($groups as $legacyRole => $vectors) {
            // Izin yang paling sering dipakai di kelompok ini
            $count = [];
            foreach ($vectors as $v) {
                $count[json_encode($v)] = ($count[json_encode($v)] ?? 0) + 1;
            }
            arsort($count);
            $perms = json_decode((string)array_key_first($count), true);
            if ($legacyRole === 'owner' && !array_diff($perms, [Catalog::MAX_LEVEL]) && isset($byCode['owner'])) {
                $out[$legacyRole] = $byCode['owner']; // pemilik dengan izin penuh = peran Owner bawaan
                continue;
            }
            [$code, $name, $desc] = self::ROLE_MAP[$legacyRole] ?? self::ROLE_MAP['staff'];
            if (isset($byCode[$code])) {
                $con->prepare('UPDATE roles SET permissions = ?, is_owner = 0, description = ? WHERE id = ?')->execute([json_encode($perms), $desc, $byCode[$code]]);
                $out[$legacyRole] = $byCode[$code];
            } else {
                $con->prepare('INSERT INTO roles (business_id, code, name, description, permissions, is_owner, sort) VALUES (?, ?, ?, ?, ?, 0, ?)')
                    ->execute([(int)$biz['id'], $code, $name, $desc, json_encode($perms), $sort++]);
                $out[$legacyRole] = $byCode[$code] = (int)$con->lastInsertId();
            }
            $log[] = "   peran {$name}: diisi dari izin nyata " . count($vectors) . ' orang';
        }
        return $out;
    }

    /** Modul yang izinnya berbeda dari peran → pengecualian per orang (hasil akhir tetap identik). */
    private static function overrides(array $role, array $levels): array
    {
        $over = [];
        foreach ($levels as $code => $lv) {
            if (($role['is_owner'] ? Catalog::MAX_LEVEL : Catalog::levelOf($role['perms'], $code)) !== $lv) {
                $over[$code] = $lv;
            }
        }
        return $over;
    }

    /* ------------------------------------------------------------------ ekspor Console → tabel lama */

    /** Urutan kekuatan peran global lama (users.role_id dipakai bersama untuk semua bisnis). */
    private const ROLE_RANK = ['staff' => 1, 'manager' => 2, 'admin' => 3, 'owner' => 4];

    /**
     * Nama sistem lama yang terhubung, untuk tulisan di layar. Diatur di secrets/legacy.env LEGACY_LABEL;
     * selama uji coba = staging di VPS ini (database lokal VPS, BUKAN hosting). Saat pindah rumah diganti.
     */
    public static function label(): string
    {
        return (string)Secrets::get('LEGACY_LABEL', 'staging.adfsystem.online (salinan uji di VPS ini)');
    }

    /** Bisnis Console yang terhubung ke sistem lama. */
    public static function linked(array $biz): bool
    {
        return !empty($biz['legacy_business_id']) && Db::legacyMaster() !== null;
    }

    /**
     * Tulis kondisi Console satu bisnis ke tabel lama, persis seperti panel developer lama menulisnya:
     * users (baru / peran / aktif), user_business_assignment, dan user_menu_permissions untuk SEMUA modul katalog
     * (yang ditolak ditulis 0000 → aturan lama "tanpa baris = boleh semua" tidak pernah berlaku).
     * User developer & menu di luar katalog tidak disentuh. $passwords = [person_id => password awal] untuk orang baru.
     * @return string[] laporan
     */
    public static function export(array $biz, array $passwords = []): array
    {
        if (!self::linked($biz)) {
            return [];
        }
        $db = self::db();
        $lbid = (int)$biz['legacy_business_id'];
        $roleIds = $db->query('SELECT role_code, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        $menuIds = $db->query('SELECT menu_code, id FROM menu_items')->fetchAll(PDO::FETCH_KEY_PAIR);
        $codes = array_keys(Catalog::MODULES);
        $in = implode(',', array_fill(0, count($codes), '?'));
        $log = [];

        $db->beginTransaction();
        try {
            $want = [];
            foreach (self::desired($biz) as $d) {
                $uid = $d['legacy_user_id'] ?? self::createUser($d, $passwords[$d['person_id']] ?? null, $roleIds, $log);
                if (!$uid) {
                    continue;
                }
                $want[$uid] = true;
                // Peran global = yang terkuat di semua bisnis orang ini (users.role_id satu untuk semua bisnis)
                $role = self::globalRole((int)$d['person_id']);
                $active = self::personStatus((int)$d['person_id']) === 'disabled' ? 0 : 1;
                $u = $db->prepare('SELECT u.role_id, u.is_active, r.role_code FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
                $u->execute([$uid]);
                $cur = $u->fetch();
                if (!$cur || $cur['role_code'] === 'developer') {
                    continue; // user lama sudah dihapus / developer: jangan disentuh
                }
                if (($cur['role_code'] !== $role && isset($roleIds[$role])) || (int)$cur['is_active'] !== $active) {
                    $db->prepare('UPDATE users SET role_id = ?, is_active = ? WHERE id = ?')->execute([$roleIds[$role] ?? $cur['role_id'], $active, $uid]);
                    $log[] = "{$d['name']}: peran global {$cur['role_code']} → {$role}" . ($active ? '' : ' (nonaktif)');
                }
                $db->prepare('INSERT IGNORE INTO user_business_assignment (user_id, business_id, assigned_at) VALUES (?, ?, NOW())')->execute([$uid, $lbid]);
                $db->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ? AND menu_code IN ({$in})")->execute([$uid, $lbid, ...$codes]);
                $ins = $db->prepare('INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($codes as $code) {
                    $ins->execute([$uid, $lbid, $menuIds[$code] ?? null, $code, ...Catalog::legacyFlags($d['levels'][$code] ?? 0)]);
                }
            }
            // Yang punya akses di sistem lama tapi tidak di Console → dicabut dari bisnis ini (user-nya tetap ada)
            foreach (self::current($lbid) as $uid => $u) {
                if ($u['assigned'] && !isset($want[$uid])) {
                    $db->prepare('DELETE FROM user_business_assignment WHERE user_id = ? AND business_id = ?')->execute([$uid, $lbid]);
                    $db->prepare('DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?')->execute([$uid, $lbid]);
                    $log[] = "{$u['username']}: akses ke bisnis ini dicabut";
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $log;
    }

    /** Kode modul Console → nama di 'enabled_modules' file config lama (isModuleEnabled), bila berbeda. */
    private const CONFIG_NAMES = ['cafe_invoice' => 'cafe-invoice', 'sales_invoice' => 'sales'];

    /** Folder kode sistem lama di server ini (secrets/legacy.env LEGACY_APP_DIR), untuk file config/businesses/*.php. */
    public static function appDir(): ?string
    {
        $dir = rtrim((string)Secrets::get('LEGACY_APP_DIR', ''), '/\\');
        return $dir !== '' && is_dir($dir . '/config/businesses') ? $dir : null;
    }

    /**
     * Modul & Fitur Console → sistem lama, supaya menu sidebar mengikuti Console:
     *   1. business_menu_config (menu yang menyala per bisnis, dipakai panel developer & beberapa halaman)
     *   2. 'enabled_modules' di config/businesses/<slug>.php (isModuleEnabled di sidebar: laporan, gaji, frontdesk, …)
     * Nama di enabled_modules yang bukan modul katalog (mis. 'auth') dibiarkan apa adanya.
     * @return string[] laporan
     */
    public static function exportModules(array $biz): array
    {
        if (!self::linked($biz)) {
            return [];
        }
        $mods = Biz::modules($biz)['module'];
        $db = self::db();
        $lbid = (int)$biz['legacy_business_id'];
        $menuIds = $db->query('SELECT menu_code, id FROM menu_items')->fetchAll(PDO::FETCH_KEY_PAIR);
        $up = $db->prepare('INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)');
        foreach (array_keys(Catalog::MODULES) as $code) {
            if (isset($menuIds[$code])) {
                $up->execute([$lbid, $menuIds[$code], !empty($mods[$code]) ? 1 : 0]);
            }
        }
        $log = [];
        $dir = self::appDir();
        $st = $db->prepare('SELECT slug FROM businesses WHERE id = ?');
        $st->execute([$lbid]);
        $file = $dir ? "{$dir}/config/businesses/" . basename((string)$st->fetchColumn()) . '.php' : null;
        if (!$file || !is_file($file)) {
            return ['file config bisnis tidak ditemukan — hanya tabel menu yang diperbarui'];
        }
        $cfg = (static fn (string $f) => require $f)($file);
        if (!is_array($cfg)) {
            throw new RuntimeException('File config bisnis lama tidak berisi array — tidak diubah');
        }
        $managed = [];
        foreach (array_keys(Catalog::MODULES) as $code) {
            $managed[self::CONFIG_NAMES[$code] ?? $code] = !empty($mods[$code]);
        }
        $before = array_values((array)($cfg['enabled_modules'] ?? []));
        $after = array_values(array_filter($before, fn ($m) => !array_key_exists((string)$m, $managed)));
        foreach ($managed as $name => $on) {
            if ($on) {
                $after[] = $name;
            }
        }
        $a = $before;
        $b = $after;
        sort($a);
        sort($b);
        if ($a !== $b) {
            $cfg['enabled_modules'] = $after;
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            $php = "<?php\n// Diperbarui otomatis oleh ADF Console (Modul & Fitur) " . date('Y-m-d H:i') . "\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException("Tidak bisa menulis {$file} (izin folder?)");
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            $on = array_diff($after, $before);
            $off = array_diff($before, $after);
            $log[] = 'menu sidebar diperbarui' . ($on ? ' · nyala: ' . implode(', ', $on) : '') . ($off ? ' · mati: ' . implode(', ', $off) : '');
        }
        return $log;
    }

    /**
     * Data akun orang (nama, email, telepon, username, aktif) → users lama. Tidak menyentuh developer.
     * @return string|null pesan, null bila orang ini belum punya user di sistem lama
     */
    public static function syncAccount(int $personId): ?string
    {
        $st = Auth::db()->prepare('SELECT * FROM people WHERE id = ?');
        $st->execute([$personId]);
        $p = $st->fetch();
        if (!$p || !$p['legacy_user_id'] || Db::legacyMaster() === null) {
            return null;
        }
        $db = self::db();
        $role = $db->prepare('SELECT r.role_code FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $role->execute([(int)$p['legacy_user_id']]);
        if ($role->fetchColumn() === 'developer') {
            throw new RuntimeException('Akun developer tidak diubah dari Console');
        }
        $dup = $db->prepare('SELECT username FROM users WHERE (username = ? OR email = ?) AND id <> ?');
        $dup->execute([$p['username'], $p['email'], (int)$p['legacy_user_id']]);
        if ($other = $dup->fetchColumn()) {
            throw new RuntimeException("Username atau email sudah dipakai user lain di sistem ({$other})");
        }
        $db->prepare('UPDATE users SET full_name = ?, email = ?, username = ?, phone = COALESCE(?, phone), is_active = ? WHERE id = ?')
            ->execute([$p['name'], $p['email'], $p['username'], $p['phone'] ?: null, $p['status'] === 'disabled' ? 0 : 1, (int)$p['legacy_user_id']]);
        return 'Akun di ' . self::label() . ' diperbarui.';
    }

    /**
     * Ganti password login sistem lama. Hanya hash yang disimpan; password_view lama dikosongkan supaya
     * panel developer lama tidak menampilkan password yang sudah usang.
     */
    public static function setPassword(int $personId, string $plain): void
    {
        $st = Auth::db()->prepare('SELECT legacy_user_id FROM people WHERE id = ?');
        $st->execute([$personId]);
        $uid = (int)$st->fetchColumn();
        if (!$uid) {
            throw new RuntimeException('Orang ini belum punya akun login di sistem');
        }
        $db = self::db();
        $role = $db->prepare('SELECT r.role_code FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $role->execute([$uid]);
        if ($role->fetchColumn() === 'developer') {
            throw new RuntimeException('Akun developer tidak diubah dari Console');
        }
        $hasView = (bool)$db->query("SHOW COLUMNS FROM users LIKE 'password_view'")->fetch();
        $db->prepare('UPDATE users SET password = ?' . ($hasView ? ', password_view = NULL' : '') . ' WHERE id = ?')
            ->execute([password_hash($plain, PASSWORD_DEFAULT), $uid]);
        // Gagal login berulang sebelumnya tidak lagi mengunci orang ini
        try {
            $u = $db->prepare('SELECT username FROM users WHERE id = ?');
            $u->execute([$uid]);
            $db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([(string)$u->fetchColumn()]);
        } catch (Throwable) {
            // tabel login_attempts lama bisa berbeda bentuk — tidak wajib
        }
    }

    /** Password acak yang mudah didikte lewat WhatsApp: tanpa huruf/angka yang mirip (0/O, 1/l/I). */
    public static function generatePassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
            if ($i === 4) {
                $out .= '-';
            }
        }
        return $out;
    }

    /** Buat user lama untuk orang baru di Console. Tanpa username / password → belum bisa (dilewati dengan catatan). */
    private static function createUser(array $d, ?string $password, array $roleIds, array &$log): ?int
    {
        $p = Auth::db()->prepare('SELECT * FROM people WHERE id = ?');
        $p->execute([$d['person_id']]);
        $person = $p->fetch();
        if (!$person || !$person['username'] || $password === null || $password === '') {
            $log[] = "{$d['name']}: belum dibuat di sistem lama (perlu username + password awal)";
            return null;
        }
        $db = self::db();
        $dup = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $dup->execute([$person['username'], $person['email']]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException("Username atau email {$person['username']} / {$person['email']} sudah dipakai di sistem lama");
        }
        $role = self::globalRole((int)$person['id']);
        $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$person['username'], $person['email'], password_hash($password, PASSWORD_DEFAULT), $person['name'], $person['phone'] ?: '0000000000', $roleIds[$role] ?? $roleIds['staff']]);
        $uid = (int)$db->lastInsertId();
        Auth::db()->prepare('UPDATE people SET legacy_user_id = ?, legacy_role = ?, status = ? WHERE id = ?')->execute([$uid, $role, 'active', $person['id']]);
        $log[] = "{$person['name']}: user baru di sistem lama ({$person['username']}, peran {$role})";
        return $uid;
    }

    private static function personStatus(int $personId): string
    {
        $st = Auth::db()->prepare('SELECT status FROM people WHERE id = ?');
        $st->execute([$personId]);
        return (string)$st->fetchColumn();
    }

    /** Peran global terkuat orang ini di semua bisnis Console yang terhubung ke sistem lama. */
    private static function globalRole(int $personId): string
    {
        $st = Auth::db()->prepare('SELECT b.id FROM businesses b
            WHERE b.legacy_business_id IS NOT NULL AND (b.id IN (SELECT business_id FROM memberships WHERE person_id = ?)
               OR b.client_id IN (SELECT client_id FROM client_owners WHERE person_id = ?))');
        $st->execute([$personId, $personId]);
        $best = 'staff';
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) {
            $role = self::desired(Biz::business((int)$bid))[$personId]['role'] ?? 'staff';
            if ((self::ROLE_RANK[$role] ?? 0) > (self::ROLE_RANK[$best] ?? 0)) {
                $best = $role;
            }
        }
        return $best;
    }

    /* ------------------------------------------------------------------ hitung balik & bandingkan */

    /**
     * Baris yang seharusnya ada di tabel lama menurut Console, per orang.
     * @return array<int, array{person_id:int, legacy_user_id:?int, username:?string, name:string, role:string, levels:array<string,int>}>
     */
    public static function desired(array $biz): array
    {
        $acc = Access::members($biz);
        $roles = Access::roles((int)$biz['id']);
        $out = [];
        $con = Auth::db();
        $person = $con->prepare('SELECT * FROM people WHERE id = ?');
        $add = function (int $pid, bool $groupOwner, ?array $m) use (&$out, $biz, $roles, $person): void {
            $person->execute([$pid]);
            $p = $person->fetch();
            if (!$p) {
                return;
            }
            $role = $m ? ($roles[(int)$m['role_id']] ?? null) : null;
            $computed = $groupOwner || ($role && $role['is_owner']) ? 'owner' : (($m['portal_owner'] ?? 0) ? 'admin' : (($role['code'] ?? '') === 'manager' ? 'manager' : 'staff'));
            $legacy = (string)($p['legacy_role'] ?? '');
            // Peran lama dipertahankan selama pintu Owner Login-nya masih sesuai dengan Console
            $keepLegacy = $legacy !== '' && in_array($legacy, self::OWNER_LOGIN_ROLES, true) === in_array($computed, self::OWNER_LOGIN_ROLES, true);
            $out[$pid] = [
                'person_id' => $pid, 'legacy_user_id' => $p['legacy_user_id'] !== null ? (int)$p['legacy_user_id'] : null, 'username' => $p['username'],
                'name' => (string)$p['name'], 'role' => $keepLegacy ? $legacy : $computed, 'levels' => Access::effective($biz, $pid),
            ];
        };
        foreach ($acc['owners'] as $o) {
            $add((int)$o['person_id'], true, null);
        }
        foreach ($acc['members'] as $m) {
            if (!isset($out[(int)$m['person_id']])) {
                $add((int)$m['person_id'], false, $m);
            }
        }
        return $out;
    }

    /**
     * Beda antara Console dan tabel lama untuk satu bisnis. Kosong = identik (akses siapa pun tidak berubah bila diekspor).
     * Dibandingkan per menu: boleh dibuka atau tidak (can_view) DAN keempat tanda izin.
     * @return string[]
     */
    public static function diff(array $biz): array
    {
        if (empty($biz['legacy_business_id'])) {
            return ['Bisnis ini belum terhubung ke sistem lama (legacy_business_id kosong).'];
        }
        $cur = self::current((int)$biz['legacy_business_id']);
        $cur = array_filter($cur, fn ($u) => $u['assigned']);
        $want = [];
        foreach (self::desired($biz) as $d) {
            $want[$d['legacy_user_id'] ?? 'baru:' . $d['person_id']] = $d;
        }
        $out = [];
        foreach ($cur as $uid => $u) {
            if (!isset($want[$uid])) {
                $out[] = "{$u['username']}: ada di sistem lama, tidak ada di Console (aksesnya akan DICABUT)";
                continue;
            }
            $d = $want[$uid];
            $role = self::globalRole((int)$d['person_id']);
            if ($role !== $u['role']) {
                $out[] = "{$u['username']}: peran global {$u['role']} → {$role}";
            }
            foreach (Catalog::MODULES as $code => $_) {
                $a = $u['levels'][$code] ?? 0;
                $b = $d['levels'][$code] ?? 0;
                if ($a !== $b) {
                    $out[] = sprintf('%s: %s %s → %s', $u['username'], $code, implode('', Catalog::legacyFlags($a)), implode('', Catalog::legacyFlags($b)));
                }
            }
            foreach ($u['unknown'] as $code) {
                $out[] = "{$u['username']}: menu '{$code}' (di luar katalog) diizinkan di sistem lama — tidak dikelola Console";
            }
            unset($want[$uid]);
        }
        foreach ($want as $d) {
            $out[] = ($d['username'] ?: $d['name']) . ': baru di Console, belum ada di sistem lama';
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

/**
 * Orang & Akses: orang × bisnis × peran.
 *   Izin akhir seseorang di satu bisnis = modul menyala di bisnis itu (lapis 1)  ∩  level peran (+ pengecualian) (lapis 2).
 *   Owner grup (client_owners) otomatis berperan Owner di semua bisnis client-nya.
 */
final class Access
{
    private static function db(): PDO
    {
        return Auth::db();
    }

    /** Pastikan bisnis punya peran (disalin dari templat jenisnya bila belum ada). */
    public static function ensureRoles(array $biz): void
    {
        $db = self::db();
        $st = $db->prepare('SELECT COUNT(*) FROM roles WHERE business_id = ?');
        $st->execute([(int)$biz['id']]);
        if ((int)$st->fetchColumn() > 0) {
            return;
        }
        $ins = $db->prepare('INSERT INTO roles (business_id, code, name, description, permissions, is_owner, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (Catalog::roleTemplates((string)$biz['type']) as $i => [$code, $name, $desc, $isOwner, $perms]) {
            $ins->execute([(int)$biz['id'], $code, $name, $desc, json_encode($perms), $isOwner ? 1 : 0, $i]);
        }
    }

    /** @return array<int, array> peran bisnis, kunci = id */
    public static function roles(int $businessId): array
    {
        $st = self::db()->prepare('SELECT * FROM roles WHERE business_id = ? ORDER BY sort, id');
        $st->execute([$businessId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $r['perms'] = json_decode((string)$r['permissions'], true) ?: [];
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** Anggota bisnis + owner grup (otomatis). */
    public static function members(array $biz): array
    {
        $db = self::db();
        $st = $db->prepare('SELECT m.*, p.name, p.email, p.status, p.username, r.name AS role_name, r.is_owner
            FROM memberships m JOIN people p ON p.id = m.person_id JOIN roles r ON r.id = m.role_id WHERE m.business_id = ? ORDER BY r.sort, p.name');
        $st->execute([(int)$biz['id']]);
        $rows = $st->fetchAll();
        $st = $db->prepare('SELECT p.* FROM client_owners co JOIN people p ON p.id = co.person_id WHERE co.client_id = ? ORDER BY p.name');
        $st->execute([(int)$biz['client_id']]);
        $owners = [];
        foreach ($st->fetchAll() as $p) {
            $owners[] = ['person_id' => (int)$p['id'], 'name' => $p['name'], 'email' => $p['email'], 'status' => $p['status'], 'group_owner' => true];
        }
        return ['owners' => $owners, 'members' => $rows];
    }

    /** Orang di seluruh bisnis client: [person => [nama, email, owner grup?, [bisnis => peran]]] */
    public static function clientPeople(int $clientId): array
    {
        $db = self::db();
        $people = [];
        $st = $db->prepare('SELECT p.id, p.name, p.email, p.status, b.id AS bid, b.name AS business_name, r.name AS role_name
            FROM memberships m JOIN people p ON p.id = m.person_id JOIN businesses b ON b.id = m.business_id JOIN roles r ON r.id = m.role_id
            WHERE b.client_id = ? ORDER BY p.name, b.name');
        $st->execute([$clientId]);
        foreach ($st->fetchAll() as $r) {
            $people[(int)$r['id']] ??= ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'status' => $r['status'], 'group_owner' => false, 'access' => []];
            $people[(int)$r['id']]['access'][] = [(int)$r['bid'], $r['business_name'], $r['role_name']];
        }
        $st = $db->prepare('SELECT p.id, p.name, p.email, p.status FROM client_owners co JOIN people p ON p.id = co.person_id WHERE co.client_id = ?');
        $st->execute([$clientId]);
        foreach ($st->fetchAll() as $r) {
            $people[(int)$r['id']] ??= ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'status' => $r['status'], 'access' => []];
            $people[(int)$r['id']]['group_owner'] = true;
        }
        uasort($people, fn ($a, $b) => [!$a['group_owner'], $a['name']] <=> [!$b['group_owner'], $b['name']]);
        return $people;
    }

    /** Cari orang berdasarkan email, atau buat baru (status diundang). */
    public static function person(string $name, string $email): int
    {
        $db = self::db();
        $email = strtolower(trim($email));
        $st = $db->prepare('SELECT id FROM people WHERE email = ?');
        $st->execute([$email]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
        $db->prepare('INSERT INTO people (name, email) VALUES (?, ?)')->execute([mb_substr(trim($name) ?: $email, 0, 120), $email]);
        return (int)$db->lastInsertId();
    }

    /** Beri / ubah akses orang di bisnis. */
    public static function setMembership(int $businessId, int $personId, int $roleId, bool $portalOwner, bool $portalStaff): void
    {
        self::db()->prepare('INSERT INTO memberships (person_id, business_id, role_id, portal_owner, portal_staff) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), portal_owner = VALUES(portal_owner), portal_staff = VALUES(portal_staff)')
            ->execute([$personId, $businessId, $roleId, $portalOwner ? 1 : 0, $portalStaff ? 1 : 0]);
    }

    public static function removeMembership(int $businessId, int $personId): void
    {
        self::db()->prepare('DELETE FROM memberships WHERE business_id = ? AND person_id = ?')->execute([$businessId, $personId]);
    }

    public static function setGroupOwner(int $clientId, int $personId, bool $on): void
    {
        $sql = $on ? 'INSERT IGNORE INTO client_owners (client_id, person_id) VALUES (?, ?)' : 'DELETE FROM client_owners WHERE client_id = ? AND person_id = ?';
        self::db()->prepare($sql)->execute([$clientId, $personId]);
    }

    /** Simpan matriks izin peran: $levels[roleId][modul] = 0..3 (peran pemilik selalu penuh). */
    public static function saveRolePermissions(int $businessId, array $levels): void
    {
        $roles = self::roles($businessId);
        $upd = self::db()->prepare('UPDATE roles SET permissions = ? WHERE id = ? AND business_id = ?');
        foreach ($roles as $rid => $r) {
            if ($r['is_owner'] || !isset($levels[$rid]) || !is_array($levels[$rid])) {
                continue;
            }
            $perms = [];
            foreach (array_keys(Catalog::MODULES) as $code) {
                if (isset($levels[$rid][$code])) {
                    $perms[$code] = max(0, min(3, (int)$levels[$rid][$code]));
                } else {
                    $perms[$code] = Catalog::levelOf($r['perms'], $code); // modul yang tidak tampil (mati) tetap disimpan apa adanya
                }
            }
            $upd->execute([json_encode($perms), $rid, $businessId]);
        }
    }

    /**
     * Izin akhir per modul untuk satu orang di satu bisnis (0..3). Modul yang mati di bisnis = 0 untuk siapa pun.
     * @return array<string,int>
     */
    public static function effective(array $biz, int $personId): array
    {
        $enabled = Biz::modules($biz)['module'];
        $db = self::db();
        $st = $db->prepare('SELECT 1 FROM client_owners WHERE client_id = ? AND person_id = ?');
        $st->execute([(int)$biz['client_id'], $personId]);
        $groupOwner = (bool)$st->fetchColumn();
        $st = $db->prepare('SELECT r.permissions, r.is_owner, m.overrides FROM memberships m JOIN roles r ON r.id = m.role_id WHERE m.business_id = ? AND m.person_id = ?');
        $st->execute([(int)$biz['id'], $personId]);
        $m = $st->fetch();
        $perms = $groupOwner || ($m && $m['is_owner']) ? ['*' => 3] : ($m ? (json_decode((string)$m['permissions'], true) ?: []) : []);
        $over = $m && !$groupOwner ? (json_decode((string)$m['overrides'], true) ?: []) : [];
        $out = [];
        foreach (array_keys(Catalog::MODULES) as $code) {
            $level = isset($over[$code]) ? (int)$over[$code] : Catalog::levelOf($perms, $code);
            $out[$code] = !empty($enabled[$code]) ? $level : 0;
        }
        return $out;
    }
}

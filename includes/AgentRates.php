<?php

/**
 * Harga Agent: daftar harga khusus (mis. "Agent", "Travel A") berisi harga /malam per tipe kamar.
 * Diatur di Pengaturan Front Desk → Harga Agent; dipilih lewat dropdown harga di penawaran.
 * Disimpan di tabel settings bisnis, kunci agent_rate_plans (JSON):
 *   [{"id":"ag1","name":"Agent","note":"","prices":{"<room_type_id>":450000, …}}, …]
 * Tipe kamar tanpa harga di daftar = ikut harga normal tipe kamar.
 */
class AgentRates
{
    private const KEY = 'agent_rate_plans';

    /** @return array<int, array{id:string,name:string,note:string,prices:array<string,int>}> */
    public static function plans($db): array
    {
        try {
            $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [self::KEY]);
        } catch (\Throwable $e) {
            return [];
        }
        $list = json_decode((string)($row['setting_value'] ?? '[]'), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $p) {
            if (!is_array($p) || trim((string)($p['name'] ?? '')) === '') continue;
            $prices = [];
            foreach ((array)($p['prices'] ?? []) as $typeId => $price) {
                if ((int)$price > 0) $prices[(string)(int)$typeId] = (int)$price;
            }
            $out[] = ['id' => (string)($p['id'] ?? ''), 'name' => (string)$p['name'], 'note' => (string)($p['note'] ?? ''), 'prices' => $prices];
        }
        return $out;
    }

    /** Simpan seluruh daftar (urutan dipertahankan). */
    public static function save($db, array $plans): void
    {
        $clean = [];
        foreach ($plans as $p) {
            $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 60);
            if ($name === '') continue;
            $prices = [];
            foreach ((array)($p['prices'] ?? []) as $typeId => $price) {
                $v = (int)preg_replace('/[^\d]/', '', (string)$price);
                if ($v > 0) $prices[(string)(int)$typeId] = $v;
            }
            $id = preg_match('/^[a-z0-9]{2,20}$/', (string)($p['id'] ?? '')) ? (string)$p['id'] : 'ag' . bin2hex(random_bytes(3));
            $clean[] = ['id' => $id, 'name' => $name, 'note' => mb_substr(trim((string)($p['note'] ?? '')), 0, 200), 'prices' => $prices];
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($db->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [self::KEY])) {
            $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$json, self::KEY]);
        } else {
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [self::KEY, $json]);
        }
    }

    /** Tipe kamar + harga normal, untuk halaman pengaturan & pencocokan di penawaran. */
    public static function roomTypes($db): array
    {
        try {
            return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['type_name'], 'base' => (int)round((float)$r['base_price'])],
                $db->fetchAll("SELECT id, type_name, base_price FROM room_types ORDER BY base_price, type_name") ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

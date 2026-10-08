<?php

/**
 * Klien API Cloudbeds (API key "cbat_…", header x-api-key).
 *
 * Pengaturan per bisnis di tabel `settings` (database bisnis yang aktif):
 *   cloudbeds_api_key      API key, DIENKRIPSI (AES-256-CBC); tidak pernah dikirim ke browser
 *   cloudbeds_property_id  propertyID Cloudbeds (terisi otomatis saat tes koneksi)
 *   cloudbeds_api_base     opsional, default https://api.cloudbeds.com/api/v1.2
 *
 * Tahap 1: hanya baca (tes koneksi, info properti, tipe kamar, kamar, sumber booking).
 * Kode lama includes/CloudbedPMS.php & CloudbedHelper.php tidak dipakai (menulis ke tabel yang tidak ada).
 */
class CloudbedsClient
{
    private $db;
    private $settings = null;
    public const DEFAULT_BASE = 'https://api.cloudbeds.com/api/v1.2';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /* ---------------- Pengaturan ---------------- */

    private function settings(): array
    {
        if ($this->settings === null) {
            $this->settings = ['cloudbeds_api_key' => '', 'cloudbeds_property_id' => '', 'cloudbeds_api_base' => ''];
            try {
                $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('cloudbeds_api_key','cloudbeds_property_id','cloudbeds_api_base')") ?: [];
                foreach ($rows as $r) {
                    $this->settings[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (\Throwable $e) {
            }
        }
        return $this->settings;
    }

    public function apiKey(): string
    {
        return self::decrypt($this->settings()['cloudbeds_api_key']);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /** 4 karakter terakhir key, untuk ditampilkan ("cbat_••••abcd") tanpa membuka key-nya. */
    public function keyHint(): string
    {
        $k = $this->apiKey();
        return $k === '' ? '' : substr($k, 0, 5) . '••••' . substr($k, -4);
    }

    public function propertyId(): string
    {
        return trim($this->settings()['cloudbeds_property_id']);
    }

    /**
     * Pemetaan tipe kamar Cloudbeds → nama tipe kamar di sistem (setting cloudbeds_roomtype_map, JSON).
     * @return array<string,string> roomTypeID => type_name
     */
    public function roomTypeMap(): array
    {
        try {
            $row = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_roomtype_map'");
            $map = json_decode((string)($row['setting_value'] ?? ''), true);
            return is_array($map) ? array_map('strval', $map) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function saveRoomTypeMap(array $map): void
    {
        $clean = [];
        foreach ($map as $id => $name) {
            $id = trim((string)$id);
            $name = trim((string)$name);
            if ($id !== '' && $name !== '') {
                $clean[$id] = $name;
            }
        }
        $this->saveSetting('cloudbeds_roomtype_map', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Saran pasangan tipe kamar: nama sama persis, lalu nama sistem yang terkandung di nama Cloudbeds
     * (Standard Queen → Queen), utamakan jumlah unit sama & nama terpanjang; tiap tipe sistem dipakai sekali.
     * @param array $cbTypes  [ ['id'=>, 'name'=>, 'units'=>], ... ]
     * @param array $localTypes  type_name => jumlah kamar
     */
    public static function suggestRoomTypeMap(array $cbTypes, array $localTypes): array
    {
        $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
        $used = [];
        $out = [];
        foreach ($cbTypes as $t) {
            foreach ($localTypes as $ln => $cnt) {
                if ($norm($ln) !== '' && $norm($ln) === $norm($t['name'])) {
                    $out[$t['id']] = $ln;
                    $used[$ln] = true;
                }
            }
        }
        foreach ($cbTypes as $t) {
            if (isset($out[$t['id']])) continue;
            $best = null;
            $bestScore = -1;
            foreach ($localTypes as $ln => $cnt) {
                if (isset($used[$ln]) || $norm($ln) === '' || strpos($norm($t['name']), $norm($ln)) === false) continue;
                $score = strlen($norm($ln)) + ((int)$cnt === (int)$t['units'] ? 100 : 0);
                if ($score > $bestScore) {
                    $best = $ln;
                    $bestScore = $score;
                }
            }
            if ($best !== null) {
                $out[$t['id']] = $best;
                $used[$best] = true;
            }
        }
        return $out;
    }

    /**
     * Pemetaan sumber booking Cloudbeds (sourceID) → source_key di booking_sources (setting cloudbeds_source_map).
     * @return array<string,string>
     */
    public function sourceMap(): array
    {
        try {
            $row = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_source_map'");
            $map = json_decode((string)($row['setting_value'] ?? ''), true);
            return is_array($map) ? array_map('strval', $map) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function saveSourceMap(array $map): void
    {
        $clean = [];
        foreach ($map as $id => $key) {
            if (trim((string)$id) !== '' && trim((string)$key) !== '') {
                $clean[trim((string)$id)] = trim((string)$key);
            }
        }
        $this->saveSetting('cloudbeds_source_map', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    /** "Hotel Collect" = tamu bayar ke hotel; "Channel Collect" = OTA terima uang lalu transfer bersih. */
    public static function collectType(string $sourceName): string
    {
        $n = strtolower($sourceName);
        if (strpos($n, 'channel collect') !== false) return 'channel';
        if (strpos($n, 'hotel collect') !== false) return 'hotel';
        return '';
    }

    /**
     * Saran pemetaan sumber: nama sistem terkandung di nama Cloudbeds (Agoda / Priceline → Agoda), sumber
     * langsung (Walk-In, Phone, Email, Website, Default …) ke sumber direct yang namanya paling mirip.
     * @param array $cbSources [ ['id'=>, 'name'=>], ... ]
     * @param array $localSources rows booking_sources (source_key, source_name, source_type)
     */
    public static function suggestSourceMap(array $cbSources, array $localSources): array
    {
        $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
        $alias = [
            'walkin' => ['walkin', 'walk_in'], 'phone' => ['phone', 'telepon'], 'email' => ['email'],
            'website' => ['website', 'online', 'web', 'direct'], 'bookingengine' => ['website', 'online', 'web', 'direct'],
        ];
        $out = [];
        foreach ($cbSources as $s) {
            $cn = $norm(preg_replace('/\(.*?\)/', '', $s['name']));
            $best = null;
            $bestLen = 0;
            // Sumber langsung dulu: "Website/Booking Engine" tidak boleh terbaca sebagai Booking.com
            foreach ($alias as $needle => $cands) {
                if ($best !== null || strpos($cn, $needle) === false) continue;
                foreach ($localSources as $l) {
                    foreach ($cands as $c) {
                        if ($best === null && (strpos($norm($l['source_key']), $c) !== false || strpos($norm($l['source_name']), $c) !== false)) {
                            $best = $l['source_key'];
                        }
                    }
                }
                if ($best !== null) {
                    $out[$s['id']] = $best;
                }
            }
            if ($best !== null) continue;
            if (preg_match('/website|bookingengine|walkin|phone|email|default/', $cn)) continue;
            foreach ($localSources as $l) {
                foreach ([$norm($l['source_name']), $norm($l['source_key'])] as $ln) {
                    $ln2 = preg_replace('/com$/', '', $ln);
                    if ($ln2 !== '' && strlen($ln2) > $bestLen && strpos($cn, $ln2) !== false) {
                        $best = $l['source_key'];
                        $bestLen = strlen($ln2);
                    }
                }
            }
            if ($best !== null) {
                $out[$s['id']] = $best;
            }
        }
        return $out;
    }

    public function baseUrl(): string
    {
        $b = trim($this->settings()['cloudbeds_api_base']);
        return rtrim($b !== '' ? $b : self::DEFAULT_BASE, '/');
    }

    public function saveSetting(string $key, string $value): void
    {
        if ($key === 'cloudbeds_api_key') {
            $value = $value === '' ? '' : self::encrypt($value);
        }
        $this->db->query(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
        $this->settings = null;
    }

    /* ---------------- Enkripsi key ---------------- */

    private static function secretKey(): string
    {
        $seed = defined('DB_PASS') ? DB_PASS : 'adf-cloudbeds-secret';
        return hash('sha256', $seed . '|adf-cloudbeds', true);
    }

    private static function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);
        return 'enc:' . base64_encode($iv . $cipher);
    }

    private static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, 'enc:') !== 0) {
            return '';
        }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain !== false ? $plain : '';
    }

    /* ---------------- HTTP ---------------- */

    /**
     * GET ke endpoint Cloudbeds. $keyOverride = uji key yang baru diketik (belum disimpan).
     * @return array{ok:bool, http:int, data:mixed, detail:string}
     */
    /**
     * POST / PUT (form-encoded) ke endpoint Cloudbeds — dipakai tahap Sistem → Cloudbeds.
     * @return array{ok:bool, http:int, data:mixed, detail:string, raw:mixed}
     */
    public function send(string $method, string $endpoint, array $params): array
    {
        $key = trim($this->apiKey(), " \t\n\r\0\x0B\"'");
        if ($key === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'API key Cloudbeds belum diisi', 'raw' => null];
        }
        if ($this->propertyId() !== '' && !isset($params['propertyID'])) {
            $params['propertyID'] = $this->propertyId();
        }
        // DELETE: parameter di URL; "DELETE:body": DELETE dengan parameter di form body; POST/PUT: form body
        $verb = strtoupper($method);
        $deleteInBody = $verb === 'DELETE:BODY';
        if ($deleteInBody) $verb = 'DELETE';
        $isDelete = $verb === 'DELETE' && !$deleteInBody;
        $ch = curl_init($this->baseUrl() . '/' . ltrim($endpoint, '/') . ($isDelete ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_POSTFIELDS => $isDelete ? '' : http_build_query($params),
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'Tidak bisa menghubungi Cloudbeds: ' . $err, 'raw' => null];
        }
        $json = json_decode($body, true);
        $ok = $http >= 200 && $http < 300 && is_array($json) && ($json['success'] ?? true) !== false;
        $detail = $ok ? 'OK' : (is_array($json) ? (string)($json['message'] ?? ('HTTP ' . $http . ' ' . substr((string)$body, 0, 160))) : ('HTTP ' . $http . ' ' . substr((string)$body, 0, 200)));
        return ['ok' => $ok, 'http' => $http, 'data' => is_array($json) ? ($json['data'] ?? $json) : null, 'detail' => $detail, 'raw' => $json];
    }

    public function get(string $endpoint, array $params = [], ?string $keyOverride = null): array
    {
        $key = trim((string)($keyOverride ?? $this->apiKey()), " \t\n\r\0\x0B\"'");
        if ($key === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'API key Cloudbeds belum diisi'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'Ekstensi PHP cURL tidak aktif di server'];
        }
        $url = $this->baseUrl() . '/' . ltrim($endpoint, '/') . ($params ? '?' . http_build_query($params) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'Tidak bisa menghubungi Cloudbeds: ' . $err];
        }
        $json = json_decode($body, true);
        $ok = $http >= 200 && $http < 300 && is_array($json) && ($json['success'] ?? true) !== false;
        $detail = $ok ? 'OK' : (is_array($json) ? (string)($json['message'] ?? ('HTTP ' . $http)) : ('HTTP ' . $http));
        if ($http === 401 || $http === 403) {
            $detail = 'Ditolak Cloudbeds (HTTP ' . $http . '): ' . $detail . ' — cek API key & scope';
        }
        return ['ok' => $ok, 'http' => $http, 'data' => is_array($json) ? ($json['data'] ?? $json) : null, 'detail' => $detail];
    }

    /** Kumpulkan (rekursif) semua objek yang memiliki salah satu kunci $keys. */
    private static function collect($data, array $keys): array
    {
        $found = [];
        if (!is_array($data)) {
            return $found;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && !is_array($data[$k])) {
                return [$data];
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = array_merge($found, self::collect($v, $keys));
            }
        }
        return $found;
    }

    /**
     * Pratinjau reservasi Cloudbeds (hanya baca) untuk rentang check-in. Semua halaman diambil (maks 5×100).
     * @return array{ok:bool, detail:string, items:array, raw_sample:mixed}
     */
    public function previewReservations(string $from, string $to): array
    {
        $items = [];
        $raw = null;
        $detail = 'OK';
        for ($page = 1; $page <= 5; $page++) {
            $q = ['checkInFrom' => $from, 'checkInTo' => $to, 'pageNumber' => $page, 'pageSize' => 100];
            if ($this->propertyId() !== '') {
                $q['propertyID'] = $this->propertyId();
            }
            $r = $this->get('getReservations', $q);
            if (!$r['ok']) {
                return ['ok' => false, 'detail' => $r['detail'], 'items' => $items, 'raw_sample' => $raw];
            }
            $rows = self::collect($r['data'], ['reservationID']);
            if ($raw === null && $rows) {
                $raw = $rows[0];
            }
            foreach ($rows as $x) {
                $items[] = [
                    'id' => (string)($x['reservationID'] ?? ''),
                    'guest' => (string)($x['guestName'] ?? trim(($x['guestFirstName'] ?? '') . ' ' . ($x['guestLastName'] ?? ''))),
                    'status' => (string)($x['status'] ?? ''),
                    'checkin' => substr((string)($x['startDate'] ?? $x['checkInDate'] ?? ''), 0, 10),
                    'checkout' => substr((string)($x['endDate'] ?? $x['checkOutDate'] ?? ''), 0, 10),
                    'source' => (string)($x['sourceName'] ?? $x['source'] ?? ''),
                    'third_party_id' => (string)($x['thirdPartyIdentifier'] ?? ''),
                    'total' => isset($x['total']) ? (float)$x['total'] : (isset($x['grandTotal']) ? (float)$x['grandTotal'] : null),
                    'balance' => isset($x['balance']) ? (float)$x['balance'] : null,
                ];
            }
            if (count($rows) < 100) {
                break;
            }
        }
        return ['ok' => true, 'detail' => $detail, 'items' => $items, 'raw_sample' => $raw];
    }

    /**
     * Detail satu reservasi (kamar yang ditempatkan, total, sisa) — hanya baca.
     * @return array{ok:bool, detail:string, rooms:array, total:?float, balance:?float, adults:int, children:int, raw:mixed}
     */
    public function reservationDetail(string $reservationId): array
    {
        $q = ['reservationID' => $reservationId];
        if ($this->propertyId() !== '') {
            $q['propertyID'] = $this->propertyId();
        }
        $r = $this->get('getReservation', $q);
        $out = ['ok' => $r['ok'], 'detail' => $r['detail'], 'rooms' => [], 'total' => null, 'balance' => null, 'adults' => 0, 'children' => 0, 'raw' => $r['data']];
        if (!$r['ok'] || !is_array($r['data'])) {
            return $out;
        }
        $d = $r['data'];
        $out['total'] = isset($d['total']) && is_numeric($d['total']) ? (float)$d['total'] : null;
        $out['balance'] = isset($d['balance']) && is_numeric($d['balance']) ? (float)$d['balance'] : null;
        // Kamar: "assigned" (sudah dapat nomor kamar) & "unassigned" (baru tipe kamar)
        foreach (['assigned' => true, 'unassigned' => false] as $key => $isAssigned) {
            foreach ((array)($d[$key] ?? []) as $rm) {
                if (!is_array($rm)) continue;
                $out['rooms'][] = [
                    'assigned' => $isAssigned,
                    'room_id' => (string)($rm['roomID'] ?? ''),
                    'room_name' => (string)($rm['roomName'] ?? ''),
                    'type_id' => (string)($rm['roomTypeID'] ?? ''),
                    'type_name' => (string)($rm['roomTypeName'] ?? ''),
                    'start' => substr((string)($rm['startDate'] ?? $rm['roomCheckIn'] ?? ''), 0, 10),
                    'end' => substr((string)($rm['endDate'] ?? $rm['roomCheckOut'] ?? ''), 0, 10),
                    'total' => isset($rm['roomTotal']) && is_numeric($rm['roomTotal']) ? (float)$rm['roomTotal'] : null,
                    'adults' => (int)($rm['adults'] ?? 0),
                    'children' => (int)($rm['children'] ?? 0),
                ];
                $out['adults'] += (int)($rm['adults'] ?? 0);
                $out['children'] += (int)($rm['children'] ?? 0);
            }
        }
        return $out;
    }

    /* ---------------- Tes koneksi (hanya baca) ---------------- */

    /**
     * Ambil properti, tipe kamar, kamar dan sumber booking. Tidak mengubah apa pun di Cloudbeds.
     * propertyID disimpan otomatis bila key baru diuji & tersimpan.
     */
    public function testConnection(?string $keyOverride = null): array
    {
        $out = ['ok' => false, 'steps' => [], 'property' => null, 'room_types' => [], 'rooms' => [], 'sources' => []];

        $hotels = $this->get('getHotels', [], $keyOverride);
        $out['steps'][] = ['name' => 'Properti (getHotels)', 'ok' => $hotels['ok'], 'detail' => $hotels['detail']];
        if (!$hotels['ok']) {
            return $out;
        }
        $list = is_array($hotels['data']) ? array_values($hotels['data']) : [];
        $prop = $list[0] ?? null;
        $propertyId = (string)($prop['propertyID'] ?? $this->propertyId());
        $out['property'] = [
            'id' => $propertyId,
            'name' => (string)($prop['propertyName'] ?? ''),
            'count' => count($list),
            'currency' => (string)($prop['propertyCurrency']['currencyCode'] ?? ($prop['propertyCurrency'] ?? '')),
        ];
        $q = $propertyId !== '' ? ['propertyID' => $propertyId] : [];

        $rt = $this->get('getRoomTypes', $q, $keyOverride);
        $out['steps'][] = ['name' => 'Tipe kamar (getRoomTypes)', 'ok' => $rt['ok'], 'detail' => $rt['detail']];
        foreach (($rt['ok'] && is_array($rt['data'])) ? $rt['data'] : [] as $t) {
            $out['room_types'][] = [
                'id' => (string)($t['roomTypeID'] ?? ''),
                'name' => (string)($t['roomTypeName'] ?? ''),
                'short' => (string)($t['roomTypeNameShort'] ?? ''),
                'units' => (int)($t['roomTypeUnits'] ?? 0),
            ];
        }

        $rm = $this->get('getRooms', $q, $keyOverride);
        $out['steps'][] = ['name' => 'Kamar (getRooms)', 'ok' => $rm['ok'], 'detail' => $rm['detail']];
        // getRooms: data = [ {propertyID, rooms: [ {roomID, roomName, roomTypeID, roomTypeName, ...} ]} ]
        foreach (($rm['ok'] && is_array($rm['data'])) ? $rm['data'] : [] as $blk) {
            foreach ((array)($blk['rooms'] ?? []) as $r) {
                $out['rooms'][] = [
                    'id' => (string)($r['roomID'] ?? ''),
                    'name' => (string)($r['roomName'] ?? ''),
                    'type' => (string)($r['roomTypeName'] ?? ''),
                    'type_id' => (string)($r['roomTypeID'] ?? ''),
                ];
            }
        }

        $src = $this->get('getSources', $q, $keyOverride);
        $out['steps'][] = ['name' => 'Sumber booking (getSources)', 'ok' => $src['ok'], 'detail' => $src['detail']];
        // Bentuk data getSources bisa datar atau bersarang (per properti); kumpulkan setiap objek yang punya sourceID/sourceName.
        foreach (self::collect($src['ok'] ? $src['data'] : [], ['sourceID', 'sourceName', 'name']) as $s) {
            $comm = $s['commission'] ?? $s['commissionPercent'] ?? $s['commission_rate'] ?? null;
            if (is_array($comm)) {
                $comm = $comm['value'] ?? $comm['amount'] ?? null;
            }
            $out['sources'][] = [
                'id' => (string)($s['sourceID'] ?? $s['id'] ?? ''),
                'name' => (string)($s['sourceName'] ?? $s['name'] ?? ''),
                'commission' => is_numeric($comm) ? (float)$comm : null,
            ];
        }
        $out['sources_raw_sample'] = is_array($src['data']) ? array_slice($src['data'], 0, 1, true) : $src['data'];

        $out['ok'] = $hotels['ok'];
        return $out;
    }
}

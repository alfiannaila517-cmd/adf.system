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
    /**
     * Semua penulisan ke Cloudbeds lewat sini: diperiksa pengaman (pembayaran / adjustment tidak boleh melampaui data
     * sistem) lalu dicatat di cloudbeds_outbound_log — termasuk yang diblokir — agar setiap perubahan bisa ditelusuri.
     */
    public function send(string $method, string $endpoint, array $params): array
    {
        // detail yang di-cache jadi basi setelah ada tulisan ke reservasi itu
        if (isset($params['reservationID'])) unset($this->detailCache[(string)$params['reservationID']]);
        else $this->detailCache = [];
        $rid = (string)($params['reservationID'] ?? ($params['roomBlockID'] ?? ''));
        $blocked = $this->writeGuard($endpoint, $params);
        if ($blocked !== null) {
            $res = ['ok' => false, 'http' => 0, 'data' => null, 'detail' => 'Pengaman: ' . $blocked, 'raw' => null];
            $this->logWrite($method, $endpoint, $rid, $params, $res, true);
            return $res;
        }
        $res = $this->rawSend($method, $endpoint, $params);
        $this->logWrite($method, $endpoint, $rid, $params, $res, false);
        return $res;
    }

    /** Alasan menolak kiriman (string) atau null bila aman. */
    private function writeGuard(string $endpoint, array $params): ?string
    {
        if (!in_array($endpoint, ['postPayment', 'postAdjustment'], true)) return null;
        $rid = (string)($params['reservationID'] ?? '');
        $amount = (float)($params['amount'] ?? 0);
        if ($endpoint === 'postAdjustment' && $amount < 0) {
            return 'pengurangan Rp ' . number_format(abs($amount), 0, ',', '.') . ' tidak bisa dikirim lewat adjustment (Cloudbeds mencatatnya sebagai tagihan tambahan) — kurangi manual di Cloudbeds';
        }
        if ($rid === '') return null;
        try {
            $links = $this->db->fetchAll("SELECT l.booking_id FROM cloudbeds_booking_links l JOIN bookings b ON b.id = l.booking_id
                WHERE l.cb_reservation_id = ? AND b.status <> 'cancelled'", [$rid]) ?: [];
        } catch (\Throwable $e) {
            return null;
        }
        if (!$links) return null;
        $in = implode(',', array_map(fn($l) => (int)$l['booking_id'], $links));
        $sysTotal = (float)($this->db->fetchOne("SELECT COALESCE(SUM(final_price), 0) s FROM bookings WHERE id IN ($in)")['s'] ?? 0);
        $sysPaid = (float)($this->db->fetchOne("SELECT COALESCE(SUM(amount), 0) s FROM booking_payments WHERE booking_id IN ($in)")['s'] ?? 0);
        $det = $this->reservationDetail($rid);
        if (!$det['ok'] || $det['total'] === null) return null; // tidak terbaca: biarkan Cloudbeds yang memutuskan
        $cbTotal = (float)$det['total'];
        $cbPaid = $cbTotal - (float)($det['balance'] ?? $cbTotal);
        $fmt = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
        if ($endpoint === 'postPayment') {
            if ($amount <= 0) return 'nominal pembayaran tidak valid';
            if ($cbPaid + $amount > $sysPaid + 1) {
                return 'pembayaran di Cloudbeds akan menjadi ' . $fmt($cbPaid + $amount) . ', melebihi pembayaran di sistem ' . $fmt($sysPaid) . ' — tidak dikirim';
            }
            return null;
        }
        // postAdjustment
        if ($amount < 0) {
            // Cloudbeds mengabaikan tanda minus: adjustment -X tercatat sebagai TAGIHAN +X (saldo malah naik)
            return 'pengurangan ' . $fmt(abs($amount)) . ' tidak bisa dikirim lewat adjustment (Cloudbeds mencatatnya sebagai tagihan tambahan) — kurangi manual di Cloudbeds';
        }
        $after = $cbTotal + $amount;
        $ceiling = max($sysTotal, $sysPaid);
        if (abs($amount) > max($sysTotal, 1)) {
            return 'adjustment ' . $fmt($amount) . ' lebih besar dari total booking ' . $fmt($sysTotal) . ' — tidak dikirim';
        }
        if ($amount > 0 && $after > $ceiling + 1) {
            return 'total Cloudbeds akan menjadi ' . $fmt($after) . ', melebihi total/pembayaran di sistem ' . $fmt($ceiling) . ' — tidak dikirim';
        }
        try {
            $n = (int)($this->db->fetchOne("SELECT COUNT(*) c FROM cloudbeds_outbound_log WHERE endpoint = 'postAdjustment' AND reservation_id = ? AND ok = 1 AND created_at > NOW() - INTERVAL 1 DAY", [$rid])['c'] ?? 0);
            if ($n >= 4) return 'sudah ' . $n . ' adjustment ke reservasi ini dalam 24 jam — dihentikan, periksa manual';
        } catch (\Throwable $e) {
        }
        return null;
    }

    private function logWrite(string $method, string $endpoint, string $rid, array $params, array $res, bool $blocked): void
    {
        try {
            static $ready = false;
            if (!$ready) {
                $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_outbound_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    method VARCHAR(12) NOT NULL,
                    endpoint VARCHAR(40) NOT NULL,
                    reservation_id VARCHAR(40) NULL,
                    amount DECIMAL(14,2) NULL,
                    ok TINYINT NOT NULL DEFAULT 0,
                    blocked TINYINT NOT NULL DEFAULT 0,
                    detail VARCHAR(255) NULL,
                    params TEXT NULL,
                    KEY idx_res (reservation_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $ready = true;
            }
            unset($params['propertyID']);
            $this->db->query("INSERT INTO cloudbeds_outbound_log (method, endpoint, reservation_id, amount, ok, blocked, detail, params) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
                $method, $endpoint, $rid !== '' ? $rid : null, isset($params['amount']) ? (float)$params['amount'] : null,
                !empty($res['ok']) ? 1 : 0, $blocked ? 1 : 0, mb_substr((string)($res['detail'] ?? ''), 0, 255), mb_substr(json_encode($params, JSON_UNESCAPED_UNICODE), 0, 2000),
            ]);
        } catch (\Throwable $e) {
            error_log('Cloudbeds outbound log: ' . $e->getMessage());
        }
    }

    /** Kirim ke Cloudbeds tanpa pengaman/log (dipakai send()). */
    protected function rawSend(string $method, string $endpoint, array $params): array
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
        return $this->interpretResponse($body, $http, $err);
    }

    /** Tafsirkan jawaban HTTP Cloudbeds (dipakai get() dan pengambilan paralel). */
    private function interpretResponse($body, int $http, string $err): array
    {
        if ($body === false || $body === null) {
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
        // Cache singkat per proses: satu reservasi tidak dibaca berulang kali dalam satu sinkron (dibersihkan saat ada tulisan ke reservasi itu)
        if (isset($this->detailCache[$reservationId]) && time() - $this->detailCache[$reservationId]['t'] < 45) {
            return $this->detailCache[$reservationId]['r'];
        }
        $r = $this->get('getReservation', $this->detailQuery($reservationId));
        $out = $this->detailFromResponse($r);
        if ($out['ok']) $this->detailCache[$reservationId] = ['t' => time(), 'r' => $out];
        return $out;
    }

    /** @var array<string,array{t:int,r:array}> */
    private $detailCache = [];

    private function detailQuery(string $reservationId): array
    {
        $q = ['reservationID' => $reservationId];
        if ($this->propertyId() !== '') {
            $q['propertyID'] = $this->propertyId();
        }
        return $q;
    }

    /**
     * Ambil detail banyak reservasi SEKALIGUS (paralel, 8 sekaligus) ke cache — jauh lebih cepat daripada satu per satu.
     * Hanya klien asli (uji coba/mock memakai get() sendiri).
     */
    public function prefetchDetails(array $ids, int $parallel = 8): void
    {
        if (static::class !== self::class || !function_exists('curl_multi_init')) return;
        $key = trim((string)$this->apiKey(), " \t\n\r\0\x0B\"'");
        if ($key === '') return;
        $todo = [];
        foreach ($ids as $id) {
            $id = (string)$id;
            if ($id === '' || isset($todo[$id])) continue;
            if (isset($this->detailCache[$id]) && time() - $this->detailCache[$id]['t'] < 45) continue;
            $todo[$id] = true;
        }
        foreach (array_chunk(array_keys($todo), max(1, $parallel)) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $id) {
                $ch = curl_init($this->baseUrl() . '/getReservation?' . http_build_query($this->detailQuery((string)$id)));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'Accept: application/json'],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[(string)$id] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $st === CURLM_OK);
            foreach ($handles as $id => $ch) {
                $body = curl_multi_getcontent($ch);
                $r = $this->interpretResponse($body === null ? false : $body, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)curl_error($ch));
                $out = $this->detailFromResponse($r);
                if ($out['ok']) $this->detailCache[$id] = ['t' => time(), 'r' => $out];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
    }

    private function detailFromResponse(array $r): array
    {
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
                    'sub_id' => (string)($rm['subReservationID'] ?? ''),
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

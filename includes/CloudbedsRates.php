<?php

/**
 * Harga & ketersediaan kamar dari Cloudbeds (tahap 5) — hanya membaca dari Cloudbeds.
 *
 * refresh()  ambil getRatePlans (detailedRates) per potongan 30 hari → simpan di cloudbeds_rates
 *            (satu baris per malam per tipe kamar Cloudbeds, nama tipe kamar sistem dari pemetaan)
 * grid()     data untuk tabel di halaman Cloudbeds
 * nightly()  harga per malam untuk tipe kamar sistem & rentang menginap (dipakai form reservasi baru)
 *
 * Rate plan: bila tipe kamar punya beberapa rate plan, dipakai rate plan dasar (bukan turunan); bisa
 * dipaksa dengan setting cloudbeds_rate_plan (nama rate plan publik).
 */
require_once __DIR__ . '/CloudbedsClient.php';

class CloudbedsRates
{
    private $db;
    private $cb;

    public function __construct($db, CloudbedsClient $cb)
    {
        $this->db = $db;
        $this->cb = $cb;
    }

    public function ensureTable(): void
    {
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_rates (
            rate_date DATE NOT NULL,
            cb_room_type_id VARCHAR(40) NOT NULL,
            room_type_name VARCHAR(100) NULL,
            rate DECIMAL(14,2) NULL,
            available INT NULL,
            rate_plan VARCHAR(120) NULL,
            fetched_at DATETIME NOT NULL,
            PRIMARY KEY (rate_date, cb_room_type_id),
            KEY idx_type_date (room_type_name, rate_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Waktu ambil terakhir (atau null). */
    public function lastFetched(): ?string
    {
        try {
            $this->ensureTable();
            $r = $this->db->fetchOne("SELECT MAX(fetched_at) m FROM cloudbeds_rates");
            return $r['m'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ambil harga & ketersediaan untuk [$from, $to] dari Cloudbeds lalu simpan.
     * @return array{ok:bool, detail:string, rows:int, plans:array, raw_sample:mixed}
     */
    public function refresh(string $from, string $to): array
    {
        $this->ensureTable();
        $typeMap = $this->cb->roomTypeMap();
        $forcedRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_rate_plan'");
        $forced = trim((string)($forcedRow['setting_value'] ?? ''));
        $rows = 0;
        $plansSeen = [];
        $rawSample = null;
        $now = date('Y-m-d H:i:s');

        for ($cs = $from; $cs <= $to; $cs = date('Y-m-d', strtotime($cs . ' +30 days'))) {
            $ce = min($to, date('Y-m-d', strtotime($cs . ' +29 days')));
            // endDate = malam terakhir + 1 (seperti check-out)
            $q = ['startDate' => $cs, 'endDate' => date('Y-m-d', strtotime($ce . ' +1 day')), 'detailedRates' => 'true'];
            if ($this->cb->propertyId() !== '') {
                $q['propertyID'] = $this->cb->propertyId();
            }
            $res = $this->cb->get('getRatePlans', $q);
            if (!$res['ok']) {
                return ['ok' => false, 'detail' => $res['detail'] . (in_array((int)$res['http'], [401, 403], true) ? ' (perlu scope "Rate: Read")' : ''), 'rows' => $rows, 'plans' => $plansSeen, 'raw_sample' => $rawSample];
            }
            // Kumpulkan rate plan (objek dengan roomTypeID + roomRateDetailed/roomRate)
            $plans = [];
            $walk = function ($d) use (&$walk, &$plans) {
                if (!is_array($d)) return;
                if (isset($d['roomTypeID']) && !is_array($d['roomTypeID']) && (isset($d['roomRateDetailed']) || isset($d['roomRate']))) {
                    $plans[] = $d;
                    return;
                }
                foreach ($d as $v) $walk($v);
            };
            $walk($res['data']);
            if ($rawSample === null && $plans) {
                $rawSample = $plans[0];
                if (isset($rawSample['roomRateDetailed']) && is_array($rawSample['roomRateDetailed'])) {
                    $rawSample['roomRateDetailed'] = array_slice($rawSample['roomRateDetailed'], 0, 2);
                }
            }

            // Pilih satu rate plan per tipe kamar: paksa (setting) → bukan turunan → harga terendah
            $byType = [];
            foreach ($plans as $p) {
                $tid = (string)$p['roomTypeID'];
                $name = (string)($p['ratePlanNamePublic'] ?? $p['ratePlanNamePrivate'] ?? $p['rateName'] ?? 'Base');
                $plansSeen[$name] = true;
                $score = 0;
                if ($forced !== '' && strcasecmp($name, $forced) === 0) $score += 1000;
                if (empty($p['isDerived'])) $score += 100;
                if (!isset($byType[$tid]) || $score > $byType[$tid]['score']
                    || ($score === $byType[$tid]['score'] && (float)($p['roomRate'] ?? 0) < (float)($byType[$tid]['p']['roomRate'] ?? 0))) {
                    $byType[$tid] = ['score' => $score, 'p' => $p, 'name' => $name];
                }
            }

            $st = $this->db->getConnection()->prepare(
                "INSERT INTO cloudbeds_rates (rate_date, cb_room_type_id, room_type_name, rate, available, rate_plan, fetched_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE room_type_name = VALUES(room_type_name), rate = VALUES(rate), available = VALUES(available),
                    rate_plan = VALUES(rate_plan), fetched_at = VALUES(fetched_at)"
            );
            foreach ($byType as $tid => $x) {
                $p = $x['p'];
                $local = $typeMap[$tid] ?? null;
                $detailed = is_array($p['roomRateDetailed'] ?? null) ? $p['roomRateDetailed'] : [];
                if (!$detailed) {
                    // Tanpa rincian per malam: pakai harga rata untuk semua malam di potongan ini
                    for ($d = $cs; $d <= $ce; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                        $detailed[] = ['date' => $d, 'rate' => $p['roomRate'] ?? null, 'roomsAvailable' => $p['roomsAvailable'] ?? null];
                    }
                }
                foreach ($detailed as $dr) {
                    $date = substr((string)($dr['date'] ?? ''), 0, 10);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $cs || $date > $ce) continue;
                    $rate = $dr['rate'] ?? ($dr['roomRate'] ?? null);
                    $avail = $dr['roomsAvailable'] ?? ($dr['availability'] ?? ($p['roomsAvailable'] ?? null));
                    $st->execute([$date, $tid, $local, is_numeric($rate) ? (float)$rate : null, is_numeric($avail) ? (int)$avail : null, mb_substr($x['name'], 0, 120), $now]);
                    $rows++;
                }
            }
        }
        return ['ok' => true, 'detail' => 'OK', 'rows' => $rows, 'plans' => array_keys($plansSeen), 'raw_sample' => $rawSample];
    }

    /** Tabel: [type_name => [date => ['rate'=>, 'available'=>]]] untuk tipe kamar yang terpetakan. */
    public function grid(string $from, string $to): array
    {
        $this->ensureTable();
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT rate_date, COALESCE(room_type_name, CONCAT('#', cb_room_type_id)) t, rate, available, rate_plan
             FROM cloudbeds_rates WHERE rate_date BETWEEN ? AND ? ORDER BY t, rate_date",
            [$from, $to]
        ) ?: [] as $r) {
            $out[$r['t']][$r['rate_date']] = ['rate' => $r['rate'] !== null ? (float)$r['rate'] : null, 'available' => $r['available'] !== null ? (int)$r['available'] : null, 'plan' => $r['rate_plan']];
        }
        return $out;
    }

    /**
     * Harga per malam untuk tipe kamar sistem; null bila ada malam yang belum punya harga Cloudbeds.
     * @return array{total:float, nights:array}|null
     */
    public function nightly(string $localType, string $checkIn, string $checkOut): ?array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT rate_date, rate FROM cloudbeds_rates WHERE room_type_name = ? AND rate_date >= ? AND rate_date < ? AND rate IS NOT NULL",
                [$localType, $checkIn, $checkOut]
            ) ?: [];
        } catch (\Throwable $e) {
            return null;
        }
        $need = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
        if ($need <= 0 || count($rows) < $need) return null;
        $nights = [];
        foreach ($rows as $r) $nights[$r['rate_date']] = (float)$r['rate'];
        return ['total' => array_sum($nights), 'nights' => $nights];
    }
}

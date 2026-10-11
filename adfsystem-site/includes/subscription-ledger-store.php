<?php

/**
 * Buku tagihan langganan per klien (mis. Karimunjawa Explore), disimpan di ADF Store.
 *
 * ADF Store adalah sumber kebenaran tagihan: klien hanya melaporkan jumlah tamu (pax) per bulan
 * lewat api/subscription-invoices.php, lalu ADF menghitung:
 *     tagihan bulan = biaya bulanan + total pax x biaya per pax
 * dikurangi bagian yang sudah tertagih (lunas / dibatalkan = dibebaskan / sudah punya link bayar).
 * Satu invoice per periode (bulan). Tamu yang baru terdaftar SETELAH invoice periodenya dibayar tidak
 * membuat invoice susulan: mereka ikut ditagih di invoice bulan berikutnya (kolom carry_guests).
 *
 * Admin bisa membatalkan, mengedit, atau menandai lunas dari admin/subscription-invoices.php.
 * Tagihan manual (di luar hitungan ini) tetap di subscription-manual-invoices-store.php.
 */

function adf_ledger_path(): string
{
    return __DIR__ . '/../data/subscription-ledger.json';
}

/** Baca-ubah-tulis atomik (flock) supaya sinkron paralel dari klien & admin tidak saling menimpa. */
function adf_ledger_mutate(callable $fn)
{
    $path = adf_ledger_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fh = fopen($path, 'c+');
    if (!$fh) {
        throw new RuntimeException('Tidak bisa membuka buku tagihan.');
    }
    flock($fh, LOCK_EX);
    try {
        $data = json_decode((string) stream_get_contents($fh), true);
        if (!is_array($data)) {
            $data = [];
        }
        $data += ['invoices' => [], 'usage' => [], 'meta' => []];
        $result = $fn($data);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function adf_ledger_load(): array
{
    $data = json_decode((string) @file_get_contents(adf_ledger_path()), true);
    return is_array($data) ? $data + ['invoices' => [], 'usage' => [], 'meta' => []] : ['invoices' => [], 'usage' => [], 'meta' => []];
}

function adf_ledger_total(array $inv): float
{
    return round((float) ($inv['base_fee'] ?? 0) + ((int) ($inv['guest_count'] ?? 0) + (int) ($inv['carry_guests'] ?? 0)) * (float) ($inv['per_guest_fee'] ?? 0) + (float) ($inv['adjustment'] ?? 0), 2);
}

/** Invoice milik klien, urut periode lalu id. */
function adf_ledger_for_client(array $data, string $clientKey): array
{
    $rows = array_values(array_filter($data['invoices'], static fn(array $i): bool => ($i['client_key'] ?? '') === $clientKey));
    usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['period'], (string) $a['period']) ?: strcmp((string) $b['ref'], (string) $a['ref']));
    return $rows;
}

/** Jatuh tempo: invoice bulanan = tanggal jangkar langganan; tagihan susulan = 14 hari dari dibuat. */
function adf_ledger_due(array $client, string $period, bool $topup): string
{
    if ($topup) {
        return date('Y-m-d', strtotime('+14 days'));
    }
    $override = (string) ($client['due_date_override'] ?? '');
    if ($override !== '' && substr($override, 0, 7) === $period) {
        return $override;
    }
    $start = (string) ($client['subscription_start_date'] ?? '');
    if ($start === '' || !strtotime($start)) {
        return date('Y-m-t', strtotime($period . '-01'));
    }
    $dim = (int) date('t', strtotime($period . '-01'));
    $due = sprintf('%s-%02d', $period, min((int) date('j', strtotime($start)), $dim));
    // Invoice yang baru dibuat setelah tanggal jatuh temponya lewat: beri tenggang 7 hari
    // (kalau tidak, klien langsung terkunci sebelum sempat membayar).
    return $due < date('Y-m-d') ? date('Y-m-d', strtotime('+7 days')) : $due;
}

/**
 * Hitung ulang satu periode. Invoice "tetap" (lunas, dibatalkan, sudah punya link bayar, atau diedit
 * admin) tidak disentuh dan dihitung sebagai sudah tertagih; hanya invoice terbuka yang disesuaikan.
 *
 * $carryIn = tamu dari periode sebelumnya yang belum tertagih (mis. terdaftar setelah invoice bulan itu
 * dibayar); diserap ke invoice terbuka periode ini. Return: jumlah tamu yang harus diteruskan ke periode
 * berikutnya (tamu periode ini yang muncul setelah invoicenya tetap + carry yang tidak bisa diserap).
 *
 * @param array $carryFrom daftar periode asal tamu susulan (diperbarui)
 */
function adf_ledger_recompute_period(array &$data, array $client, string $period, int $guests, int $carryIn = 0, array &$carryFrom = []): int
{
    $key = (string) $client['client_key'];
    $fee = (float) ($client['per_guest_fee'] ?? 0);
    $base = (float) ($client['base_fee'] ?? 0);

    $idx = [];
    foreach ($data['invoices'] as $i => $inv) {
        if (($inv['client_key'] ?? '') === $key && ($inv['period'] ?? '') === $period) {
            $idx[] = $i;
        }
    }

    $guestsBilled = 0;
    $baseBilled = 0.0;
    $carryBilled = 0;
    $open = null;
    $drop = [];
    foreach ($idx as $i) {
        $inv = $data['invoices'][$i];
        $fixed = ($inv['status'] ?? '') !== 'unpaid' || !empty($inv['locked']) || !empty($inv['edited']);
        if ($fixed) {
            $guestsBilled += (int) $inv['guest_count'];
            $baseBilled += (float) $inv['base_fee'];
            $carryBilled += (int) ($inv['carry_guests'] ?? 0);
        } elseif (($inv['kind'] ?? '') === 'topup') {
            $drop[] = $i; // model lama (tagihan susulan terpisah) tidak dipakai lagi
        } elseif ($open === null) {
            $open = $i;
        } else {
            $drop[] = $i;
        }
    }
    foreach ($drop as $i) {
        unset($data['invoices'][$i]);
    }
    if ($drop) {
        $data['invoices'] = array_values($data['invoices']);
        // indeks bergeser: cari ulang invoice terbuka
        $open = null;
        foreach ($data['invoices'] as $i => $inv) {
            if (($inv['client_key'] ?? '') === $key && ($inv['period'] ?? '') === $period
                && ($inv['status'] ?? '') === 'unpaid' && empty($inv['locked']) && empty($inv['edited'])) {
                $open = $i;
                break;
            }
        }
    }

    $needOwn = max(0, $guests - $guestsBilled);
    $needBase = max(0.0, $base - $baseBilled);
    $needCarry = max(0, $carryIn - $carryBilled);
    $hasInvoice = (bool) array_filter($data['invoices'], static fn(array $i): bool => ($i['client_key'] ?? '') === $key && ($i['period'] ?? '') === $period);

    if ($open !== null) {
        if ($needOwn <= 0 && $needBase <= 0 && $needCarry <= 0) {
            unset($data['invoices'][$open]);
            $data['invoices'] = array_values($data['invoices']);
        } else {
            $data['invoices'][$open]['base_fee'] = $needBase;
            $data['invoices'][$open]['guest_count'] = $needOwn;
            $data['invoices'][$open]['carry_guests'] = $needCarry;
            $data['invoices'][$open]['carry_from'] = $needCarry > 0 ? implode(', ', array_unique($carryFrom)) : '';
            $data['invoices'][$open]['per_guest_fee'] = $fee;
        }
        $carryFrom = [];
        return 0;
    }

    if ($hasInvoice) {
        // Invoice periode ini sudah tetap (mis. lunas): tamu yang bertambah sesudahnya diteruskan ke bulan depan.
        $out = $needOwn + $needCarry;
        if ($needOwn > 0) {
            $carryFrom[] = $period;
        }
        return $out;
    }

    if ($needOwn <= 0 && $needBase <= 0 && $needCarry <= 0) {
        return 0;
    }

    $currentMonth = date('Y-m');
    if ($period > $currentMonth) {
        // Bulan depan baru ditagih setelah bulan ini tidak punya tagihan yang menunggu bayar.
        foreach ($data['invoices'] as $inv) {
            if (($inv['client_key'] ?? '') === $key && ($inv['period'] ?? '') <= $currentMonth && ($inv['status'] ?? '') === 'unpaid') {
                return $needCarry; // belum dibuat: tamu susulan tetap menunggu
            }
        }
    }

    $data['invoices'][] = [
        'id' => uniqid('li', true),
        'client_key' => $key,
        'ref' => $period,
        'kind' => 'recurring',
        'period' => $period,
        'base_fee' => $needBase,
        'guest_count' => $needOwn,
        'carry_guests' => $needCarry,
        'carry_from' => $needCarry > 0 ? implode(', ', array_unique($carryFrom)) : '',
        'per_guest_fee' => $fee,
        'adjustment' => 0,
        'note' => '',
        'status' => 'unpaid',
        'due_date' => adf_ledger_due($client, $period, false),
        'created_at' => date('c'),
        'paid_at' => null,
    ];
    $carryFrom = [];
    return 0;
}

function adf_ledger_recompute_all(array &$data, array $client): void
{
    $key = (string) $client['client_key'];
    $from = (string) ($data['meta'][$key]['from_period'] ?? date('Y-m'));
    $to = date('Y-m', strtotime('first day of next month'));
    $usage = (array) ($data['usage'][$key] ?? []);
    ksort($usage);
    $carry = 0;
    $carryFrom = [];
    foreach ($usage as $period => $row) {
        if (preg_match('/^\d{4}-\d{2}$/', (string) $period) && $period >= $from && $period <= $to) {
            $carry = adf_ledger_recompute_period($data, $client, (string) $period, (int) ($row['guests'] ?? 0), $carry, $carryFrom);
        }
    }
}
/**
 * Proses sinkron dari klien: impor invoice lunas lama, kunci invoice yang sudah punya link bayar,
 * simpan pax per bulan, hitung ulang, lalu kembalikan daftar invoice klien.
 * $payload: usage {YYYY-MM: pax}, local_paid [{ref, base_fee, guest_count, per_guest_fee, total_amount, paid_at}], links [ref]
 */
function adf_ledger_ingest(array $client, array $payload): array
{
    return adf_ledger_mutate(static function (array &$data) use ($client, $payload): array {
        $key = (string) $client['client_key'];
        $data['meta'][$key] = ($data['meta'][$key] ?? []) + ['from_period' => date('Y-m')];
        $data['meta'][$key]['last_sync'] = date('c');

        $known = array_column(array_filter($data['invoices'], static fn(array $i): bool => ($i['client_key'] ?? '') === $key), 'ref');

        // Invoice yang sudah lunas di sisi klien sebelum buku tagihan ada (mis. 2026-10 dibayar Rp 1.000).
        foreach ((array) ($payload['local_paid'] ?? []) as $lp) {
            $ref = trim((string) ($lp['ref'] ?? ''));
            if ($ref === '' || in_array($ref, $known, true) || !preg_match('/^(\d{4}-\d{2}|TOPUP-\d{4}-\d{2}-\d+)$/', $ref)) {
                continue;
            }
            $period = substr($ref, 0, 5) === 'TOPUP' ? substr($ref, 6, 7) : $ref;
            $inv = [
                'id' => uniqid('li', true),
                'client_key' => $key,
                'ref' => $ref,
                'kind' => $ref === $period ? 'recurring' : 'topup',
                'period' => $period,
                'base_fee' => (float) ($lp['base_fee'] ?? 0),
                'guest_count' => (int) ($lp['guest_count'] ?? 0),
                'per_guest_fee' => (float) ($lp['per_guest_fee'] ?? 0),
                'adjustment' => 0,
                'note' => 'Diimpor dari sistem klien',
                'status' => 'paid',
                'due_date' => null,
                'created_at' => date('c'),
                'paid_at' => (string) ($lp['paid_at'] ?? date('c')),
            ];
            // Samakan total dengan yang benar-benar dibayar.
            $inv['adjustment'] = round((float) ($lp['total_amount'] ?? 0) - adf_ledger_total($inv), 2);
            $data['invoices'][] = $inv;
            $known[] = $ref;
        }

        // Link bayar sudah dibuat di klien -> nominal dikunci supaya cocok dengan link.
        $links = array_flip(array_map('strval', (array) ($payload['links'] ?? [])));
        foreach ($data['invoices'] as &$inv) {
            if (($inv['client_key'] ?? '') === $key && ($inv['status'] ?? '') === 'unpaid' && isset($links[$inv['ref']])) {
                $inv['locked'] = true;
            }
        }
        unset($inv);

        foreach ((array) ($payload['usage'] ?? []) as $period => $guests) {
            if (preg_match('/^\d{4}-\d{2}$/', (string) $period)) {
                $data['usage'][$key][(string) $period] = ['guests' => max(0, (int) $guests), 'at' => date('c')];
            }
        }

        adf_ledger_recompute_all($data, $client);
        return adf_ledger_for_client($data, $key);
    });
}

function adf_ledger_find_index(array $data, string $id): ?int
{
    foreach ($data['invoices'] as $i => $inv) {
        if (($inv['id'] ?? '') === $id) {
            return $i;
        }
    }
    return null;
}

/** Tandai lunas berdasarkan ref (dipanggil saat klien melaporkan pembayaran). */
function adf_ledger_mark_paid(string $clientKey, string $ref, string $paidAt): bool
{
    return (bool) adf_ledger_mutate(static function (array &$data) use ($clientKey, $ref, $paidAt): bool {
        foreach ($data['invoices'] as &$inv) {
            if (($inv['client_key'] ?? '') === $clientKey && ($inv['ref'] ?? '') === $ref && ($inv['status'] ?? '') !== 'paid') {
                $inv['status'] = 'paid';
                $inv['paid_at'] = $paidAt;
                return true;
            }
        }
        return false;
    });
}

/** Aksi admin atas satu invoice: cancel | paid | edit | auto. Return pesan hasil. */
function adf_ledger_admin_action(string $id, string $action, array $fields = []): string
{
    return (string) adf_ledger_mutate(static function (array &$data) use ($id, $action, $fields): string {
        $i = adf_ledger_find_index($data, $id);
        if ($i === null) {
            return 'Invoice tidak ditemukan.';
        }
        $inv = &$data['invoices'][$i];
        if ($action === 'cancel') {
            if ($inv['status'] !== 'unpaid') {
                return 'Hanya invoice belum dibayar yang bisa dibatalkan.';
            }
            $inv['status'] = 'cancelled';
            return 'Invoice dibatalkan (tamu & biaya di dalamnya dianggap dibebaskan).';
        }
        if ($action === 'paid') {
            if ($inv['status'] !== 'unpaid') {
                return 'Invoice sudah tidak berstatus belum dibayar.';
            }
            $inv['status'] = 'paid';
            $inv['paid_at'] = date('c');
            return 'Invoice ditandai lunas.';
        }
        if ($action === 'auto') {
            $inv['edited'] = false;
            return 'Invoice dihitung otomatis lagi.';
        }
        if ($action === 'edit') {
            if ($inv['status'] === 'paid') {
                return 'Invoice lunas tidak bisa diedit.';
            }
            $inv['base_fee'] = max(0, (float) ($fields['base_fee'] ?? $inv['base_fee']));
            $inv['guest_count'] = max(0, (int) ($fields['guest_count'] ?? $inv['guest_count']));
            $inv['carry_guests'] = max(0, (int) ($fields['carry_guests'] ?? ($inv['carry_guests'] ?? 0)));
            $inv['adjustment'] = (float) ($fields['adjustment'] ?? 0);
            $inv['note'] = mb_substr(trim((string) ($fields['note'] ?? '')), 0, 200);
            if (!empty($fields['due_date']) && strtotime((string) $fields['due_date'])) {
                $inv['due_date'] = date('Y-m-d', strtotime((string) $fields['due_date']));
            }
            $inv['edited'] = true; // tidak ditimpa hitung otomatis lagi
            return 'Invoice diperbarui. Nilai ini tidak lagi dihitung otomatis (bisa dikembalikan lewat "Otomatis").';
        }
        return 'Aksi tidak dikenal.';
    });
}

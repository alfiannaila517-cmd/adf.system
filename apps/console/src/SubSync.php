<?php

declare(strict_types=1);

/**
 * Pintu sinkron langganan untuk sistem bisnis — protokol SAMA PERSIS dengan adfsystem.store/api/subscription-*.php
 * (client_key + client_token di body JSON/form; jawaban JSON sama), supaya sistem bisnis cukup mengganti alamat sinkron.
 * Buku tagihan per tamu (dipakai Karimunjawa Explore) memakai algoritma Store apa adanya (adf_ledger_*), datanya di
 * subscription_charges / subscription_usage / subscriptions.ledger_from.
 */
final class SubSync
{
    private static function db(): PDO
    {
        return Biz::db();
    }

    /** Body JSON (atau form) dari sistem bisnis. */
    public static function payload(): array
    {
        $in = json_decode((string)file_get_contents('php://input'), true);
        return is_array($in) ? $in : $_POST;
    }

    public static function respond(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Cari langganan dari client_key + client_token (cocok persis dulu, lalu bentuk ternormalisasi seperti Store).
     * @return array langganan + nama & id bisnis
     */
    public static function auth(array $p, bool $needPeriod = false): array
    {
        $key = trim((string)($p['client_key'] ?? ''));
        $token = trim((string)($p['client_token'] ?? ''));
        $period = trim((string)($p['period'] ?? ''));
        if ($key === '' || $token === '' || ($needPeriod && $period === '')) {
            self::respond(400, ['error' => $needPeriod ? 'client_key, client_token and period required' : 'client_key and client_token required']);
        }
        $rows = self::db()->query('SELECT s.*, b.name AS business_name, b.client_id FROM subscriptions s JOIN businesses b ON b.id = s.business_id')->fetchAll();
        $norm = static fn (string $k): string => (string)preg_replace('/[\s_-]+/', '', strtolower(trim($k)));
        $found = null;
        foreach ($rows as $r) {
            if ($r['client_key'] === $key) {
                $found = $r;
                break;
            }
        }
        if (!$found) {
            foreach ($rows as $r) {
                if ($norm((string)$r['client_key']) === $norm($key)) {
                    $found = $r;
                    break;
                }
            }
        }
        if (!$found || !hash_equals((string)$found['client_token'], $token)) {
            self::respond(403, ['error' => 'invalid client_key or client_token']);
        }
        self::db()->prepare('UPDATE subscriptions SET last_sync_at = NOW() WHERE id = ?')->execute([(int)$found['id']]);
        return $found;
    }

    public static function clientName(array $sub): string
    {
        return (string)($sub['display_name'] ?: $sub['business_name']);
    }

    /** Jawaban subscription-config.php (field & tipe sama dengan Store). */
    public static function config(array $sub): array
    {
        $s = Invoice::settings();
        return [
            'client_name' => self::clientName($sub),
            'base_fee' => (float)$sub['base_fee'],
            'per_guest_fee' => (float)$sub['per_guest_fee'],
            'subscription_start_date' => (string)($sub['start_date'] ?? ''),
            'locked' => (bool)$sub['locked'],
            'due_date_override' => (string)($sub['due_date_override'] ?? ''),
            'pakasir_slug' => (string)($sub['pakasir_slug'] ?? ''),
            'pakasir_api_key' => (string)($sub['pakasir_api_key'] ?? ''),
            'pakasir_webhook_secret' => (string)($sub['pakasir_webhook_secret'] ?? ''),
            'provider_name' => (string)$s['company_name'],
            'provider_logo' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'console.adfsystem.store') . '/logo.png',
            'provider_address' => (string)$s['address'],
            'provider_email' => (string)$s['email'],
            'updated_at' => $sub['updated_at'] ? date('c', strtotime((string)$sub['updated_at'])) : null,
            // Pembayaran online lewat Console (Mayar): sistem bisnis minta link ke api/subscription-pay.php, bukan ke Pakasir
            'payment_gateway' => Mayar::configured() ? 'console' : 'pakasir',
            // Tagihan yang sudah lunas di Console (dibayar online / ditandai lunas admin) → ikut lunas di sistem bisnis
            'paid_periods' => Pay::paidPeriods((int)$sub['business_id']),
        ];
    }

    /** Invoice Console yang ditagihkan lewat sistem bisnis ini (status belum dibayar) → daftar "tagihan manual". */
    public static function manualInvoices(array $sub): array
    {
        $st = self::db()->prepare("SELECT * FROM invoices WHERE business_id = ? AND kind = 'manual' AND status = 'unpaid' ORDER BY due_date, id");
        $st->execute([(int)$sub['business_id']]);
        $out = [];
        foreach ($st->fetchAll() as $inv) {
            $full = Invoice::find((int)$inv['id']);
            $desc = trim((string)($full['items'][0]['description'] ?? '')) ?: (string)$inv['number'];
            if (count($full['items']) > 1) {
                $desc .= ' (+' . (count($full['items']) - 1) . ' lainnya)';
            }
            $out[] = [
                'id' => 'CI' . (int)$inv['id'],
                'description' => $inv['number'] . ' · ' . $desc,
                'amount' => Invoice::totals($full)['total'],
                'due_date' => (string)$inv['due_date'],
                'created_at' => date('c', strtotime((string)$inv['created_at'])),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ buku tagihan (port adf_ledger_* Store)

    /** Bentuk "client" yang dipakai algoritma ledger Store. */
    private static function ledgerClient(array $sub): array
    {
        return [
            'client_key' => (string)$sub['client_key'],
            'base_fee' => (float)$sub['base_fee'],
            'per_guest_fee' => (float)$sub['per_guest_fee'],
            'subscription_start_date' => (string)($sub['start_date'] ?? ''),
            'due_date_override' => (string)($sub['due_date_override'] ?? ''),
        ];
    }

    public static function ledgerTotal(array $inv): float
    {
        return round((float)($inv['base_fee'] ?? 0) + ((int)($inv['guest_count'] ?? 0) + (int)($inv['carry_guests'] ?? 0)) * (float)($inv['per_guest_fee'] ?? 0) + (float)($inv['adjustment'] ?? 0), 2);
    }

    private static function ledgerDue(array $client, string $period): string
    {
        $override = (string)($client['due_date_override'] ?? '');
        if ($override !== '' && substr($override, 0, 7) === $period) {
            return $override;
        }
        $start = (string)($client['subscription_start_date'] ?? '');
        if ($start === '' || !strtotime($start)) {
            return date('Y-m-t', strtotime($period . '-01'));
        }
        $dim = (int)date('t', strtotime($period . '-01'));
        $due = sprintf('%s-%02d', $period, min((int)date('j', strtotime($start)), $dim));
        // Invoice yang baru dibuat setelah tanggal jatuh temponya lewat: tenggang 7 hari (supaya tidak langsung terkunci)
        return $due < date('Y-m-d') ? date('Y-m-d', strtotime('+7 days')) : $due;
    }

    /** Hitung ulang satu periode — sama dengan adf_ledger_recompute_period Store. Return tamu yang diteruskan. */
    private static function recomputePeriod(array &$inv, array $client, string $period, int $guests, int $carryIn, array &$carryFrom): int
    {
        $fee = (float)$client['per_guest_fee'];
        $base = (float)$client['base_fee'];
        $guestsBilled = 0;
        $baseBilled = 0.0;
        $carryBilled = 0;
        $open = null;
        $drop = [];
        foreach ($inv as $i => $row) {
            if ($row['period'] !== $period || !empty($row['_delete'])) {
                continue; // tagihan yang sudah dibuang (Store: unset) tidak dihitung lagi
            }
            $fixed = $row['status'] !== 'unpaid' || !empty($row['locked']) || !empty($row['edited']);
            if ($fixed) {
                $guestsBilled += (int)$row['guest_count'];
                $baseBilled += (float)$row['base_fee'];
                $carryBilled += (int)($row['carry_guests'] ?? 0);
            } elseif (($row['kind'] ?? '') === 'topup') {
                $drop[] = $i; // model lama (tagihan susulan terpisah) tidak dipakai lagi
            } elseif ($open === null) {
                $open = $i;
            } else {
                $drop[] = $i;
            }
        }
        foreach ($drop as $i) {
            $inv[$i]['_delete'] = true;
        }
        $needOwn = max(0, $guests - $guestsBilled);
        $needBase = max(0.0, $base - $baseBilled);
        $needCarry = max(0, $carryIn - $carryBilled);
        $hasInvoice = (bool)array_filter($inv, fn ($r) => $r['period'] === $period && empty($r['_delete']));

        if ($open !== null) {
            if ($needOwn <= 0 && $needBase <= 0 && $needCarry <= 0) {
                $inv[$open]['_delete'] = true;
            } else {
                $inv[$open]['base_fee'] = $needBase;
                $inv[$open]['guest_count'] = $needOwn;
                $inv[$open]['carry_guests'] = $needCarry;
                $inv[$open]['carry_from'] = $needCarry > 0 ? implode(', ', array_unique($carryFrom)) : '';
                $inv[$open]['per_guest_fee'] = $fee;
            }
            $carryFrom = [];
            return 0;
        }
        if ($hasInvoice) {
            // Invoice periode ini sudah tetap (mis. lunas): tamu yang bertambah sesudahnya diteruskan ke bulan depan
            if ($needOwn > 0) {
                $carryFrom[] = $period;
            }
            return $needOwn + $needCarry;
        }
        if ($needOwn <= 0 && $needBase <= 0 && $needCarry <= 0) {
            return 0;
        }
        $currentMonth = date('Y-m');
        if ($period > $currentMonth) {
            // Bulan depan baru ditagih setelah bulan ini tidak punya tagihan yang menunggu bayar
            foreach ($inv as $row) {
                if (empty($row['_delete']) && $row['period'] <= $currentMonth && $row['status'] === 'unpaid') {
                    return $needCarry;
                }
            }
        }
        $inv[] = [
            'id' => null, 'ref' => $period, 'kind' => 'recurring', 'period' => $period, 'base_fee' => $needBase, 'guest_count' => $needOwn,
            'carry_guests' => $needCarry, 'carry_from' => $needCarry > 0 ? implode(', ', array_unique($carryFrom)) : '', 'per_guest_fee' => $fee,
            'adjustment' => 0, 'note' => '', 'status' => 'unpaid', 'locked' => 0, 'edited' => 0, 'due_date' => self::ledgerDue($client, $period), 'paid_at' => null,
        ];
        $carryFrom = [];
        return 0;
    }

    /**
     * subscription-invoices.php: impor invoice lunas lama, kunci yang sudah punya link bayar, simpan pax per bulan,
     * hitung ulang, lalu kembalikan daftar invoice (format Store).
     */
    public static function ledgerIngest(array $sub, array $p): array
    {
        $db = self::db();
        $bid = (int)$sub['business_id'];
        $client = self::ledgerClient($sub);
        $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM subscriptions WHERE id = ? FOR UPDATE')->execute([(int)$sub['id']]); // satu sinkron per klien
            $from = $db->query('SELECT ledger_from FROM subscriptions WHERE id = ' . (int)$sub['id'])->fetchColumn() ?: date('Y-m');
            $db->prepare('UPDATE subscriptions SET ledger_from = COALESCE(ledger_from, ?) WHERE id = ?')->execute([$from, (int)$sub['id']]);

            $st = $db->prepare('SELECT * FROM subscription_charges WHERE business_id = ?');
            $st->execute([$bid]);
            $inv = $st->fetchAll();
            $known = array_column($inv, 'ref');

            // Invoice yang sudah lunas di sisi klien sebelum buku tagihan ada
            foreach ((array)($p['local_paid'] ?? []) as $lp) {
                $ref = trim((string)($lp['ref'] ?? ''));
                if ($ref === '' || in_array($ref, $known, true) || !preg_match('/^(\d{4}-\d{2}|TOPUP-\d{4}-\d{2}-\d+)$/', $ref)) {
                    continue;
                }
                $period = substr($ref, 0, 5) === 'TOPUP' ? substr($ref, 6, 7) : $ref;
                $row = [
                    'id' => null, 'ref' => $ref, 'kind' => $ref === $period ? 'recurring' : 'topup', 'period' => $period,
                    'base_fee' => (float)($lp['base_fee'] ?? 0), 'guest_count' => (int)($lp['guest_count'] ?? 0), 'carry_guests' => 0, 'carry_from' => '',
                    'per_guest_fee' => (float)($lp['per_guest_fee'] ?? 0), 'adjustment' => 0, 'note' => 'Diimpor dari sistem klien', 'status' => 'paid',
                    'locked' => 0, 'edited' => 0, 'due_date' => null, 'paid_at' => self::sqlTime((string)($lp['paid_at'] ?? '')),
                ];
                $row['adjustment'] = round((float)($lp['total_amount'] ?? 0) - self::ledgerTotal($row), 2);
                $inv[] = $row;
                $known[] = $ref;
            }

            // Link bayar sudah dibuat di klien → nominal dikunci supaya cocok dengan link
            $links = array_flip(array_map('strval', (array)($p['links'] ?? [])));
            foreach ($inv as &$row) {
                if ($row['status'] === 'unpaid' && isset($links[$row['ref']])) {
                    $row['locked'] = 1;
                }
            }
            unset($row);

            $up = $db->prepare('INSERT INTO subscription_usage (business_id, period, guests) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE guests = VALUES(guests)');
            foreach ((array)($p['usage'] ?? []) as $period => $guests) {
                if (preg_match('/^\d{4}-\d{2}$/', (string)$period)) {
                    $up->execute([$bid, (string)$period, max(0, (int)$guests)]);
                }
            }

            // Hitung ulang dari ledger_from sampai bulan depan, berurutan, tamu susulan diteruskan
            $st = $db->prepare('SELECT period, guests FROM subscription_usage WHERE business_id = ? AND period >= ? AND period <= ? ORDER BY period');
            $st->execute([$bid, $from, date('Y-m', strtotime('first day of next month'))]);
            $carry = 0;
            $carryFrom = [];
            foreach ($st->fetchAll() as $u) {
                $carry = self::recomputePeriod($inv, $client, (string)$u['period'], (int)$u['guests'], $carry, $carryFrom);
            }

            self::persist($bid, $inv);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::ledgerList($bid);
    }

    /** Tulis hasil hitung ke subscription_charges (hapus / ubah / tambah). */
    private static function persist(int $bid, array $inv): void
    {
        $db = self::db();
        $del = $db->prepare('DELETE FROM subscription_charges WHERE id = ? AND business_id = ?');
        $upd = $db->prepare('UPDATE subscription_charges SET base_fee = ?, guest_count = ?, carry_guests = ?, carry_from = ?, per_guest_fee = ?, adjustment = ?, locked = ? WHERE id = ?');
        $ins = $db->prepare('INSERT INTO subscription_charges (business_id, kind, ref, period, base_fee, guest_count, carry_guests, carry_from, per_guest_fee, adjustment, note, status, locked, edited, due_date, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($inv as $r) {
            if ($r['id'] !== null && !empty($r['_delete'])) {
                $del->execute([(int)$r['id'], $bid]);
            } elseif ($r['id'] !== null) {
                $upd->execute([$r['base_fee'], $r['guest_count'], $r['carry_guests'] ?? 0, ($r['carry_from'] ?? '') ?: null, $r['per_guest_fee'], $r['adjustment'], (int)!empty($r['locked']), (int)$r['id']]);
            } elseif (empty($r['_delete'])) {
                $ins->execute([$bid, $r['kind'], $r['ref'], $r['period'], $r['base_fee'], $r['guest_count'], $r['carry_guests'] ?? 0, ($r['carry_from'] ?? '') ?: null,
                    $r['per_guest_fee'], $r['adjustment'], $r['note'] ?: null, $r['status'], (int)!empty($r['locked']), (int)!empty($r['edited']), $r['due_date'], $r['paid_at']]);
            }
        }
    }

    /** Daftar invoice format Store, urut periode lalu ref (terbaru dulu). */
    public static function ledgerList(int $bid): array
    {
        $st = self::db()->prepare('SELECT * FROM subscription_charges WHERE business_id = ? ORDER BY period DESC, ref DESC');
        $st->execute([$bid]);
        return array_map(fn ($r) => [
            'ref' => $r['ref'], 'kind' => $r['kind'], 'period' => $r['period'], 'base_fee' => (float)$r['base_fee'], 'guest_count' => (int)$r['guest_count'],
            'carry_guests' => (int)$r['carry_guests'], 'carry_from' => (string)($r['carry_from'] ?? ''), 'per_guest_fee' => (float)$r['per_guest_fee'],
            'adjustment' => (float)$r['adjustment'], 'note' => (string)($r['note'] ?? ''), 'total' => self::ledgerTotal($r), 'status' => $r['status'],
            'due_date' => $r['due_date'], 'paid_at' => $r['paid_at'] ? date('c', strtotime((string)$r['paid_at'])) : null,
        ], $st->fetchAll());
    }

    // ------------------------------------------------------------------ pembayaran & email

    /**
     * Catat pembayaran yang dilaporkan sistem bisnis (subscription-payment-notify / -record): order (idempoten per order_id),
     * tagihan periode itu → lunas, tagihan manual Console (MANUAL-CI<id>) → lunas. Return order_id.
     */
    public static function recordPayment(array $sub, array $p): string
    {
        $db = self::db();
        $period = trim((string)$p['period']);
        $paidAt = self::sqlTime((string)($p['paid_at'] ?? '')) ?? date('Y-m-d H:i:s');
        $amount = (int)round((float)($p['total_amount'] ?? 0));
        $orderId = trim((string)($p['order_id'] ?? '')) ?: 'SUB-' . strtoupper((string)$sub['client_key']) . '-' . $period;
        $title = trim((string)($p['description'] ?? '')) ?: (str_starts_with($period, 'MANUAL-') ? 'Tagihan Manual' : 'Langganan ' . $period);

        // Sudah lunas di Console (dibayar lewat Mayar / ditandai lunas admin) → sistem bisnis hanya ikut melaporkan:
        // jangan catat transaksi kedua (email kuitansi tetap dikirim oleh endpoint pemanggil)
        $chk = $db->prepare("SELECT 1 FROM subscription_charges WHERE business_id = ? AND ref = ? AND status = 'paid'");
        $chk->execute([(int)$sub['business_id'], $period]);
        $paidHere = (bool)$chk->fetchColumn();
        if (!$paidHere && preg_match('/^MANUAL-CI(\d+)$/', $period, $mm)) {
            $chk = $db->prepare("SELECT 1 FROM invoices WHERE id = ? AND status = 'paid'");
            $chk->execute([(int)$mm[1]]);
            $paidHere = (bool)$chk->fetchColumn();
        }
        if ($paidHere) {
            return $orderId;
        }

        $exists = $db->prepare('SELECT status FROM orders WHERE order_id = ?');
        $exists->execute([mb_substr($orderId, 0, 80)]);
        $wasCompleted = $exists->fetchColumn() === 'completed';
        $db->prepare("INSERT INTO orders (order_id, product_title, amount, name, status, source, client_id, business_id, created_at, completed_at)
                VALUES (?, ?, ?, ?, 'completed', 'subscription', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = 'completed', completed_at = VALUES(completed_at)")
            ->execute([mb_substr($orderId, 0, 80), mb_substr($title, 0, 200), $amount, self::clientName($sub), (int)$sub['client_id'], (int)$sub['business_id'], $paidAt, $paidAt]);

        $db->prepare("UPDATE subscription_charges SET status = 'paid', paid_at = ? WHERE business_id = ? AND ref = ? AND status <> 'paid' LIMIT 1")
            ->execute([$paidAt, (int)$sub['business_id'], $period]);
        if (preg_match('/^MANUAL-CI(\d+)$/', $period, $m)) {
            $st = $db->prepare("SELECT id FROM invoices WHERE id = ? AND business_id = ? AND status = 'unpaid'");
            $st->execute([(int)$m[1], (int)$sub['business_id']]);
            if ($st->fetchColumn()) {
                Invoice::setStatus((int)$m[1], 'paid', 'pakasir');
            }
        }
        if (!$wasCompleted) {
            Telegram::send('💰 <b>Pembayaran langganan</b>' . "\n" . Telegram::esc(self::clientName($sub)) . ' · ' . Telegram::esc($title) . "\n<b>" . Telegram::esc(Invoice::rp($amount)) . '</b>');
        }
        return $orderId;
    }

    /** Email ke notify_email langganan. null = tidak ada alamat (dilewati). */
    public static function notify(array $sub, string $subject, string $html): ?array
    {
        $to = trim((string)($sub['notify_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $ok = Mailer::send($to, $subject, $html, (string)Invoice::settings()['company_name']);
        return ['emailed' => $ok, 'error' => $ok ? null : Mailer::lastError()];
    }

    private static function sqlTime(string $v): ?string
    {
        $t = $v !== '' ? strtotime($v) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }
}

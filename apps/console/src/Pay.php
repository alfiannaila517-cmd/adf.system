<?php

declare(strict_types=1);

/**
 * Pembayaran online lewat Console (Mayar) — pengganti Pakasir yang dulu dipanggil langsung oleh tiap sistem klien.
 *   forPeriod()  tagihan langganan sistem bisnis (periode YYYY-MM / susulan, atau MANUAL-CI<id> = invoice manual)
 *   forInvoice() link "Bayar sekarang" di invoice publik
 *   settle()     tandai lunas: tagihan / invoice → paid, + baris orders (Transaksi & Keuangan), Telegram
 *   check()      cek ulang status ke Mayar (webhook, tombol cek, atau saat sistem klien sinkron)
 * Satu link dipakai ulang selama masih berlaku dan jumlahnya sama.
 */
final class Pay
{
    private static function db(): PDO
    {
        return Biz::db();
    }

    /** Link aktif yang bisa dipakai ulang */
    private static function reusable(string $type, string $ref, float $amount): ?array
    {
        $st = self::db()->prepare("SELECT * FROM payment_links WHERE ref_type = ? AND ref = ? AND status = 'pending' AND amount = ?
            AND (expires_at IS NULL OR expires_at > DATE_ADD(NOW(), INTERVAL 1 HOUR)) ORDER BY id DESC LIMIT 1");
        $st->execute([$type, $ref, $amount]);
        return $st->fetch() ?: null;
    }

    private static function create(string $type, string $ref, ?int $bizId, string $desc, float $amount, array $cust, int $ttlDays = 3): array
    {
        if ($amount < 1000) {
            throw new RuntimeException('Jumlah terlalu kecil untuk dibayar online.');
        }
        if ($link = self::reusable($type, $ref, $amount)) {
            return $link;
        }
        $exp = time() + $ttlDays * 86400;
        // Email ke Mayar = alamat ADF khusus (tagihan@adfsystem.store → Gmail lewat Email Routing; BUKAN email akun Mayar,
        // yang ditolak Mayar): email Mayar masuk ke ADF, pelanggan cukup menerima email dari sistem ADF.
        // HP = nomor pelanggan (bila ada), supaya pengingat WA Mayar sampai ke yang membayar.
        $own = Invoice::settings();
        $m = Mayar::createInvoice((string)($cust['name'] ?? ''), (string)Secrets::get('MAYAR_CUSTOMER_EMAIL', 'tagihan@adfsystem.store'), (string)(($cust['phone'] ?? '') ?: $own['whatsapp']), $desc, (int)round($amount), $exp, ['ref_type' => $type, 'ref' => $ref]);
        self::db()->prepare('INSERT INTO payment_links (gateway, ref_type, ref, business_id, description, amount, gateway_id, gateway_txn, link, expires_at) VALUES (\'mayar\', ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$type, $ref, $bizId, mb_substr($desc, 0, 200), $amount, $m['id'], $m['transactionId'] ?: null, $m['link'], date('Y-m-d H:i:s', $m['expiredAt'] ? intdiv($m['expiredAt'], 1000) : $exp)]);
        return self::find((int)self::db()->lastInsertId());
    }

    private static function find(int $id): array
    {
        $st = self::db()->prepare('SELECT * FROM payment_links WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new RuntimeException('Link pembayaran tidak ditemukan.');
    }

    /** Kontak penagihan sebuah bisnis: email langganan / email & HP grup */
    private static function contact(array $sub): array
    {
        $biz = Biz::business((int)$sub['business_id']);
        $c = $biz ? Biz::client((int)$biz['client_id']) : null;
        // Kontak per bisnis (Pengaturan langganan), bukan kontak grup
        return ['name' => SubSync::clientName($sub), 'email' => (string)($sub['notify_email'] ?? ''), 'phone' => (string)($sub['notify_phone'] ?? '')];
    }

    /**
     * Link bayar untuk satu tagihan dari sistem bisnis. Jumlah dihitung Console (bukan dari klien):
     * MANUAL-CI<id> = total invoice; periode = tagihan di buku tagihan, atau biaya dasar langganan bila belum ada.
     */
    public static function forPeriod(array $sub, string $period, string $desc = ''): array
    {
        $bid = (int)$sub['business_id'];
        if (preg_match('/^MANUAL-CI(\d+)$/', $period, $m)) {
            $inv = Invoice::find((int)$m[1]);
            if (!$inv || (int)$inv['business_id'] !== $bid || $inv['status'] !== 'unpaid') {
                throw new RuntimeException('Tagihan manual tidak ditemukan atau sudah lunas.');
            }
            return self::create('invoice', (string)$inv['id'], $bid, $inv['number'] . ' · ' . ($inv['items'][0]['description'] ?? 'Tagihan'), Invoice::totals($inv)['total'], self::contact($sub));
        }
        $st = self::db()->prepare('SELECT * FROM subscription_charges WHERE business_id = ? AND ref = ?');
        $st->execute([$bid, $period]);
        $ch = $st->fetch();
        if ($ch && $ch['status'] !== 'unpaid') {
            throw new RuntimeException('Tagihan ' . $period . ' sudah ' . ($ch['status'] === 'paid' ? 'lunas' : 'dibatalkan') . '.');
        }
        $amount = $ch ? Biz::chargeTotal($ch) : (float)$sub['base_fee'];
        return self::create('charge', $period, $bid, ($desc !== '' ? $desc : 'Langganan ' . $period) . ' · ' . SubSync::clientName($sub), $amount, self::contact($sub));
    }

    /** Link "Bayar sekarang" untuk invoice (link publik) */
    public static function forInvoice(array $inv): array
    {
        if ($inv['status'] !== 'unpaid') {
            throw new RuntimeException('Invoice ini tidak dalam status belum dibayar.');
        }
        $cust = ['name' => (string)($inv['customer_company'] ?: $inv['customer_name']), 'email' => (string)$inv['customer_email'], 'phone' => (string)$inv['customer_phone']];
        return self::create('invoice', (string)$inv['id'], $inv['business_id'] ? (int)$inv['business_id'] : null, $inv['number'] . ' · ' . ($inv['items'][0]['description'] ?? 'Invoice'), Invoice::totals($inv)['total'], $cust, 7);
    }

    /** Cek status ke Mayar; lunas → settle(). Return true bila (sudah) lunas. */
    public static function check(array $link): bool
    {
        if ($link['status'] === 'paid') {
            return true;
        }
        self::db()->prepare('UPDATE payment_links SET checked_at = NOW() WHERE id = ?')->execute([(int)$link['id']]);
        $d = Mayar::invoice((string)$link['gateway_id']);
        $status = strtolower((string)($d['status'] ?? ''));
        if ($status === 'paid') {
            self::settle($link, (string)($d['transactionId'] ?? ''));
            return true;
        }
        if ($status === 'closed' || ($link['expires_at'] && strtotime((string)$link['expires_at']) < time() - 86400)) {
            self::db()->prepare("UPDATE payment_links SET status = 'expired' WHERE id = ? AND status = 'pending'")->execute([(int)$link['id']]);
        }
        return false;
    }

    /** Tandai lunas (idempoten): tagihan / invoice, baris orders, Telegram */
    public static function settle(array $link, string $txn = ''): void
    {
        $db = self::db();
        $upd = $db->prepare("UPDATE payment_links SET status = 'paid', paid_at = NOW(), gateway_txn = COALESCE(NULLIF(?, ''), gateway_txn) WHERE id = ? AND status <> 'paid'");
        $upd->execute([$txn, (int)$link['id']]);
        if (!$upd->rowCount()) {
            return; // sudah diproses sebelumnya
        }
        $bid = $link['business_id'] !== null ? (int)$link['business_id'] : null;
        $biz = $bid ? Biz::business($bid) : null;
        $now = date('Y-m-d H:i:s');
        if ($link['ref_type'] === 'invoice') {
            if (($inv = Invoice::find((int)$link['ref'])) && $inv['status'] !== 'paid') {
                Invoice::setStatus((int)$link['ref'], 'paid', 'mayar');
            }
        } elseif ($link['ref_type'] === 'charge' && $bid) {
            $st = $db->prepare('SELECT id FROM subscription_charges WHERE business_id = ? AND ref = ?');
            $st->execute([$bid, $link['ref']]);
            if ($st->fetchColumn()) {
                $db->prepare("UPDATE subscription_charges SET status = 'paid', paid_at = ? WHERE business_id = ? AND ref = ?")->execute([$now, $bid, $link['ref']]);
            } else {
                // Sistem bisnis tanpa buku tagihan (tarif tetap) → catat tagihan periode itu sebagai lunas
                $db->prepare("INSERT INTO subscription_charges (business_id, kind, ref, period, base_fee, note, status, paid_at) VALUES (?, 'recurring', ?, ?, ?, 'Dibayar online (Mayar)', 'paid', ?)")
                    ->execute([$bid, $link['ref'], substr((string)$link['ref'], 0, 7), (float)$link['amount'], $now]);
            }
        }
        $db->prepare("INSERT INTO orders (order_id, txn_id, product_title, amount, name, status, source, payment_method, client_id, business_id, created_at, completed_at)
                VALUES (?, ?, ?, ?, ?, 'completed', ?, 'mayar', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'completed', completed_at = VALUES(completed_at)")
            ->execute(['MYR-' . $link['gateway_id'], $txn ?: null, (string)$link['description'], (float)$link['amount'], $biz['name'] ?? (string)$link['description'],
                $link['ref_type'] === 'order' ? 'checkout' : 'subscription', $biz['client_id'] ?? null, $bid, $link['created_at'], $now]);
        Telegram::send('💰 <b>Pembayaran masuk (Mayar)</b>' . "\n" . Telegram::esc((string)$link['description']) . "\n<b>" . Telegram::esc(Invoice::rp((float)$link['amount'])) . '</b>');
    }

    /** Cek link yang masih menunggu untuk satu bisnis (dipanggil saat sistem klien sinkron; tiap link maks. 1×/2 menit) */
    public static function checkPending(int $businessId): void
    {
        if (!Mayar::configured()) {
            return;
        }
        $st = self::db()->prepare("SELECT * FROM payment_links WHERE business_id = ? AND status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 10 DAY)
            AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)) ORDER BY id DESC LIMIT 3");
        $st->execute([$businessId]);
        foreach ($st->fetchAll() as $l) {
            try {
                self::check($l);
            } catch (Throwable) {
                // Mayar sedang tidak bisa dihubungi — coba lagi di sinkron berikutnya
            }
        }
    }

    /** Webhook: cocokkan id dari payload; bila tidak ada yang cocok, cek link menunggu 7 hari terakhir. Return jumlah yang lunas. */
    public static function webhook(array $payload): int
    {
        $d = (array)($payload['data'] ?? []);
        $ids = array_values(array_filter(array_unique(array_map('strval', [$d['id'] ?? '', $d['transactionId'] ?? '', $d['paymentLinkId'] ?? '', $d['productId'] ?? '', $d['invoiceId'] ?? '']))));
        $links = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = self::db()->prepare("SELECT * FROM payment_links WHERE status = 'pending' AND (gateway_id IN ({$in}) OR gateway_txn IN ({$in}))");
            $st->execute([...$ids, ...$ids]);
            $links = $st->fetchAll();
        }
        if (!$links) {
            $links = self::db()->query("SELECT * FROM payment_links WHERE status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 15")->fetchAll();
        }
        $n = 0;
        foreach ($links as $l) {
            try {
                $n += self::check($l) ? 1 : 0;
            } catch (Throwable) {
            }
        }
        return $n;
    }

    /** Periode yang sudah lunas di Console (120 hari) — dikirim ke sistem bisnis agar tagihan lokalnya ikut lunas */
    public static function paidPeriods(int $businessId): array
    {
        $st = self::db()->prepare("SELECT ref FROM subscription_charges WHERE business_id = ? AND status = 'paid' AND COALESCE(paid_at, created_at) > DATE_SUB(NOW(), INTERVAL 120 DAY)");
        $st->execute([$businessId]);
        $out = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = self::db()->prepare("SELECT id FROM invoices WHERE business_id = ? AND kind = 'manual' AND status = 'paid' AND COALESCE(paid_at, created_at) > DATE_SUB(NOW(), INTERVAL 120 DAY)");
        $st->execute([$businessId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            $out[] = 'MANUAL-CI' . $iid;
        }
        return array_values(array_map('strval', $out));
    }
}

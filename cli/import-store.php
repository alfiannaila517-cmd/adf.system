<?php

/**
 * Impor data adfsystem.store lama (folder data/ berisi file JSON) ke database Console.
 *   php cli/import-store.php <folder-data>            impor (aman diulang: data yang sudah ada diperbarui, bukan diduplikasi)
 *   php cli/import-store.php <folder-data> --dry-run  hanya hitung, tidak menulis apa pun
 *
 * File yang dibaca (yang tidak ada dilewati): subscription-clients.json, subscription-ledger.json,
 * subscription-manual-invoices.json, invoices.json, invoice-settings.json, orders.json
 *
 * Setiap client langganan lama = satu BISNIS + langganannya. Untuk awal, tiap bisnis mendapat client (grup) sendiri
 * bernama sama; pengelompokan (mis. Grup Narayana) dilakukan di Console setelah impor.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/apps/console/bootstrap.php';

$dir = rtrim((string)($argv[1] ?? ''), '/\\');
$dry = in_array('--dry-run', $argv, true);
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Pakai: php cli/import-store.php <folder-data> [--dry-run]\n");
    exit(1);
}

$db = Auth::db();
$json = function (string $file) use ($dir): ?array {
    $p = "{$dir}/{$file}";
    if (!is_file($p)) {
        echo "  - {$file}: tidak ada, dilewati\n";
        return null;
    }
    $d = json_decode((string)file_get_contents($p), true);
    if (!is_array($d)) {
        throw new RuntimeException("{$file} bukan JSON yang valid");
    }
    return $d;
};
$date = fn ($v) => ($v && strtotime((string)$v)) ? date('Y-m-d', strtotime((string)$v)) : null;
$dt = fn ($v) => ($v && strtotime((string)$v)) ? date('Y-m-d H:i:s', strtotime((string)$v)) : null;
$slugify = function (string $s): string {
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    return substr($s !== '' ? $s : 'bisnis', 0, 60);
};
$count = ['bisnis' => 0, 'tagihan' => 0, 'invoice' => 0, 'order' => 0];

$db->beginTransaction();
try {
    // ── 1. Client langganan → client + bisnis + langganan ─────────────────
    $bizByKey = [];
    foreach ($json('subscription-clients.json') ?? [] as $c) {
        $key = trim((string)($c['client_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $name = trim((string)($c['client_name'] ?? '')) ?: $key;
        $st = $db->prepare('SELECT business_id FROM subscriptions WHERE client_key = ?');
        $st->execute([$key]);
        $bizId = $st->fetchColumn();
        if (!$bizId) {
            $db->prepare('INSERT INTO clients (name, email) VALUES (?, ?)')->execute([$name, ($c['notify_email'] ?? '') ?: null]);
            $clientId = (int)$db->lastInsertId();
            $slug = $slugify($key);
            for ($i = 2; $db->query('SELECT 1 FROM businesses WHERE slug = ' . $db->quote($slug))->fetchColumn(); $i++) {
                $slug = $slugify($key) . '-' . $i;
            }
            $db->prepare('INSERT INTO businesses (client_id, slug, name) VALUES (?, ?, ?)')->execute([$clientId, $slug, $name]);
            $bizId = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT INTO subscriptions (business_id, client_key, client_token, display_name, base_fee, per_guest_fee, start_date, due_date_override, locked, locked_at, pakasir_slug, pakasir_api_key, pakasir_webhook_secret, notify_email)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE client_token = VALUES(client_token), display_name = VALUES(display_name), base_fee = VALUES(base_fee), per_guest_fee = VALUES(per_guest_fee),
                start_date = VALUES(start_date), due_date_override = VALUES(due_date_override), locked = VALUES(locked), locked_at = VALUES(locked_at),
                pakasir_slug = VALUES(pakasir_slug), pakasir_api_key = VALUES(pakasir_api_key), pakasir_webhook_secret = VALUES(pakasir_webhook_secret), notify_email = VALUES(notify_email)')
            ->execute([
                $bizId, $key, (string)($c['client_token'] ?? ''), $name, (float)($c['base_fee'] ?? 0), (float)($c['per_guest_fee'] ?? 0),
                $date($c['subscription_start_date'] ?? null), $date($c['due_date_override'] ?? null), empty($c['locked']) ? 0 : 1, $dt($c['locked_at'] ?? null),
                ($c['pakasir_slug'] ?? '') ?: null, ($c['pakasir_api_key'] ?? '') ?: null, ($c['pakasir_webhook_secret'] ?? '') ?: null, ($c['notify_email'] ?? '') ?: null,
            ]);
        $bizByKey[$key] = (int)$bizId;
        $count['bisnis']++;
    }
    $bizFor = function (string $key) use (&$bizByKey, $db): ?int {
        if (isset($bizByKey[$key])) {
            return $bizByKey[$key];
        }
        $st = $db->prepare('SELECT business_id FROM subscriptions WHERE client_key = ?');
        $st->execute([$key]);
        $id = $st->fetchColumn();
        return $id ? ($bizByKey[$key] = (int)$id) : null;
    };

    // ── 2. Buku tagihan langganan ─────────────────────────────────────────
    $ledger = $json('subscription-ledger.json');
    if ($ledger !== null) {
        $ins = $db->prepare('INSERT INTO subscription_charges (legacy_id, business_id, kind, ref, period, base_fee, guest_count, carry_guests, carry_from, per_guest_fee, adjustment, note, status, due_date, paid_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE legacy_id = VALUES(legacy_id), kind = VALUES(kind), base_fee = VALUES(base_fee), guest_count = VALUES(guest_count), carry_guests = VALUES(carry_guests),
                carry_from = VALUES(carry_from), per_guest_fee = VALUES(per_guest_fee), adjustment = VALUES(adjustment), note = VALUES(note), status = VALUES(status), due_date = VALUES(due_date), paid_at = VALUES(paid_at)');
        foreach ($ledger['invoices'] ?? [] as $l) {
            $biz = $bizFor((string)($l['client_key'] ?? ''));
            if (!$biz) {
                echo "  ! tagihan {$l['id']}: client_key '{$l['client_key']}' tidak dikenal, dilewati\n";
                continue;
            }
            $period = (string)($l['period'] ?? '');
            $ins->execute([
                (string)($l['id'] ?? ''), $biz, ($l['kind'] ?? '') === 'topup' ? 'topup' : 'recurring', (string)($l['ref'] ?? $period), substr($period, 0, 7),
                (float)($l['base_fee'] ?? 0), (int)($l['guest_count'] ?? 0), (int)($l['carry_guests'] ?? 0), ($l['carry_from'] ?? '') ?: null,
                (float)($l['per_guest_fee'] ?? 0), (float)($l['adjustment'] ?? 0), ($l['note'] ?? '') ?: null,
                in_array($l['status'] ?? '', ['paid', 'cancelled'], true) ? $l['status'] : 'unpaid', $date($l['due_date'] ?? null), $dt($l['paid_at'] ?? null), $dt($l['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
            ]);
            $count['tagihan']++;
        }
        // Data pemakaian tamu & meta disimpan utuh agar perhitungan carry / top-up tetap bisa ditelusuri
        $db->prepare('INSERT INTO console_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['legacy_ledger_usage_meta', json_encode(['usage' => $ledger['usage'] ?? [], 'meta' => $ledger['meta'] ?? []], JSON_UNESCAPED_UNICODE)]);
    }

    // ── 3. Pengaturan invoice ────────────────────────────────────────────
    if (($settings = $json('invoice-settings.json')) !== null) {
        $db->prepare('INSERT INTO console_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['invoice_settings', json_encode($settings, JSON_UNESCAPED_UNICODE)]);
    }

    // ── 4. Invoice bebas + invoice manual langganan ─────────────────────
    $saveInvoice = function (array $h, array $items) use ($db, &$count): void {
        $h += ['business_id' => null, 'customer_company' => null, 'customer_email' => null, 'customer_phone' => null, 'customer_address' => null,
            'discount_type' => 'rp', 'discount_value' => 0, 'tax_percent' => 0, 'notes' => null, 'paid_at' => null];
        $st = $db->prepare('SELECT id FROM invoices WHERE legacy_id = ? OR number = ? LIMIT 1');
        $st->execute([$h['legacy_id'], $h['number']]);
        $id = $st->fetchColumn();
        $cols = ['legacy_id', 'number', 'token', 'kind', 'business_id', 'customer_name', 'customer_company', 'customer_email', 'customer_phone', 'customer_address',
            'issue_date', 'due_date', 'discount_type', 'discount_value', 'tax_percent', 'notes', 'status', 'paid_at', 'created_at'];
        $vals = array_map(fn ($c) => $h[$c] ?? null, $cols);
        if ($id) {
            $db->prepare('UPDATE invoices SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO invoices (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')')->execute($vals);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO invoice_items (invoice_id, description, note, qty, unit_price, sort) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (array_values($items) as $i => $it) {
            $ins->execute([$id, mb_substr((string)$it['desc'], 0, 240), ($it['note'] ?? '') ?: null, (float)($it['qty'] ?? 1), (float)($it['unit_price'] ?? 0), $i]);
        }
        $count['invoice']++;
    };
    foreach ($json('invoices.json') ?? [] as $inv) {
        $cu = $inv['customer'] ?? [];
        $saveInvoice([
            'legacy_id' => (string)($inv['id'] ?? ''), 'number' => (string)($inv['number'] ?? $inv['id']), 'token' => substr((string)($inv['token'] ?? bin2hex(random_bytes(12))), 0, 24),
            'kind' => 'free', 'customer_name' => (string)($cu['name'] ?? '-'), 'customer_company' => ($cu['company'] ?? '') ?: null, 'customer_email' => ($cu['email'] ?? '') ?: null,
            'customer_phone' => ($cu['phone'] ?? '') ?: null, 'customer_address' => ($cu['address'] ?? '') ?: null,
            'issue_date' => $date($inv['issue_date'] ?? null) ?? date('Y-m-d'), 'due_date' => $date($inv['due_date'] ?? null) ?? date('Y-m-d'),
            'discount_type' => ($inv['discount_type'] ?? 'rp') === 'percent' ? 'percent' : 'rp', 'discount_value' => (float)($inv['discount_value'] ?? 0), 'tax_percent' => (float)($inv['tax_percent'] ?? 0),
            'notes' => ($inv['notes'] ?? '') ?: null, 'status' => in_array($inv['status'] ?? '', ['draft', 'unpaid', 'paid', 'cancelled'], true) ? $inv['status'] : 'unpaid',
            'paid_at' => $dt($inv['paid_at'] ?? null), 'created_at' => $dt($inv['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ], $inv['items'] ?? []);
    }
    foreach ($json('subscription-manual-invoices.json') ?? [] as $m) {
        $biz = $bizFor((string)($m['client_key'] ?? ''));
        $legacy = (string)($m['id'] ?? '');
        $name = $biz ? (string)$db->query('SELECT name FROM businesses WHERE id = ' . (int)$biz)->fetchColumn() : (string)($m['client_key'] ?? '-');
        $paid = !empty($m['paid_at']) || ($m['status'] ?? '') === 'paid';
        $saveInvoice([
            'legacy_id' => $legacy, 'number' => 'MAN-' . substr(preg_replace('/[^a-z0-9]/i', '', $legacy), -10), 'token' => bin2hex(random_bytes(12)), 'kind' => 'manual',
            'business_id' => $biz, 'customer_name' => $name, 'issue_date' => $date($m['created_at'] ?? null) ?? date('Y-m-d'), 'due_date' => $date($m['due_date'] ?? null) ?? date('Y-m-d'),
            'status' => $paid ? 'paid' : 'unpaid', 'paid_at' => $dt($m['paid_at'] ?? null), 'created_at' => $dt($m['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ], [['desc' => (string)($m['description'] ?? 'Tagihan manual'), 'qty' => 1, 'unit_price' => (float)($m['amount'] ?? 0)]]);
    }

    // ── 5. Order ─────────────────────────────────────────────────────────
    $ord = $db->prepare('INSERT INTO orders (order_id, txn_id, product_title, amount, name, email, whatsapp, status, source, payment_method, business_id, created_at, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE txn_id = VALUES(txn_id), status = VALUES(status), payment_method = VALUES(payment_method), completed_at = VALUES(completed_at)');
    foreach ($json('orders.json') ?? [] as $o) {
        if (empty($o['order_id'])) {
            continue;
        }
        $ord->execute([
            (string)$o['order_id'], ($o['txn_id'] ?? '') ?: null, $o['product_title'] ?? null, (float)($o['amount'] ?? 0), $o['name'] ?? null, ($o['email'] ?? '') ?: null,
            ($o['whatsapp'] ?? '') ?: null, (string)($o['status'] ?? 'pending'), $o['source'] ?? null, ($o['payment_method'] ?? '') ?: null,
            isset($o['client_key']) ? $bizFor((string)$o['client_key']) : null, $dt($o['created_at'] ?? null) ?? date('Y-m-d H:i:s'), $dt($o['completed_at'] ?? null),
        ]);
        $count['order']++;
    }

    if ($dry) {
        $db->rollBack();
        echo "\n[DRY-RUN] tidak ada yang disimpan.\n";
    } else {
        $db->commit();
    }
    printf("\nBisnis+langganan: %d · Tagihan langganan: %d · Invoice: %d · Order: %d\n", $count['bisnis'], $count['tagihan'], $count['invoice'], $count['order']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'GAGAL (tidak ada yang tersimpan): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

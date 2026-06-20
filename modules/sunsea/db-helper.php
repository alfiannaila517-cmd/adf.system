<?php
/**
 * Sunsea Module - Database Helper
 * Provides PDO connection to adf_sunsea database
 * 
 * Designed to be portable - if Sunsea moves to its own hosting,
 * only the connection credentials here need to change.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Get a PDO connection to the Sunsea database.
 * Respects local vs. production naming convention automatically.
 *
 * @return PDO
 * @throws Exception on connection failure
 */
function getSunseaConnection(): PDO {
    // Use the central Database class (it reads ACTIVE_BUSINESS_ID = 'sunsea' and picks adf_sunsea)
    if (class_exists('Database')) {
        return Database::getInstance()->getConnection();
    }

    // Standalone fallback (if ever hosted separately)
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
                     strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

    if ($isProduction) {
        $host = 'localhost';
        $db   = 'adfb2574_sunsea';
        $user = 'adfb2574_adfsystem';
        $pass = '@Nnoc2025';
    } else {
        $host = 'localhost';
        $db   = 'adf_sunsea';
        $user = 'root';
        $pass = '';
    }

    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}

/**
 * Get next auto-number for quotation / invoice.
 * Format: SS-QUO-2026-001
 *
 * @param PDO    $pdo
 * @param string $type  'quotation' | 'invoice'
 * @return string
 */
function sunseaNextNumber(PDO $pdo, string $type): string {
    $prefixMap = [
        'quotation' => 'SS-QUO',
        'invoice'   => 'SS-INV',
    ];
    $prefix = $prefixMap[$type] ?? 'SS-DOC';
    $year   = date('Y');

    // Reset counter when year changes
    $pdo->prepare(
        "INSERT INTO sequences (seq_name, last_value, year) VALUES (?, 0, ?)
         ON DUPLICATE KEY UPDATE 
           last_value = IF(year < VALUES(year), 0, last_value),
           year       = IF(year < VALUES(year), VALUES(year), year)"
    )->execute([$type, $year]);

    $pdo->prepare("UPDATE sequences SET last_value = last_value + 1, year = ? WHERE seq_name = ?")
        ->execute([$year, $type]);

    $row = $pdo->prepare("SELECT last_value FROM sequences WHERE seq_name = ?");
    $row->execute([$type]);
    $num = (int)$row->fetchColumn();

    return $prefix . '-' . $year . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

/**
 * Format Rupiah
 */
function sunseaRupiah(float $amount, bool $short = false): string {
    if ($short && $amount >= 1_000_000) {
        return 'Rp ' . number_format($amount / 1_000_000, 1) . ' jt';
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Get Sunsea setting value from settings table.
 */
function sunseaSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== '') ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

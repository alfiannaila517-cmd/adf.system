<?php

declare(strict_types=1);

/**
 * Koneksi database platform. Kredensial dari secrets/db.env (DB_HOST, DB_USER, DB_PASS) — tidak pernah dari git.
 * Semua database platform berawalan adf_ (user adf_app hanya punya hak pada adf_%).
 *
 *   $pdo = Db::connect('adf_console');
 */
final class Db
{
    /** @var array<string, PDO> */
    private static array $pool = [];

    public static function connect(?string $database = null): PDO
    {
        $key = $database ?? '';
        if (isset(self::$pool[$key])) {
            return self::$pool[$key];
        }
        if ($database !== null) {
            self::assertName($database);
        }
        $dsn = 'mysql:host=' . Secrets::get('DB_HOST', 'localhost') . ';charset=utf8mb4' . ($database ? ";dbname={$database}" : '');
        $pdo = new PDO($dsn, Secrets::require('DB_USER'), Secrets::require('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Jam database mengikuti WIB, sama dengan PHP
        $pdo->exec("SET time_zone = '+07:00'");
        // Server MariaDB disamakan dengan hosting lama (tidak ketat) demi sistem lama; platform baru tetap ketat di sesinya sendiri.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
        return self::$pool[$key] = $pdo;
    }

    /**
     * Database master sistem lama (adf_system di hosting = adfb2574_adf), nama dari secrets/legacy.env LEGACY_MASTER_DB.
     * Satu-satunya pengecualian aturan awalan adf_; dipakai jembatan Console ↔ sistem lama. null = belum diatur.
     * Sesi memakai sql_mode server (tidak ketat) supaya menulis persis seperti sistem lama.
     */
    public static function legacyMaster(): ?PDO
    {
        $name = (string)Secrets::get('LEGACY_MASTER_DB', '');
        if ($name === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $name)) {
            throw new InvalidArgumentException("LEGACY_MASTER_DB tidak valid: {$name}");
        }
        if (isset(self::$pool['legacy:' . $name])) {
            return self::$pool['legacy:' . $name];
        }
        // Pengaman: jembatan hanya boleh ke database di server ini sendiri — tidak pernah ke hosting / server lain
        $host = strtolower((string)Secrets::get('DB_HOST', 'localhost'));
        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException("Jembatan sistem lama hanya boleh ke database lokal (DB_HOST={$host} ditolak)");
        }
        $pdo = new PDO('mysql:host=' . Secrets::get('DB_HOST', 'localhost') . ";charset=utf8mb4;dbname={$name}", Secrets::require('DB_USER'), Secrets::require('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
        return self::$pool['legacy:' . $name] = $pdo;
    }

    /** Buat database bila belum ada (utf8mb4). */
    public static function ensureDatabase(string $name): void
    {
        self::assertName($name);
        self::connect()->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private static function assertName(string $name): void
    {
        if (!preg_match('/^adf_[a-z0-9_]{2,60}$/', $name)) {
            throw new InvalidArgumentException("Nama database tidak valid: {$name}");
        }
    }
}

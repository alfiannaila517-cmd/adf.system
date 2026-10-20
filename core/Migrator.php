<?php

declare(strict_types=1);

/**
 * Menjalankan file migrasi SQL berurutan (001_xxx.sql, 002_xxx.sql, …) sekali saja per database.
 * Yang sudah dijalankan dicatat di tabel _migrations, jadi aman dijalankan berulang (mis. setiap deploy).
 * Satu file boleh berisi beberapa perintah dipisah titik koma di akhir baris.
 */
final class Migrator
{
    /** @return string[] nama file yang baru dijalankan */
    public static function run(string $database, string $dir): array
    {
        Db::ensureDatabase($database);
        $pdo = Db::connect($database);
        $pdo->exec('CREATE TABLE IF NOT EXISTS _migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $done = array_flip($pdo->query('SELECT name FROM _migrations')->fetchAll(PDO::FETCH_COLUMN));

        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($done[$name])) {
                continue;
            }
            foreach (self::statements((string)file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->prepare('INSERT INTO _migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Pisahkan isi file menjadi perintah-perintah (titik koma di akhir baris; komentar -- diabaikan). */
    private static function statements(string $sql): array
    {
        $lines = array_filter(preg_split('/\R/', $sql), fn ($l) => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*$/m', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}

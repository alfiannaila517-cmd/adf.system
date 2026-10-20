<?php

declare(strict_types=1);

/**
 * Titik kustomisasi per bisnis tanpa mengubah kode inti.
 *
 * Kode inti menyediakan titik:
 *   $dp = Hooks::filter('booking.dp_amount', $total * 0.5, $booking);   // nilai bisa diubah tenant
 *   Hooks::action('booking.created', $booking);                          // tenant bisa ikut bereaksi
 *
 * tenants/<bisnis>/hooks.php mendaftarkan perilaku khususnya:
 *   Hooks::on('booking.dp_amount', fn (float $dp, array $b) => $b['final_price'] * 0.3);
 *
 * Aturan: kode inti tidak boleh berisi nama bisnis — perbedaan per bisnis masuk ke fitur on/off (tenant.php) atau hook.
 */
final class Hooks
{
    /** @var array<string, array<int, array{0:int,1:int,2:callable}>> nama → [prioritas, urutan, fungsi] */
    private static array $listeners = [];
    private static int $seq = 0;

    /** Daftarkan fungsi pada sebuah titik. Prioritas kecil dijalankan lebih dulu. */
    public static function on(string $name, callable $callback, int $priority = 10): void
    {
        self::$listeners[$name][] = [$priority, self::$seq++, $callback];
    }

    /** Lewatkan $value melalui semua fungsi terdaftar; tiap fungsi menerima nilai terakhir + argumen tambahan. */
    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::sorted($name) as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }

    /** Jalankan semua fungsi terdaftar (tanpa nilai kembali). */
    public static function action(string $name, mixed ...$args): void
    {
        foreach (self::sorted($name) as $callback) {
            $callback(...$args);
        }
    }

    public static function has(string $name): bool
    {
        return !empty(self::$listeners[$name]);
    }

    /** Untuk pengujian: hapus semua pendaftaran. */
    public static function reset(): void
    {
        self::$listeners = [];
        self::$seq = 0;
    }

    /** @return callable[] */
    private static function sorted(string $name): array
    {
        $list = self::$listeners[$name] ?? [];
        usort($list, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($list, 2);
    }
}

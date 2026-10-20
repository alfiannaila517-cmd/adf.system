<?php

declare(strict_types=1);

/**
 * Satu bisnis (tenant) = satu folder tenants/<slug>/ berisi:
 *   tenant.php   konfigurasi: nama, jenis, database, modul, fitur on/off, tema, domain
 *   hooks.php    (opsional) logika khusus bisnis ini — hanya dimuat saat bisnis ini aktif
 *   modules/     (opsional) halaman yang hanya dimiliki bisnis ini
 * Folder berawalan "_" (mis. _template-hotel) adalah cetakan, bukan bisnis aktif.
 */
final class Tenant
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]{1,48}$/';
    private const REQUIRED = ['slug', 'name', 'type', 'database'];

    private static ?Tenant $current = null;
    /** @var array<string, Tenant> */
    private static array $cache = [];

    /** @param array<string,mixed> $config */
    private function __construct(public readonly string $slug, private array $config)
    {
    }

    /** Muat konfigurasi satu bisnis. */
    public static function load(string $slug): self
    {
        if (isset(self::$cache[$slug])) {
            return self::$cache[$slug];
        }
        if (!preg_match(self::SLUG, $slug)) {
            throw new InvalidArgumentException("Slug tenant tidak valid: {$slug}");
        }
        $file = ADF_TENANTS . "/{$slug}/tenant.php";
        if (!is_file($file)) {
            throw new RuntimeException("Tenant {$slug} tidak ditemukan");
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException("tenants/{$slug}/tenant.php harus mengembalikan array");
        }
        foreach (self::REQUIRED as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("tenants/{$slug}/tenant.php: '{$key}' wajib diisi");
            }
        }
        if ($config['slug'] !== $slug) {
            throw new RuntimeException("tenants/{$slug}/tenant.php: slug di dalam file ({$config['slug']}) tidak sama dengan nama folder");
        }
        return self::$cache[$slug] = new self($slug, $config);
    }

    /** Semua bisnis aktif (tanpa cetakan berawalan "_"), urut nama folder. @return Tenant[] */
    public static function all(): array
    {
        $out = [];
        foreach (glob(ADF_TENANTS . '/*/tenant.php') ?: [] as $file) {
            $slug = basename(dirname($file));
            if ($slug[0] !== '_') {
                $out[] = self::load($slug);
            }
        }
        return $out;
    }

    /** Jadikan bisnis ini yang sedang dipakai dan muat hook khususnya (sekali saja). */
    public static function activate(string $slug): self
    {
        $tenant = self::load($slug);
        if (self::$current?->slug !== $slug) {
            self::$current = $tenant;
            $hooks = ADF_TENANTS . "/{$slug}/hooks.php";
            if (is_file($hooks)) {
                require_once $hooks;
            }
        }
        return $tenant;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function name(): string
    {
        return (string)$this->config['name'];
    }

    public function type(): string
    {
        return (string)$this->config['type'];
    }

    public function database(): string
    {
        return (string)$this->config['database'];
    }

    /** Nilai konfigurasi dengan notasi titik, mis. get('theme.color_primary'). */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Fitur on/off dari tenant.php ('features' => ['cloudbeds' => true, …]). Default mati. */
    public function feature(string $name): bool
    {
        return (bool)($this->config['features'][$name] ?? false);
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, (array)($this->config['modules'] ?? []), true);
    }

    /** Folder file milik bisnis ini (uploads, log, backup) di luar git; dibuat bila belum ada. */
    public function storagePath(string $sub = ''): string
    {
        $path = ADF_STORAGE . '/' . $this->slug . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        return $path;
    }
}

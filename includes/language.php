<?php
/**
 * Language Loader - ID / EN
 * Bahasa aktif = pilihan user (session user_language, diisi dari user_preferences.language).
 * Teks yang belum diterjemahkan jatuh ke bahasa Indonesia (dasar), lalu ke kuncinya.
 */

/** Bahasa yang didukung. Tambah bahasa baru: buat includes/languages/<kode>.php lalu daftarkan di sini. */
function langSupported(): array
{
    return [
        'id' => ['name' => 'Bahasa Indonesia', 'short' => 'ID', 'flag' => '🇮🇩', 'locale' => 'id_ID'],
        'en' => ['name' => 'English', 'short' => 'EN', 'flag' => '🇬🇧', 'locale' => 'en_US'],
    ];
}

$userLang = $_SESSION['user_language'] ?? 'id';
if (!isset(langSupported()[$userLang])) {
    $userLang = 'id';
}

$lang = require __DIR__ . '/languages/id.php';
if ($userLang !== 'id') {
    $langFile = __DIR__ . '/languages/' . $userLang . '.php';
    if (is_file($langFile)) {
        $lang = array_replace_recursive($lang, require $langFile);
    }
}

/**
 * Translation function
 * @param string $key Translation key (mis. 'nav.hotel_services')
 * @param array $replace Pengganti placeholder :nama
 */
function __($key, $replace = []) {
    global $lang;

    $value = $lang;
    foreach (explode('.', $key) as $k) {
        if (is_array($value) && isset($value[$k])) {
            $value = $value[$k];
        } else {
            return $key; // kunci belum ada
        }
    }
    if (!is_string($value)) {
        return $key;
    }
    foreach ($replace as $search => $replacement) {
        $value = str_replace(':' . $search, (string)$replacement, $value);
    }
    return $value;
}

/** Kode bahasa aktif (id / en). */
function getCurrentLanguage() {
    $l = $_SESSION['user_language'] ?? 'id';
    return isset(langSupported()[$l]) ? $l : 'id';
}

/**
 * Format tanggal mengikuti bahasa aktif. Sama dengan date(), tetapi nama bulan/hari
 * (M, F, D, l) memakai bahasa yang dipilih. Contoh: langDate('2026-10-11', 'd M Y') -> "11 Okt 2026" / "11 Oct 2026".
 */
function langDate($time, string $format = 'd M Y'): string {
    $ts = is_numeric($time) ? (int)$time : strtotime((string)$time);
    if (!$ts) {
        return '';
    }
    $months = $GLOBALS['lang']['date']['months'] ?? [];
    $monthsShort = $GLOBALS['lang']['date']['months_short'] ?? [];
    $days = $GLOBALS['lang']['date']['days'] ?? [];
    $daysShort = $GLOBALS['lang']['date']['days_short'] ?? [];
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $out .= $format[++$i];
            continue;
        }
        switch ($c) {
            case 'F': $out .= $months[(int)date('n', $ts) - 1] ?? date('F', $ts); break;
            case 'M': $out .= $monthsShort[(int)date('n', $ts) - 1] ?? date('M', $ts); break;
            case 'l': $out .= $days[(int)date('w', $ts)] ?? date('l', $ts); break;
            case 'D': $out .= $daysShort[(int)date('w', $ts)] ?? date('D', $ts); break;
            default: $out .= date($c, $ts);
        }
    }
    return $out;
}

/** Subset kamus untuk JavaScript: window.I18N = <?php echo langJs(['user_menu.save_failed']); ?> */
function langJs(array $keys): string {
    $map = [];
    foreach ($keys as $k) {
        $map[$k] = __($k);
    }
    return json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}
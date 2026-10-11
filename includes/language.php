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

/* ========================================================================
 * Lapisan penerjemah halaman (output filter)
 * Saat bahasa bukan Indonesia, HTML yang dihasilkan halaman diterjemahkan lewat kamus frasa
 * includes/languages/phrases.<kode>.php. Hanya TEKS TAMPILAN yang disentuh:
 *   - teks di antara tag (>...<) dan atribut title / placeholder / aria-label / alt
 *   - literal string tertentu di <script> (daftar 'js' di kamus)
 * Nilai atribut value/name/id/href dan kode lainnya tidak diubah, jadi logika form & skrip aman.
 * ======================================================================== */

/** Kamus frasa untuk bahasa tertentu (diindeks & di-cache). */
function langPhraseMap(string $code): array
{
    static $cache = [];
    if (isset($cache[$code])) {
        return $cache[$code];
    }
    // Kamus dasar + semua berkas modul languages/phrases/<kode>/*.php (digabung; yang belakangan menimpa)
    $files = [__DIR__ . '/languages/phrases.' . $code . '.php'];
    foreach (glob(__DIR__ . '/languages/phrases/' . $code . '/*.php') ?: [] as $extra) {
        $files[] = $extra;
    }
    $map = ['text' => [], 'js' => [], 'patterns' => [], 'jsraw' => []];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $part = require $file;
        $map['text'] = array_merge($map['text'], $part['text'] ?? []);
        $map['js'] = array_merge($map['js'], $part['js'] ?? []);
        $map['patterns'] = array_merge($map['patterns'], $part['patterns'] ?? []);
        $map['jsraw'] = array_merge($map['jsraw'] ?? [], $part['jsraw'] ?? []);
    }

    // indeks teks: kunci dinormalisasi (spasi dirapatkan)
    $text = [];
    foreach ($map['text'] as $k => $v) {
        $text[langNorm((string)$k)] = (string)$v;
    }
    // pasangan literal JS: setiap frasa dicoba dengan/tanpa spasi pinggir & kedua jenis kutip
    $jsPairs = [];
    foreach ($map['js'] as $k => $v) {
        $k = (string)$k;
        $v = (string)$v;
        foreach (['', ' '] as $lead) {
            foreach (['', ' '] as $trail) {
                $jsPairs["'" . $lead . $k . $trail . "'"] = "'" . $lead . str_replace("'", "\\'", $v) . $trail . "'";
                $jsPairs['"' . $lead . $k . $trail . '"'] = '"' . $lead . str_replace('"', '\\"', $v) . $trail . '"';
            }
        }
    }
    // fragmen JS mentah (mis. bagian template literal berisi ${...}) diganti apa adanya, tanpa bungkus kutip
    foreach ($map['jsraw'] as $k => $v) {
        $jsPairs[(string)$k] = (string)$v;
    }
    return $cache[$code] = ['text' => $text, 'js' => $jsPairs, 'patterns' => $map['patterns']];
}

/** Normalisasi kunci/teks: spasi dirapatkan, penanda varian emoji dibuang. */
function langNorm(string $s): string
{
    $s = preg_replace('/[\x{FE0E}\x{FE0F}\x{200D}]/u', '', $s);
    return preg_replace('/\s+/u', ' ', trim($s));
}

/**
 * Terjemahkan satu potong teks (sudah di-decode). Urutan: kecocokan persis -> tanpa simbol/tanda di awal & akhir
 * (mis. "✅ Berhasil!", "Divisi *", "Tanggal:") -> pola regex. Return null bila tidak ada padanan.
 */
function langTranslateText(string $s, array $map): ?string
{
    $key = langNorm($s);
    if ($key === '') {
        return null;
    }
    if (isset($map['text'][$key])) {
        return $map['text'][$key];
    }
    // awalan (emoji, simbol, "+", "←") dan akhiran (":", "*", "→", "...") dipisahkan
    $raw = trim(preg_replace('/\s+/u', ' ', $s)); // awalan asli (termasuk penanda emoji) dipertahankan
    if (preg_match('/^((?:[^\p{L}\p{N}]|[\x{2100}-\x{214F}])*)(.*?)([\s:*→…\.!?]*)$/us', $raw, $m) && $m[2] !== '' && ($m[1] !== '' || $m[3] !== '')) {
        $withTail = langNorm($m[2] . $m[3]); // kunci yang memang berakhiran titik/tanda dicoba dulu
        if ($m[1] !== '' && isset($map['text'][$withTail])) {
            return $m[1] . $map['text'][$withTail];
        }
        $m[2] = langNorm($m[2]);
        if (isset($map['text'][$m[2]])) {
            return $m[1] . $map['text'][$m[2]] . $m[3];
        }
    }
    if ($map['patterns'] && preg_match('/[\d%]|\p{L}{3}/u', $key)) {
        $out = $key;
        foreach ($map['patterns'] as $re => $rep) {
            $out = preg_replace($re, $rep, $out);
        }
        if ($out !== $key) {
            return $out;
        }
    }
    return null;
}

/** Callback ob_start: terjemahkan seluruh keluaran HTML halaman. */
function langTranslateOutput(string $html): string
{
    $code = getCurrentLanguage();
    if ($code === 'id' || $html === '' || stripos($html, '<html') === false) {
        return $html; // bahasa dasar, atau bukan halaman HTML penuh (JSON/potongan)
    }
    $map = langPhraseMap($code);
    if (!$map['text'] && !$map['js']) {
        return $html;
    }

    $parts = preg_split('~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            // blok <script>/<style>: hanya <script> inline biasa yang diterjemahkan literalnya
            if (stripos($part, '<script') === 0 && !preg_match('~^<script\b[^>]*(src=|type="(?:application/(?:ld\+)?json|text/template)")~i', $part)) {
                if ($map['js']) {
                    $part = strtr($part, $map['js']);
                }
                // Potongan HTML di dalam literal JS (mis. '<span>Total baru</span>'): teks di antara tag ikut diterjemahkan.
                // Hanya yang persis ada di kamus teks, jadi ekspresi seperti 'a>b&&c<d' tidak tersentuh.
                $part = preg_replace_callback('~>([^<>\n\\\\\'"`;{}]+)<~', function ($m) use ($map) {
                    $tr = $map['text'][langNorm($m[1])] ?? null;
                    return $tr === null ? $m[0] : '>' . str_replace("'", "\\'", $tr) . '<';
                }, $part);
                $parts[$i] = $part;
            }
            continue;
        }
        // teks di antara tag
        $part = preg_replace_callback('~>([^<>]+)<~', function ($m) use ($map) {
            $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = langTranslateText($decoded, $map);
            if ($tr === null) {
                return $m[0];
            }
            // pertahankan spasi pinggir asli
            preg_match('/^(\s*).*?(\s*)$/su', $m[1], $ws);
            return '>' . ($ws[1] ?? '') . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . ($ws[2] ?? '') . '<';
        }, $part);
        // atribut tampilan
        $part = preg_replace_callback('~\b(title|placeholder|aria-label|alt)="([^"]*)"~', function ($m) use ($map) {
            $decoded = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = langTranslateText($decoded, $map);
            return $tr === null ? $m[0] : $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
        }, $part);
        $parts[$i] = $part;
    }
    return implode('', $parts);
}
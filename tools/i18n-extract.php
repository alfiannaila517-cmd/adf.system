<?php
/**
 * Ekstrak teks tampilan (kandidat terjemahan) dari berkas template PHP.
 *
 * Pemakaian:  php tools/i18n-extract.php index.php includes/frontdesk_today.php modules/frontdesk/settings.php
 * Keluaran:   daftar frasa unik (satu per baris) beserta jumlah kemunculannya, untuk diterjemahkan
 *             lalu dimasukkan ke includes/languages/phrases.en.php.
 *
 * Yang diambil: teks di antara tag HTML, atribut title/placeholder/aria-label/alt, dan literal string
 * pendek di dalam <script> yang tampak seperti kalimat. Kode PHP dibuang lebih dulu.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // hanya untuk baris perintah
$root = dirname(__DIR__);
$files = array_slice($argv, 1);
$missing = null; // --missing=en : tampilkan hanya frasa yang belum ada di kamus bahasa itu
foreach ($files as $i => $a) {
    if (strpos($a, '--missing=') === 0) {
        $missing = substr($a, 10);
        unset($files[$i]);
    }
}
if ($missing !== null) {
    $_SESSION = [];
    require_once $root . '/includes/language.php';
    $known = langPhraseMap($missing);
}
if (!$files) {
    fwrite(STDERR, "Usage: php tools/i18n-extract.php <file> [file...]\n");
    exit(1);
}

$found = [];
$add = function (string $s, string $kind) use (&$found) {
    $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 140) return;
    if (!preg_match('/\p{L}{2,}/u', $s)) return;                  // harus ada huruf
    if (preg_match('/^[\w.\/\-#:]+\.(php|js|css|png|jpg|svg)$/i', $s)) return; // nama berkas
    if (preg_match('/^(https?:|\/|\$|#|\.|function|var |let |const |return|if\s*\()/', $s)) return;
    if (preg_match('/[{};=]{2,}|=>|\$\w+|\(\)|\bfunction\b/', $s)) return;    // sisa kode
    $found[$kind][$s] = ($found[$kind][$s] ?? 0) + 1;
};

foreach ($files as $rel) {
    $path = is_file($rel) ? $rel : $root . '/' . $rel;
    if (!is_file($path)) { fwrite(STDERR, "skip (not found): $rel\n"); continue; }
    $src = file_get_contents($path);
    $src = preg_replace('/<\?php.*?(\?>|\z)/s', "\x01", $src);   // buang kode PHP (penanda \x01)
    $src = preg_replace('/<!--.*?-->/s', '', $src);

    // blok <script>
    $scripts = [];
    $html = preg_replace_callback('~<script\b[^>]*>(.*?)</script>~is', function ($m) use (&$scripts) {
        $scripts[] = $m[1];
        return '';
    }, $src);
    $html = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html);

    foreach ($scripts as $js) {
        $js = preg_replace('~//[^\n]*~', '', $js);
        preg_match_all('/(["\'])((?:(?!\1)[^\\\\\n]|\\\\.){3,140})\1/u', $js, $mm);
        foreach ($mm[2] as $lit) {
            if (preg_match('/\s/u', $lit) || preg_match('/^[A-Z][a-z]+$/', $lit)) $add($lit, 'js');
        }
    }
    preg_match_all('/>([^<>]+)</', $html, $tn);
    foreach ($tn[1] as $t) $add(str_replace("\x01", '', $t), 'text');
    preg_match_all('/\b(?:title|placeholder|aria-label|alt)="([^"\x01]*)"/', $html, $at);
    foreach ($at[1] as $t) $add($t, 'attr');
}

foreach (['text', 'attr', 'js'] as $k) {
    if ($missing !== null && !empty($found[$k])) {
        foreach (array_keys($found[$k]) as $phrase) {
            $hit = ($k === 'js') ? isset($known['js']["'" . $phrase . "'"]) : (langTranslateText($phrase, $known) !== null);
            if ($hit) unset($found[$k][$phrase]);
        }
    }
    if (empty($found[$k])) continue;
    echo "=== $k (" . count($found[$k]) . ") ===\n";
    ksort($found[$k]);
    foreach ($found[$k] as $s => $n) echo ($n > 1 ? "[$n] " : '') . $s . "\n";
}

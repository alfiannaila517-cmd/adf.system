<?php
/**
 * Halaman lama integrasi Cloudbed (OAuth client id/secret + sinkron ke tabel `reservasi` yang tidak ada).
 * Diganti modules/frontdesk/cloudbeds.php (API key cbat_, tes koneksi & pemetaan kamar).
 */
require_once '../../config/config.php';
header('Location: ' . BASE_URL . '/modules/frontdesk/cloudbeds.php');
exit;

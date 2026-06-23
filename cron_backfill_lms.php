<?php
/**
 * Cron job: backfill lms_password di tabel konfirmasi_desa
 * Password = kode_desa dibalik (REVERSE)
 *
 * Cara pakai:
 * 1. Upload file ini ke root: brilian.jurnalsinta.id/cron_backfill_lms.php
 * 2. Set cron di cPanel (lihat instruksi di bawah file)
 *
 * Hanya bisa dijalankan jika token cocok (mencegah akses publik via URL).
 */

// =====================================================
// GANTI TOKEN INI DENGAN STRING ACAK PUNYA ANDA SENDIRI
// =====================================================
define('CRON_TOKEN', '1af74284ae0499d7f2e19db2ec19ff1e');

// Validasi token
$provided = $_GET['token'] ?? ($argv[1] ?? '');
if (!hash_equals(CRON_TOKEN, $provided)) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/config.php';

$started = date('Y-m-d H:i:s');

// Eksekusi backfill
$sql = "UPDATE konfirmasi_desa
        SET lms_password = REVERSE(kode_desa)
        WHERE lms_password IS NULL OR lms_password = ''";

if ($mysqli->query($sql)) {
    $affected = $mysqli->affected_rows;
    echo "[{$started}] OK - Backfill selesai. Rows updated: {$affected}\n";
} else {
    http_response_code(500);
    echo "[{$started}] ERROR - " . $mysqli->error . "\n";
}

$mysqli->close();

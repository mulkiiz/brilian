<?php
/**
 * Cron job: generate CSV user Moodle (Joglo) dari tabel konfirmasi_desa
 *
 * Output: /user_moodle/moodle_users_YYYYMMDD_HHMMSS.csv
 *
 * Format CSV (mengikuti template_moodle_brilian.csv):
 * - UTF-8 with BOM, line ending CRLF
 * - Header: username,password,firstname,lastname,email,city,country,department,institution,course1
 * - Mapping:
 *     username    = kode_desa
 *     password    = lms_password (kode_desa dibalik)
 *     firstname   = "Desa {nama_desa}"
 *     lastname    = "Kec. {kecamatan}"
 *     email       = email
 *     city        = "Kabupaten {kabupaten}"
 *     country     = ID
 *     department  = kecamatan (tanpa prefix)
 *     institution = provinsi
 *     course1     = brilian2026
 *
 * Hanya record yang sudah konfirmasi (dari tabel konfirmasi_desa) yang diekspor.
 */

// =====================================================
// GANTI TOKEN INI DENGAN STRING ACAK PUNYA ANDA SENDIRI
// =====================================================
define('CRON_TOKEN', 'f6ea04bc7b38ae541fd7cb5b2a33b173');

// Validasi token
$provided = $_GET['token'] ?? ($argv[1] ?? '');
if (!hash_equals(CRON_TOKEN, $provided)) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/config.php';

$started = date('Y-m-d H:i:s');

// Pastikan direktori output ada
$dir = __DIR__ . '/user_moodle';
if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
        http_response_code(500);
        exit("[{$started}] ERROR - Gagal membuat direktori user_moodle\n");
    }
    // Proteksi listing direktori
    @file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
    @file_put_contents($dir . '/index.html', '');
}

// Auto-fix lms_password yang masih NULL/kosong
$mysqli->query("UPDATE konfirmasi_desa
                SET lms_password = REVERSE(kode_desa)
                WHERE lms_password IS NULL OR lms_password = ''");

// Ambil data: konfirmasi_desa (data terkini hasil konfirmasi)
$sql = "SELECT kode_desa, lms_password, nama_desa, kecamatan, kabupaten, provinsi, email
        FROM konfirmasi_desa
        WHERE kode_desa IS NOT NULL AND kode_desa <> ''
        ORDER BY provinsi, kabupaten, kecamatan, nama_desa";

$result = $mysqli->query($sql);
if (!$result) {
    http_response_code(500);
    exit("[{$started}] ERROR - Query gagal: " . $mysqli->error . "\n");
}

// Buka file output
$ts       = date('Ymd_His');
$filename = "moodle_users_{$ts}.csv";
$filepath = $dir . '/' . $filename;

$fp = fopen($filepath, 'w');
if (!$fp) {
    http_response_code(500);
    exit("[{$started}] ERROR - Gagal membuat file {$filepath}\n");
}

// UTF-8 BOM (supaya Excel buka dengan benar)
fwrite($fp, "\xEF\xBB\xBF");

// Helper untuk tulis baris CSV dengan CRLF
$writeRow = function ($fp, array $cols) {
    $escaped = array_map(function ($v) {
        $v = (string)$v;
        // Quote jika mengandung koma, kutip, atau newline
        if (preg_match('/[",\r\n]/', $v)) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }, $cols);
    fwrite($fp, implode(',', $escaped) . "\r\n");
};

// Header
$writeRow($fp, [
    'username', 'password', 'firstname', 'lastname',
    'email', 'city', 'country', 'department', 'institution', 'course1'
]);

$count = 0;
while ($r = $result->fetch_assoc()) {
    $kode = trim($r['kode_desa']);
    $pwd  = $r['lms_password'] !== '' && $r['lms_password'] !== null
            ? $r['lms_password']
            : strrev($kode);

    $nama_desa = trim($r['nama_desa'] ?? '');
    $kec       = trim($r['kecamatan'] ?? '');
    $kab       = trim($r['kabupaten'] ?? '');
    $prov      = trim($r['provinsi']  ?? '');
    $email     = trim($r['email']     ?? '');

    // Skip jika data esensial kosong
    if ($kode === '' || $email === '') continue;

    // Tambah prefix hanya jika belum ada (case-insensitive)
    $firstname = $nama_desa !== ''
        ? (stripos($nama_desa, 'desa ') === 0 ? $nama_desa : "Desa {$nama_desa}")
        : '';
    $lastname  = $kec !== ''
        ? (stripos($kec, 'kec.') === 0 || stripos($kec, 'kecamatan') === 0 ? $kec : "Kec. {$kec}")
        : '';
    $city      = $kab !== ''
        ? (stripos($kab, 'kabupaten') === 0 || stripos($kab, 'kab.') === 0 ? $kab : "Kabupaten {$kab}")
        : '';
    // Department = kecamatan tanpa prefix
    $department = preg_replace('/^(kec\.?\s*|kecamatan\s+)/i', '', $kec);

    $writeRow($fp, [
        $kode,          // username
        $pwd,           // password
        $firstname,     // firstname
        $lastname,      // lastname
        $email,         // email
        $city,          // city
        'ID',           // country
        $department,    // department
        $prov,          // institution
        'brilian2026',  // course1
    ]);
    $count++;
}

fclose($fp);
$result->close();
$mysqli->close();

// Optional: hapus file lama (lebih dari 30 hari) supaya tidak menumpuk
foreach (glob($dir . '/moodle_users_*.csv') as $old) {
    if (filemtime($old) < strtotime('-30 days')) {
        @unlink($old);
    }
}

$size = filesize($filepath);
echo "[{$started}] OK - File: {$filename} ({$count} users, " . number_format($size) . " bytes)\n";

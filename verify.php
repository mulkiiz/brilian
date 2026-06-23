<?php
// Endpoint: verifikasi kode desa (untuk modal Edit Data)
// Disesuaikan dengan struktur desa_peserta v2 (CSV-aligned)
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'msg' => 'Metode tidak diizinkan.']);
}

$csrf = $_POST['csrf'] ?? '';
if (!csrf_check($csrf)) {
    json_response(['ok' => false, 'msg' => 'Sesi kedaluwarsa, mohon refresh halaman.']);
}

$kode = preg_replace('/\D/', '', $_POST['kode'] ?? '');
$ip   = get_ip();

if (!check_rate_limit($mysqli, $ip)) {
    json_response(['ok' => false, 'msg' => 'Terlalu banyak percobaan salah. Coba lagi 1 jam lagi atau hubungi panitia.']);
}

if (strlen($kode) !== 10) {
    log_attempt($mysqli, $ip, $kode, 0);
    json_response(['ok' => false, 'msg' => 'Kode desa harus 10 digit angka.']);
}

// Ambil dari desa_peserta v2 - kolom struktur baru
$sql = "SELECT kode_desa, firstname, lastname, city, institution, email
        FROM desa_peserta WHERE kode_desa = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $kode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    log_attempt($mysqli, $ip, $kode, 0);
    json_response(['ok' => false, 'msg' => 'Kode desa tidak ditemukan. Periksa kembali atau hubungi panitia.']);
}

// Cek apakah sudah pernah konfirmasi
$stmt = $mysqli->prepare("SELECT nama_desa, kecamatan, kabupaten, provinsi, nama_kades,
                                 hp_kades, email, total_edits
                          FROM konfirmasi_desa WHERE kode_desa = ? LIMIT 1");
$stmt->bind_param('s', $kode);
$stmt->execute();
$prev = $stmt->get_result()->fetch_assoc();
$stmt->close();

log_attempt($mysqli, $ip, $kode, 1);

// Strip prefix dari kolom desa_peserta untuk display
$nama_master = preg_replace('/^Desa\s+/i',     '', $row['firstname']);
$kec_master  = preg_replace('/^Kec\.?\s+/i',   '', $row['lastname']);

// Email & HP: prefer dari konfirmasi (jika ada), fallback ke desa_peserta
$email = $prev['email']    ?? $row['email'];
$hp    = $prev['hp_kades'] ?? '';

// Skip placeholder email dari Moodle CSV
if (stripos($email, '@brilian2026.id') !== false) $email = '';

// Bersihkan HP placeholder
$hp_clean = preg_replace('/\D/', '', $hp);
if (in_array($hp_clean, ['', '0', '0000'], true)) $hp = '';
if (strpos($hp_clean, '62') === 0 && strlen($hp_clean) > 2) {
    $hp = '0' . substr($hp_clean, 2);
}

// Data lain prefer dari konfirmasi (jika ada), fallback dari master
$nama_desa  = $prev['nama_desa']  ?? $nama_master;
$kec        = $prev['kecamatan']  ?? $kec_master;
$kab        = $prev['kabupaten']  ?? $row['city'];
$prov       = $prev['provinsi']   ?? $row['institution'];
$nama_kades = $prev['nama_kades'] ?? '';

json_response([
    'ok'   => true,
    'data' => [
        'kode'        => $row['kode_desa'],
        'nama'        => $nama_desa,
        'kec'         => $kec,
        'kab'         => $kab,
        'prov'        => $prov,
        'nama_kades'  => $nama_kades,
        'hp'          => $hp,
        'email'       => $email,
        'sudah_konfirmasi' => !empty($prev),
        'total_edits'      => (int)($prev['total_edits'] ?? 0),
    ]
]);

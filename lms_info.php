<?php
// Endpoint: ambil info kredensial LMS dari tabel desa_peserta (struktur v2)
// Username = kode_desa, Password = kolom password (sama dgn yg ada di Moodle)
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

// Ambil dari desa_peserta (struktur v2)
$stmt = $mysqli->prepare("SELECT kode_desa, password, firstname FROM desa_peserta WHERE kode_desa = ? LIMIT 1");
$stmt->bind_param('s', $kode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    log_attempt($mysqli, $ip, $kode, 0);
    json_response(['ok' => false, 'msg' => 'Kode desa tidak ditemukan. Periksa kembali atau hubungi panitia.']);
}

log_attempt($mysqli, $ip, $kode, 1);

// firstname = "Desa <nama>" → strip prefix untuk display
$nama_desa = preg_replace('/^Desa\s+/i', '', $row['firstname']);

json_response([
    'ok'   => true,
    'data' => [
        'kode'      => $row['kode_desa'],
        'nama_desa' => $nama_desa,
        'username'  => $row['kode_desa'],
        'password'  => $row['password'],
        'lms_url'   => 'https://joglo.unsoed.ac.id',
    ]
]);

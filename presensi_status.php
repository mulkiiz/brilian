<?php
// Endpoint: cek tanggal mana saja yg sudah presensi utk 1 desa
// v2: kirim 'today' dari server agar frontend tahu tanggal mana yg boleh diklik
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
    json_response(['ok' => false, 'msg' => 'Terlalu banyak percobaan. Coba lagi 1 jam lagi.']);
}

if (strlen($kode) !== 10) {
    log_attempt($mysqli, $ip, $kode, 0);
    json_response(['ok' => false, 'msg' => 'Kode desa harus 10 digit angka.']);
}

// Validasi desa ada di master
$stmt = $mysqli->prepare("SELECT kode_desa, firstname FROM desa_peserta WHERE kode_desa = ? LIMIT 1");
$stmt->bind_param('s', $kode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    log_attempt($mysqli, $ip, $kode, 0);
    json_response(['ok' => false, 'msg' => 'Kode desa tidak ditemukan.']);
}

log_attempt($mysqli, $ip, $kode, 1);
$nama_desa = preg_replace('/^Desa\s+/i', '', $row['firstname']);

// Ambil semua presensi desa ini
$stmt = $mysqli->prepare("SELECT tanggal_sesi, submitted_at, status
                          FROM presensi_desa WHERE kode_desa = ?
                          ORDER BY tanggal_sesi ASC");
$stmt->bind_param('s', $kode);
$stmt->execute();
$res = $stmt->get_result();
$presensi = [];
while ($r = $res->fetch_assoc()) {
    $presensi[$r['tanggal_sesi']] = [
        'submitted_at' => date('d-m-Y H:i', strtotime($r['submitted_at'])) . ' WIB',
        'status'       => $r['status'],
    ];
}
$stmt->close();

json_response([
    'ok'   => true,
    'data' => [
        'kode'      => $row['kode_desa'],
        'nama_desa' => $nama_desa,
        'presensi'  => $presensi,
        'today'     => date('Y-m-d'),                       // <-- tgl sistem server
        'today_label' => date('d M Y'),                     // utk pesan UI
    ]
]);

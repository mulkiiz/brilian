<?php
/**
 * Endpoint: ambil seluruh nilai gradebook untuk 1 desa (read-only).
 * Dipakai modal "Gradebook" di index.php. Wajib kode desa valid.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'msg' => 'Metode tidak diizinkan.']);
}
if (!csrf_check($_POST['csrf'] ?? '')) {
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

// Kehadiran
$hadir = [];
$stmt = $mysqli->prepare("SELECT hari FROM gradebook_hadir WHERE kode_desa=? AND hadir=1");
$stmt->bind_param('s', $kode); $stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc()) $hadir[$x['hari']] = 1;
$stmt->close();

// Pre/Post test
$nilai = [];
$stmt = $mysqli->prepare("SELECT hari, jenis, nilai FROM gradebook_nilai WHERE kode_desa=?");
$stmt->bind_param('s', $kode); $stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc()) {
    $nilai[$x['hari']][$x['jenis']] = rtrim(rtrim((string)$x['nilai'], '0'), '.');
}
$stmt->close();

// 4 Tugas (nilai)
$tugas = [];
$stmt = $mysqli->prepare("SELECT tugas_no, kumpul, nilai FROM gradebook_tugas WHERE kode_desa=?");
$stmt->bind_param('s', $kode); $stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc()) {
    $tugas[(int)$x['tugas_no']] = [
        'nilai'  => $x['nilai'] === null ? null : rtrim(rtrim((string)$x['nilai'], '0'), '.'),
        'kumpul' => (int)$x['kumpul'],
    ];
}
$stmt->close();

// Keaktifan
$keaktifan = null;
$stmt = $mysqli->prepare("SELECT nilai FROM gradebook_keaktifan WHERE kode_desa=? LIMIT 1");
$stmt->bind_param('s', $kode); $stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($r && $r['nilai'] !== null && $r['nilai'] !== '') {
    $keaktifan = rtrim(rtrim((string)$r['nilai'], '0'), '.');
}

json_response([
    'ok'   => true,
    'data' => [
        'kode'      => $row['kode_desa'],
        'nama_desa' => $nama_desa,
        'hadir'     => $hadir,
        'nilai'     => $nilai,
        'tugas'     => $tugas,
        'keaktifan' => $keaktifan,
    ],
]);

<?php
// =====================================================
// Endpoint presensi mandiri DIHENTIKAN per 13 Mei 2026.
// Mekanisme presensi sekarang via upload Moodle Attendance oleh admin.
// File ini dipertahankan agar tidak 404, dan untuk audit log
// jika ada pihak yang mencoba submit di luar prosedur.
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'msg' => 'Metode tidak diizinkan.']);
}

// Log percobaan submit (untuk monitoring)
$ip = get_ip();
$kode = preg_replace('/\D/', '', $_POST['kode'] ?? '');
@log_attempt($mysqli, $ip, $kode, 0);

json_response([
    'ok'  => false,
    'msg' => 'Presensi mandiri sudah tidak digunakan. '
           . 'Kehadiran dicatat otomatis oleh panitia berdasarkan Activity Attendance di LMS Joglo. '
           . 'Pastikan Anda hadir dan klik "Submit attendance" di Moodle saat sesi berlangsung.',
]);

<?php
// Endpoint: simpan konfirmasi data ke konfirmasi_desa
// Disesuaikan dengan struktur desa_peserta v2 (CSV-aligned)
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'msg' => 'Metode tidak diizinkan.']);
}

$csrf = $_POST['csrf'] ?? '';
if (!csrf_check($csrf)) {
    json_response(['ok' => false, 'msg' => 'Sesi kedaluwarsa, mohon refresh halaman.']);
}

// Input
$kode       = preg_replace('/\D/', '', $_POST['kode'] ?? '');
$nama_desa  = trim($_POST['nama_desa']  ?? '');
$kec        = trim($_POST['kec']        ?? '');
$kab        = trim($_POST['kab']        ?? '');
$prov       = trim($_POST['prov']       ?? '');
$nama_kades = trim($_POST['nama_kades'] ?? '');
$hp         = trim($_POST['hp']         ?? '');
$email      = trim($_POST['email']      ?? '');
$confirmed  = (int)($_POST['confirmed'] ?? 0);

$ip = get_ip();
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

// Validasi
if (strlen($kode) !== 10) {
    json_response(['ok' => false, 'msg' => 'Kode desa tidak valid.']);
}
if (!$confirmed) {
    json_response(['ok' => false, 'msg' => 'Anda harus mencentang konfirmasi sebelum submit.']);
}
if ($nama_desa === '' || $kec === '' || $kab === '' || $prov === '' || $nama_kades === '') {
    json_response(['ok' => false, 'msg' => 'Semua data wajib diisi (nama desa, kecamatan, kabupaten, provinsi, nama kades).']);
}
if (!valid_hp($hp)) {
    json_response(['ok' => false, 'msg' => 'Nomor HP tidak valid. Gunakan format 08xxxxxxxxxx (minimal 11 digit).']);
}
if (!valid_email($email)) {
    json_response(['ok' => false, 'msg' => 'Format email tidak valid. Contoh: nama@gmail.com']);
}
$hp_normal = normalize_hp($hp);

// Cek apakah sudah pernah konfirmasi (untuk komparasi field yg diedit)
$stmt = $mysqli->prepare("SELECT nama_desa, kecamatan, kabupaten, provinsi, nama_kades,
                                 hp_kades, email, total_edits
                          FROM konfirmasi_desa WHERE kode_desa = ? LIMIT 1");
$stmt->bind_param('s', $kode);
$stmt->execute();
$prev = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Cek desa ada di master + ambil data master untuk fallback komparasi
$stmt = $mysqli->prepare("SELECT firstname, lastname, city, institution, email
                          FROM desa_peserta WHERE kode_desa = ? LIMIT 1");
$stmt->bind_param('s', $kode);
$stmt->execute();
$master = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$master) {
    json_response(['ok' => false, 'msg' => 'Kode desa tidak ditemukan.']);
}

// Strip prefix master untuk komparasi
$master_nama = preg_replace('/^Desa\s+/i',     '', $master['firstname']);
$master_kec  = preg_replace('/^Kec\.?\s+/i',   '', $master['lastname']);

// Baseline untuk komparasi:
// - Jika peserta sudah pernah konfirmasi → bandingkan dengan data konfirmasi terakhir
// - Jika belum pernah → bandingkan dengan master (desa_peserta)
$base_nama  = $prev['nama_desa']  ?? $master_nama;
$base_kec   = $prev['kecamatan']  ?? $master_kec;
$base_kab   = $prev['kabupaten']  ?? $master['city'];
$base_prov  = $prev['provinsi']   ?? $master['institution'];
$base_kades = $prev['nama_kades'] ?? '';
$base_email = $prev['email']      ?? $master['email'];
$base_hp    = $prev['hp_kades']   ?? '';

// Email placeholder dari CSV Moodle dianggap kosong (bukan baseline valid)
if (stripos($base_email, '@brilian2026.id') !== false) $base_email = '';

// Deteksi field yang diedit
$edited = [];
$cmp = function($a, $b) {
    return strcasecmp(trim((string)$a), trim((string)$b)) !== 0;
};
if ($cmp($nama_desa,  $base_nama))  $edited[] = 'nama_desa';
if ($cmp($kec,        $base_kec))   $edited[] = 'kecamatan';
if ($cmp($kab,        $base_kab))   $edited[] = 'kabupaten';
if ($cmp($prov,       $base_prov))  $edited[] = 'provinsi';
if ($cmp($nama_kades, $base_kades)) $edited[] = 'nama_kades';
if ($cmp($email,      $base_email)) $edited[] = 'email';

// HP: bandingkan setelah normalisasi (08xx → 628xx)
$base_hp_n = preg_replace('/\D/', '', $base_hp);
if (strpos($base_hp_n, '0') === 0 && strlen($base_hp_n) > 1) {
    $base_hp_n = '62' . substr($base_hp_n, 1);
} elseif (strpos($base_hp_n, '8') === 0) {
    $base_hp_n = '62' . $base_hp_n;
}
if ($hp_normal !== $base_hp_n) $edited[] = 'hp_kades';

$edited_str = implode(',', $edited);

// Cek revisi ke berapa
$revision_no = ($prev ? (int)$prev['total_edits'] : 0) + 1;

// UPSERT ke konfirmasi_desa
$mysqli->begin_transaction();
try {
    $sql = "INSERT INTO konfirmasi_desa
            (kode_desa, nama_desa, kecamatan, kabupaten, provinsi, nama_kades,
             hp_kades, email, edited_fields, total_edits, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
              nama_desa     = VALUES(nama_desa),
              kecamatan     = VALUES(kecamatan),
              kabupaten     = VALUES(kabupaten),
              provinsi      = VALUES(provinsi),
              nama_kades    = VALUES(nama_kades),
              hp_kades      = VALUES(hp_kades),
              email         = VALUES(email),
              edited_fields = VALUES(edited_fields),
              total_edits   = total_edits + 1,
              ip_address    = VALUES(ip_address),
              user_agent    = VALUES(user_agent),
              updated_at    = CURRENT_TIMESTAMP";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(
        'sssssssssss',
        $kode, $nama_desa, $kec, $kab, $prov, $nama_kades,
        $hp_normal, $email, $edited_str, $ip, $ua
    );
    $stmt->execute();
    $stmt->close();

    // Insert ke riwayat (jika tabel ada)
    $sql2 = "INSERT INTO konfirmasi_riwayat
             (kode_desa, nama_desa, kecamatan, kabupaten, provinsi, nama_kades,
              hp_kades, email, edited_fields, revision_no, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql2);
    if ($stmt) {
        $stmt->bind_param(
            'sssssssssiss',
            $kode, $nama_desa, $kec, $kab, $prov, $nama_kades,
            $hp_normal, $email, $edited_str, $revision_no, $ip, $ua
        );
        $stmt->execute();
        $stmt->close();
    }

    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
    json_response(['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()]);
}

$hp_display = strpos($hp_normal, '62') === 0 ? '0' . substr($hp_normal, 2) : $hp_normal;

json_response([
    'ok'   => true,
    'msg'  => 'Konfirmasi berhasil disimpan.',
    'data' => [
        'nama'        => $nama_desa,
        'hp'          => $hp_display,
        'email'       => $email,
        'edited'      => $edited,
        'revision_no' => $revision_no,
    ]
]);

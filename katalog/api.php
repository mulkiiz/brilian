<?php
// =====================================================
// KATALOG API
// Actions:
//   verify_kode       - public (rate-limited)
//   save_narasi       - perlu sesi
//   upload_photo      - perlu sesi
//   delete_photo      - perlu sesi
//   update_caption    - perlu sesi
//   logout            - perlu sesi
// =====================================================
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'msg' => 'Metode tidak diizinkan.']);
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    json_response(['ok' => false, 'msg' => 'Sesi kedaluwarsa, mohon refresh halaman.']);
}

$action = $_POST['action'] ?? '';

// =====================================================
// VERIFY KODE (public, rate-limited)
// =====================================================
if ($action === 'verify_kode') {
    $kode = preg_replace('/\D/', '', $_POST['kode'] ?? '');
    $ip   = get_ip();

    if (!check_rate_limit($mysqli, $ip)) {
        json_response(['ok' => false, 'msg' => 'Terlalu banyak percobaan salah. Coba lagi 1 jam lagi atau hubungi panitia.']);
    }
    if (strlen($kode) !== 10) {
        log_attempt($mysqli, $ip, $kode, 0);
        json_response(['ok' => false, 'msg' => 'Kode desa harus 10 digit angka.']);
    }

    $stmt = $mysqli->prepare("SELECT kode_desa, firstname, lastname, city, institution FROM desa_peserta WHERE kode_desa = ? LIMIT 1");
    $stmt->bind_param('s', $kode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        log_attempt($mysqli, $ip, $kode, 0);
        json_response(['ok' => false, 'msg' => 'Kode desa tidak ditemukan. Periksa kembali atau hubungi panitia.']);
    }

    log_attempt($mysqli, $ip, $kode, 1);
    session_regenerate_id(true);
    $_SESSION['katalog_kode'] = $row['kode_desa'];
    $_SESSION['katalog_nama'] = preg_replace('/^Desa\s+/i', '', $row['firstname']);
    $_SESSION['katalog_kec']  = preg_replace('/^Kec\.?\s+/i', '', $row['lastname']);
    $_SESSION['katalog_kab']  = $row['city'];
    $_SESSION['katalog_prov'] = $row['institution'];

    json_response(['ok' => true, 'msg' => 'Verifikasi berhasil.']);
}

// =====================================================
// Action lain butuh sesi katalog
// =====================================================
$kode = $_SESSION['katalog_kode'] ?? null;
if (!$kode) {
    json_response(['ok' => false, 'msg' => 'Sesi tidak valid. Silakan login kembali.', 'redirect' => 'index.php']);
}

switch ($action) {

    // -------------------- LOGOUT --------------------
    case 'logout': {
        unset($_SESSION['katalog_kode'], $_SESSION['katalog_nama'],
              $_SESSION['katalog_kec'], $_SESSION['katalog_kab'], $_SESSION['katalog_prov']);
        json_response(['ok' => true, 'redirect' => 'index.php']);
        break;
    }

    // -------------------- SIMPAN NARASI --------------------
    case 'save_narasi': {
        $section = $_POST['section'] ?? '';
        if (!katalog_section($section)) {
            json_response(['ok' => false, 'msg' => 'Section tidak valid.']);
        }
        $html = katalog_sanitize_html($_POST['narasi'] ?? '');

        if (strip_tags($html) === '' || preg_match('/^\s*<p>(\s|<br\s*\/?>)*<\/p>\s*$/i', $html)) {
            $html = '';
        }

        $stmt = $mysqli->prepare(
            "INSERT INTO katalog_desa (kode_desa, section_key, narasi_html)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE narasi_html=VALUES(narasi_html), updated_at=NOW()"
        );
        $stmt->bind_param('sss', $kode, $section, $html);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) json_response(['ok' => false, 'msg' => 'Gagal menyimpan ke database.']);

        katalog_refresh_status($mysqli, $kode);
        $filled = katalog_count_filled($mysqli, $kode);
        json_response(['ok' => true, 'msg' => 'Narasi tersimpan.', 'filled' => $filled]);
        break;
    }

    // -------------------- UPLOAD FOTO --------------------
    case 'upload_photo': {
        $section = $_POST['section'] ?? '';
        if (!katalog_section($section)) {
            json_response(['ok' => false, 'msg' => 'Section tidak valid.']);
        }
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'File melebihi batas server (php.ini).',
                UPLOAD_ERR_FORM_SIZE  => 'File terlalu besar.',
                UPLOAD_ERR_PARTIAL    => 'Upload tidak selesai.',
                UPLOAD_ERR_NO_FILE    => 'Tidak ada file.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server tidak punya folder tmp.',
                UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file.',
            ];
            $code = $_FILES['photo']['error'] ?? -1;
            json_response(['ok' => false, 'msg' => $errMap[$code] ?? 'Upload gagal.']);
        }

        $file = $_FILES['photo'];

        $stmt = $mysqli->prepare("SELECT COUNT(*) c FROM katalog_foto WHERE kode_desa=? AND section_key=?");
        $stmt->bind_param('ss', $kode, $section);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        if ($cnt >= KATALOG_MAX_PHOTOS) {
            json_response(['ok' => false, 'msg' => 'Sudah mencapai batas '.KATALOG_MAX_PHOTOS.' foto. Hapus salah satu dulu.']);
        }

        if ($file['size'] > KATALOG_MAX_FILESIZE) {
            json_response(['ok' => false, 'msg' => 'Ukuran foto melebihi '.(KATALOG_MAX_FILESIZE/1024).' KB ('.round($file['size']/1024).' KB). Tolong dikompres dulu.']);
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
        if ($finfo) finfo_close($finfo);

        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($ext_map[$mime])) {
            json_response(['ok' => false, 'msg' => 'Format file harus JPG, PNG, atau WebP.']);
        }
        $ext = $ext_map[$mime];

        $dir = KATALOG_UPLOAD_BASE . '/' . $kode;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                json_response(['ok' => false, 'msg' => 'Gagal membuat folder upload.']);
            }
            @file_put_contents($dir . '/index.html', '');
        }

        $fname = $section . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest  = $dir . '/' . $fname;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_response(['ok' => false, 'msg' => 'Gagal menyimpan file di server.']);
        }
        @chmod($dest, 0644);

        $urutan = $cnt + 1;
        $size   = (int)$file['size'];
        $stmt = $mysqli->prepare(
            "INSERT INTO katalog_foto (kode_desa, section_key, filename, urutan, filesize)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssii', $kode, $section, $fname, $urutan, $size);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        katalog_refresh_status($mysqli, $kode);
        $filled = katalog_count_filled($mysqli, $kode);

        json_response([
            'ok'  => true,
            'msg' => 'Foto berhasil diunggah.',
            'filled' => $filled,
            'photo' => [
                'id'       => $newId,
                'url'      => KATALOG_UPLOAD_URL . '/' . $kode . '/' . $fname,
                'caption'  => '',
            ]
        ]);
        break;
    }

    // -------------------- HAPUS FOTO --------------------
    case 'delete_photo': {
        $id = (int)($_POST['photo_id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'msg' => 'ID foto tidak valid.']);

        $stmt = $mysqli->prepare("SELECT filename FROM katalog_foto WHERE id=? AND kode_desa=?");
        $stmt->bind_param('is', $id, $kode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) json_response(['ok' => false, 'msg' => 'Foto tidak ditemukan.']);

        $path = KATALOG_UPLOAD_BASE . '/' . $kode . '/' . $row['filename'];
        if (is_file($path)) @unlink($path);

        $stmt = $mysqli->prepare("DELETE FROM katalog_foto WHERE id=? AND kode_desa=?");
        $stmt->bind_param('is', $id, $kode);
        $stmt->execute();
        $stmt->close();

        katalog_refresh_status($mysqli, $kode);
        $filled = katalog_count_filled($mysqli, $kode);
        json_response(['ok' => true, 'msg' => 'Foto dihapus.', 'filled' => $filled]);
        break;
    }

    // -------------------- EDIT CAPTION --------------------
    case 'update_caption': {
        $id      = (int)($_POST['photo_id'] ?? 0);
        $caption = trim((string)($_POST['caption'] ?? ''));
        if (mb_strlen($caption) > 500) $caption = mb_substr($caption, 0, 500);

        $stmt = $mysqli->prepare("UPDATE katalog_foto SET caption=? WHERE id=? AND kode_desa=?");
        $stmt->bind_param('sis', $caption, $id, $kode);
        $stmt->execute();
        $stmt->close();

        json_response(['ok' => true, 'msg' => 'Caption diperbarui.']);
        break;
    }

    default:
        json_response(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
}

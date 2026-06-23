<?php
// =====================================================
// Upload & Import Presensi dari Moodle Attendance Export
// Admin only. Konsumsi xlsx hasil export "Activity Attendance" Moodle.
// =====================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/XlsxReader.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------
function strip_desa($s) { return preg_replace('/^Desa\s+/i', '', trim((string)$s)); }
function strip_kec($s)  { return preg_replace('/^Kec\.?\s+/i', '', trim((string)$s)); }

/** Normalisasi untuk fuzzy match: lowercase, hilangkan tanda baca, satu spasi */
function nm_norm($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('#[()/\-,.]#', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Parse header sesi: "12 May 2026 8.00AM All students"
 *  → ['date_iso' => '2026-05-12', 'date_label' => '12 May 2026', 'time' => '08:00:00']
 * Return null kalau bukan format sesi.
 */
function parse_session_header($h) {
    $h = trim((string)$h);
    // Pattern: D MMM YYYY H[.MM][AM|PM] All students
    if (!preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})\s+(\d{1,2})(?:[.:](\d{1,2}))?\s*(AM|PM)?\s+All students/i', $h, $m)) {
        return null;
    }
    $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
               'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    $mon = $months[strtolower(substr($m[2], 0, 3))] ?? null;
    if (!$mon) return null;
    $day = (int)$m[1]; $year = (int)$m[3];
    $hour = (int)$m[4]; $minute = isset($m[5]) ? (int)$m[5] : 0;
    $ampm = strtoupper($m[6] ?? '');
    if ($ampm === 'PM' && $hour < 12) $hour += 12;
    if ($ampm === 'AM' && $hour === 12) $hour = 0;
    return [
        'date_iso'   => sprintf('%04d-%02d-%02d', $year, $mon, $day),
        'date_label' => sprintf('%d %s %d', $day, ucfirst(strtolower(substr($m[2],0,3))), $year),
        'time'       => sprintf('%02d:%02d:00', $hour, $minute),
    ];
}

/**
 * Cek sel attendance: "H (2/2)" = hadir. Pattern: H/P/Present di depan, fraksi 2/2 ideal.
 * Return true kalau dianggap hadir.
 */
function is_hadir_cell($val) {
    $v = strtoupper(trim((string)$val));
    if ($v === '' || $v === '?') return false;
    // Format Moodle: "H (2/2)" / "P (2/2)" / kadang "L" (late)
    // Aturan user: HANYA "H (2/2)" yg dihitung hadir.
    return (bool)preg_match('/^H\s*\(\s*(\d+)\s*\/\s*\2\s*\)/i', $v)
        || preg_match('/^H\s*\(\s*2\s*\/\s*2\s*\)$/i', $v);
}

// ---------------------------------------------------------------
// Build master index for desa matching
// ---------------------------------------------------------------
function load_master_indexes($mysqli) {
    $by_kode = [];
    $by_nk = []; $by_nk_n = [];
    $by_nm = []; $by_nm_n = [];
    $all = [];

    $res = $mysqli->query("SELECT kode_desa, firstname, lastname, city, institution FROM desa_peserta");
    while ($r = $res->fetch_assoc()) {
        $nama = strip_desa($r['firstname']);
        $nama = strip_desa($nama); // handle "Desa Desa X"
        $kec  = strip_kec($r['lastname']);
        $rec = [
            'kode_desa' => $r['kode_desa'],
            'nama_desa' => $nama,
            'kecamatan' => $kec,
            'kabupaten' => $r['city'],
            'provinsi'  => $r['institution'],
        ];
        $all[] = $rec;
        $by_kode[$r['kode_desa']] = $rec;
        $nk = strtolower($nama) . '|' . strtolower($kec);
        $by_nk[$nk] = $rec;
        $by_nk_n[nm_norm($nama) . '|' . nm_norm($kec)] = $rec;
        $by_nm[strtolower($nama)][] = $rec;
        $by_nm_n[nm_norm($nama)][]  = $rec;
    }
    return compact('all', 'by_kode', 'by_nk', 'by_nk_n', 'by_nm', 'by_nm_n');
}

// Manual override (disetel sesuai temuan dari import-import sebelumnya)
$MANUAL_OVERRIDE = [
    // key: 'firstname_lower|lastname_lower'  (raw dari file, sebelum strip prefix)
    'desa|bangunreja'                       => '3301012010',
    'bumdes esa keter|desa kayuuwi satu'    => '7102222002',
    'desa ngetuk|jepara_ro10'               => '3320122011',
    'desa|teluk paman timur'                => '1401072034',
];

function match_desa($first_raw, $last_raw, $email, $idx, $manual) {
    $rawf = strtolower(trim((string)$first_raw));
    $rawl = strtolower(trim((string)$last_raw));
    $email = strtolower(trim((string)$email));

    // 0. Manual override (key pakai RAW first|last)
    if (isset($manual[$rawf . '|' . $rawl])) {
        return $idx['by_kode'][$manual[$rawf . '|' . $rawl]] ?? null;
    }

    $nama = strip_desa($first_raw);
    $kec  = strip_kec($last_raw);

    // 1. Exact (nama_clean, kec_clean) lowercase
    $k1 = strtolower($nama) . '|' . strtolower($kec);
    if (isset($idx['by_nk'][$k1])) return $idx['by_nk'][$k1];

    // 2. Normalized
    $k2 = nm_norm($nama) . '|' . nm_norm($kec);
    if (isset($idx['by_nk_n'][$k2])) return $idx['by_nk_n'][$k2];

    // 3. Email placeholder kdmp{10digit}@brilian2026.id
    if (preg_match('/^kdmp(\d{10})@brilian2026\.id$/', $email, $m)) {
        if (isset($idx['by_kode'][$m[1]])) return $idx['by_kode'][$m[1]];
    }

    // 4. Nama unique (cuma 1 di master)
    foreach ([strtolower($nama), nm_norm($nama)] as $key) {
        $bucket = $idx['by_nm'][$key] ?? $idx['by_nm_n'][$key] ?? null;
        if ($bucket && count($bucket) === 1) return $bucket[0];
    }

    // 5. Substring dalam kecamatan sama
    $kn = nm_norm($kec);
    foreach ($idx['all'] as $rec) {
        if (nm_norm($rec['kecamatan']) === $kn) {
            $nn = nm_norm($rec['nama_desa']);
            $nm = nm_norm($nama);
            if ($nn !== '' && $nm !== '' && (strpos($nn, $nm) !== false || strpos($nm, $nn) !== false)) {
                return $rec;
            }
        }
    }
    return null;
}

// ---------------------------------------------------------------
// Tanggal sesi yang diizinkan
// ---------------------------------------------------------------
$ALLOWED_DATES = [
    '2026-05-12','2026-05-13',
    '2026-05-19','2026-05-20','2026-05-21',
    '2026-05-25','2026-05-26',
];

$IP_TAG = 'attendance-import';

// ---------------------------------------------------------------
// Handle POST upload
// ---------------------------------------------------------------
$report = null;
$err = null;
$preview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'preview'; // 'preview' | 'commit'

        // === Tentukan source file ===
        // Preview: dari $_FILES (baru di-upload)
        // Commit:  dari token (file sudah di-save saat preview)
        $tmp = null;
        $name = null;

        if ($action === 'commit' && !empty($_POST['token'])) {
            // Ambil dari staging
            $token = preg_replace('/[^a-f0-9]/', '', $_POST['token']);
            if ($token === '' || strlen($token) !== 32) {
                throw new Exception('Token tidak valid. Silakan upload ulang.');
            }
            $stagedFile = sys_get_temp_dir() . '/brilian_attend_' . $token . '.xlsx';
            $stagedMeta = sys_get_temp_dir() . '/brilian_attend_' . $token . '.json';
            if (!is_file($stagedFile) || !is_file($stagedMeta)) {
                throw new Exception('File staging tidak ditemukan / sudah kedaluwarsa. Silakan upload ulang.');
            }
            $meta = json_decode(file_get_contents($stagedMeta), true);
            if (!$meta || ($meta['admin_session'] ?? '') !== session_id()) {
                throw new Exception('Sesi tidak cocok. Silakan upload ulang.');
            }
            $tmp  = $stagedFile;
            $name = $meta['name'] ?? 'upload.xlsx';

        } else {
            // Preview: harus ada file baru di-upload
            if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Pilih file Excel (.xlsx) terlebih dahulu.');
            }
            $tmp  = $_FILES['xlsx']['tmp_name'];
            $name = $_FILES['xlsx']['name'];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                throw new Exception('Hanya file .xlsx yang didukung (Excel 2007+). File: ' . htmlspecialchars($name));
            }
            if ($_FILES['xlsx']['size'] > 10 * 1024 * 1024) {
                throw new Exception('Ukuran file maksimal 10 MB.');
            }
        }

        $preview = ($action === 'preview');

        $rows = XlsxReader::read($tmp);
        if (empty($rows)) throw new Exception('File kosong atau tidak bisa dibaca.');

        // Cari header row: baris yg punya "Last name" + "First name" + "Email address"
        $headerRow = -1;
        $headers = [];
        foreach ($rows as $i => $row) {
            $low = array_map(function($v){ return strtolower(trim((string)$v)); }, $row);
            if (in_array('last name', $low, true) &&
                in_array('first name', $low, true) &&
                in_array('email address', $low, true)) {
                $headerRow = $i;
                $headers = $row;
                break;
            }
        }
        if ($headerRow < 0) throw new Exception('Header tabel (Last name / First name / Email address) tidak ditemukan. Pastikan file ini hasil export Activity Attendance dari Moodle.');

        // Map kolom kunci
        $colLast = $colFirst = $colEmail = -1;
        $sessionCols = []; // [col_idx => parsed_session_info]
        foreach ($headers as $c => $h) {
            $hl = strtolower(trim((string)$h));
            if ($hl === 'last name') $colLast = $c;
            elseif ($hl === 'first name') $colFirst = $c;
            elseif ($hl === 'email address') $colEmail = $c;
            else {
                $parsed = parse_session_header($h);
                if ($parsed !== null) {
                    if (in_array($parsed['date_iso'], $ALLOWED_DATES, true)) {
                        $sessionCols[$c] = $parsed;
                    }
                }
            }
        }
        if ($colLast < 0 || $colFirst < 0 || $colEmail < 0) {
            throw new Exception('Kolom wajib (Last name / First name / Email address) tidak lengkap.');
        }
        if (empty($sessionCols)) {
            throw new Exception('Tidak ada kolom sesi yang dikenali. Header sesi harus berformat "DD MMM YYYY H.MMAM/PM All students" dan tanggalnya termasuk dalam jadwal Brilian 2026.');
        }

        // Build master index
        $idx = load_master_indexes($mysqli);

        // Iterate data rows
        $perSession = []; // session_date => ['hadir'=>[], 'non_desa'=>[]]
        foreach ($sessionCols as $c => $info) {
            $perSession[$info['date_iso']] = [
                'info'     => $info,
                'hadir'    => [],
                'non_desa' => [],
                'kode_seen'=> [],
            ];
        }

        for ($i = $headerRow + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (empty($r)) continue;
            $first = $r[$colFirst] ?? '';
            $last  = $r[$colLast]  ?? '';
            $email = $r[$colEmail] ?? '';
            if ($first === '' && $last === '' && $email === '') continue;

            foreach ($sessionCols as $c => $info) {
                $cell = $r[$c] ?? '';
                if (!is_hadir_cell($cell)) continue;

                $m = match_desa($first, $last, $email, $idx, $MANUAL_OVERRIDE);
                if (!$m) {
                    $perSession[$info['date_iso']]['non_desa'][] = [
                        'first' => $first, 'last' => $last, 'email' => $email, 'cell' => $cell
                    ];
                    continue;
                }
                $kode = $m['kode_desa'];
                if (isset($perSession[$info['date_iso']]['kode_seen'][$kode])) continue; // dedup
                $perSession[$info['date_iso']]['kode_seen'][$kode] = true;
                $perSession[$info['date_iso']]['hadir'][] = $m + ['cell' => $cell];
            }
        }

        // === Cek state existing di DB per sesi (utk angka "akan ditambahkan" vs "skip duplikat") ===
        foreach ($perSession as $tgl => &$bucket) {
            $stmt = $mysqli->prepare("SELECT kode_desa FROM presensi_desa WHERE tanggal_sesi = ?");
            $stmt->bind_param('s', $tgl);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = [];
            while ($e = $res->fetch_assoc()) $existing[$e['kode_desa']] = true;
            $stmt->close();
            $bucket['existing'] = $existing;
            $bucket['new'] = 0; $bucket['dup'] = 0;
            foreach ($bucket['hadir'] as $h) {
                if (isset($existing[$h['kode_desa']])) $bucket['dup']++; else $bucket['new']++;
            }
        }
        unset($bucket);

        if ($action === 'commit') {
            // === COMMIT: insert ke presensi_desa ===
            $ua_tag = 'IMPORT: Moodle Attendance via admin upload';
            $inserted = 0; $skipped = 0;

            $mysqli->begin_transaction();
            try {
                $sql = "INSERT IGNORE INTO presensi_desa
                        (kode_desa, nama_desa, kecamatan, kabupaten, provinsi,
                         tanggal_sesi, submitted_at, status, selisih_detik,
                         ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'ontime', ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);

                foreach ($perSession as $tgl => $bucket) {
                    $submitted_at = $tgl . ' ' . $bucket['info']['time'];
                    $selisih = strtotime($submitted_at) - strtotime($tgl . ' 00:00:00');
                    foreach ($bucket['hadir'] as $h) {
                        $stmt->bind_param(
                            'sssssssiss',
                            $h['kode_desa'], $h['nama_desa'], $h['kecamatan'],
                            $h['kabupaten'], $h['provinsi'],
                            $tgl, $submitted_at, $selisih, $IP_TAG, $ua_tag
                        );
                        $stmt->execute();
                        if ($stmt->affected_rows > 0) $inserted++; else $skipped++;
                    }
                }
                $stmt->close();
                $mysqli->commit();
            } catch (Exception $e) {
                $mysqli->rollback();
                throw $e;
            }

            // Bersihkan staging file setelah sukses commit
            if (!empty($_POST['token'])) {
                $tok = preg_replace('/[^a-f0-9]/', '', $_POST['token']);
                @unlink(sys_get_temp_dir() . '/brilian_attend_' . $tok . '.xlsx');
                @unlink(sys_get_temp_dir() . '/brilian_attend_' . $tok . '.json');
            }

            $report = [
                'mode' => 'commit',
                'sessions' => $perSession,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'file'     => $name,
            ];
        } else {
            // === PREVIEW: simpan file ke staging dgn token, tampilkan ringkasan ===
            $token = bin2hex(random_bytes(16));
            $stagedFile = sys_get_temp_dir() . '/brilian_attend_' . $token . '.xlsx';
            $stagedMeta = sys_get_temp_dir() . '/brilian_attend_' . $token . '.json';

            // Copy file ke staging (kalau $tmp dari $_FILES, gunakan move_uploaded_file;
            //  kalau $tmp sudah dari staging sebelumnya, abaikan – shouldn't happen di preview)
            if (is_uploaded_file($tmp)) {
                if (!move_uploaded_file($tmp, $stagedFile)) {
                    throw new Exception('Gagal menyimpan file untuk staging.');
                }
            } else {
                copy($tmp, $stagedFile);
            }
            file_put_contents($stagedMeta, json_encode([
                'name' => $name,
                'admin_session' => session_id(),
                'created_at' => time(),
            ]));

            // Cleanup file staging lama (> 1 jam) — housekeeping
            foreach (glob(sys_get_temp_dir() . '/brilian_attend_*.{xlsx,json}', GLOB_BRACE) ?: [] as $old) {
                if (is_file($old) && filemtime($old) < time() - 3600) {
                    @unlink($old);
                }
            }

            $report = [
                'mode' => 'preview',
                'sessions' => $perSession,
                'file'     => $name,
                'token'    => $token,
            ];
        }

    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Presensi - Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=7">
<style>
  .admin-bar { background:#0f1c33; color:#fff; padding:12px 0; }
  .admin-bar, .admin-bar * { color:#fff; }
  .admin-bar .wrap { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
  .admin-bar a { color:#ffd700 !important; }
  .admin-bar b { color:#fff !important; }

  .upload-box {
    background:#fff; border:2px dashed #d1d5db; border-radius:10px;
    padding:24px; text-align:center; margin:16px 0;
  }
  .upload-box.has-file { border-style:solid; border-color:#0f766e; background:#f0fdfa; }
  .upload-box input[type=file] { font-size:14px; }
  .btn-upload {
    background:#0f766e; color:#fff; border:none; padding:10px 20px;
    border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; margin-top:10px;
  }
  .btn-upload:hover { background:#0d5c55; }
  .btn-commit {
    background:#dc2626; color:#fff; border:none; padding:10px 22px;
    border-radius:6px; font-size:15px; font-weight:700; cursor:pointer; margin-top:14px;
  }
  .btn-commit:hover { background:#b91c1c; }
  .session-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
    padding:14px 18px; margin-bottom:12px;
  }
  .session-card h3 { margin:0 0 8px; color:#003d7a; }
  .session-stats { display:flex; gap:20px; flex-wrap:wrap; font-size:14px; margin:8px 0; }
  .session-stats div { padding:4px 0; }
  .session-stats b { color:#0f172a; }
  .non-desa-list {
    margin-top:10px; padding:10px 12px;
    background:#fef3c7; border-left:4px solid #f59e0b;
    border-radius:6px; font-size:13px;
  }
  .non-desa-list ul { margin:6px 0 0; padding-left:20px; }
  .non-desa-list li { font-family:ui-monospace,monospace; font-size:12px; }
  .info-banner {
    background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;
    padding:12px 16px; margin:14px 0; font-size:14px; color:#1e3a8a;
  }
  .ok-banner {
    background:#dcfce7; border:1px solid #bbf7d0; border-radius:8px;
    padding:14px 18px; margin:14px 0; font-size:15px; color:#166534;
  }
  .ok-banner b { font-size:22px; }
  .help-box {
    background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;
    padding:12px 16px; margin:14px 0; font-size:13px; color:#374151;
  }
  .help-box ul { margin:6px 0; padding-left:20px; }
</style>
</head>
<body>

<div class="admin-bar">
  <div class="wrap">
    <div><b>📤 Upload Presensi (Moodle Attendance)</b> &middot; Brilian 2026</div>
    <div>
      <a href="admin_presensi.php">← Admin Presensi</a> &nbsp;
      <a href="admin.php">Konfirmasi</a> &nbsp;
      <a href="admin.php?logout=1">Keluar</a>
    </div>
  </div>
</div>

<main class="wrap" style="padding-top:16px;padding-bottom:30px">

  <div class="help-box">
    <b>Cara pakai:</b>
    <ul>
      <li>Di Moodle: <b>Activity → Attendance → Export</b>, format <b>Excel (.xlsx)</b>, centang semua peserta.</li>
      <li>Upload file hasil export di bawah. Sistem akan baca kolom bertajuk "<i>DD MMM YYYY H.MMAM All students</i>".</li>
      <li>Sel bernilai "<b>H (2/2)</b>" = desa hadir. Sel kosong / "?" / lainnya = tidak hadir.</li>
      <li>Tahap <b>Preview</b> hanya menampilkan rekap. Klik <b>Simpan ke Database</b> untuk commit.</li>
      <li>Data yang sudah ada (presensi via klik tombol atau import sebelumnya) tidak ditimpa — di-skip otomatis.</li>
    </ul>
  </div>

  <?php if ($err): ?>
    <div class="msg error" style="display:block;padding:12px;border-radius:6px"><?= h($err) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <div class="upload-box">
      <div style="font-size:36px">📂</div>
      <div style="font-weight:600;margin:6px 0">Pilih file Excel hasil export Moodle Attendance</div>
      <input type="file" name="xlsx" accept=".xlsx" required>
      <div style="font-size:12px;color:#6b7280;margin-top:6px">Format: .xlsx (Excel 2007+) · Max 10 MB</div>
      <button class="btn-upload" type="submit" name="action" value="preview">📊 Preview Rekap</button>
    </div>
  </form>

  <?php if ($report && $report['mode'] === 'commit'): ?>
    <div class="ok-banner">
      ✅ <b><?= number_format($report['inserted'], 0, ',', '.') ?></b> presensi berhasil disimpan.
      <?php if ($report['skipped'] > 0): ?>
        <br><small><?= number_format($report['skipped'], 0, ',', '.') ?> di-skip karena sudah tercatat sebelumnya.</small>
      <?php endif; ?>
      <br><a href="admin_presensi.php" style="color:#166534;text-decoration:underline">→ Buka Admin Presensi untuk verifikasi</a>
    </div>
  <?php endif; ?>

  <?php if ($report): ?>
    <div class="info-banner">
      📄 File: <b><?= h($report['file']) ?></b> &middot;
      Sesi terdeteksi: <b><?= count($report['sessions']) ?></b>
    </div>

    <?php foreach ($report['sessions'] as $tgl => $bucket):
      $info = $bucket['info'];
      $hadir_cnt = count($bucket['hadir']);
      $nondesa_cnt = count($bucket['non_desa']);
    ?>
      <div class="session-card">
        <h3>📅 <?= h($info['date_label']) ?> &middot; Sesi <?= h(substr($info['time'], 0, 5)) ?></h3>
        <div class="session-stats">
          <div>Total <b>H (2/2)</b> di file: <b><?= $hadir_cnt + $nondesa_cnt ?></b></div>
          <div>Match ke desa: <b style="color:#16a34a"><?= $hadir_cnt ?></b></div>
          <?php if ($report['mode'] === 'preview'): ?>
            <div>Akan ditambahkan: <b style="color:#0f766e"><?= $bucket['new'] ?></b></div>
            <div>Sudah ada di DB (skip): <b style="color:#9ca3af"><?= $bucket['dup'] ?></b></div>
          <?php else: ?>
            <div>Diproses: <b style="color:#0f766e"><?= $hadir_cnt ?></b></div>
          <?php endif; ?>
          <?php if ($nondesa_cnt > 0): ?>
            <div>Non-desa (di-skip): <b style="color:#dc2626"><?= $nondesa_cnt ?></b></div>
          <?php endif; ?>
        </div>

        <?php if (!empty($bucket['non_desa'])): ?>
          <div class="non-desa-list">
            <b>⚠ Baris hadir tapi tidak match ke master desa</b> (di-skip — biasanya akun panitia/dosen):
            <ul>
              <?php foreach ($bucket['non_desa'] as $x): ?>
                <li><?= h($x['first']) ?> / <?= h($x['last']) ?> &lt;<?= h($x['email']) ?>&gt;</li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($report['mode'] === 'preview' && array_sum(array_column($report['sessions'], 'new')) > 0): ?>
      <form method="post"
            onsubmit="return confirm('Yakin simpan presensi ini ke database?\n\nData yang sudah ada akan di-skip otomatis (tidak ditimpa).')">
        <input type="hidden" name="action" value="commit">
        <input type="hidden" name="token" value="<?= h($report['token']) ?>">
        <div class="upload-box" style="border-color:#dc2626;background:#fef2f2">
          <div style="font-weight:600;color:#991b1b;margin-bottom:6px">
            ✅ File siap di-commit ke database
          </div>
          <div style="font-size:13px;color:#7f1d1d;margin-bottom:4px">
            File: <b><?= h($report['file']) ?></b>
          </div>
          <button class="btn-commit" type="submit">💾 Simpan ke Database</button>
        </div>
      </form>
    <?php elseif ($report['mode'] === 'preview'): ?>
      <div class="info-banner">
        Tidak ada presensi baru untuk disimpan (semua sudah tercatat di database).
      </div>
    <?php endif; ?>

  <?php endif; ?>

</main>

</body>
</html>

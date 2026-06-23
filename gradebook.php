<?php
/**
 * =====================================================
 * GRADE BOOK — Brilian 2026
 * =====================================================
 * Fitur:
 *  - Upload hasil PRE-TEST  Moodle (CSV) per hari
 *  - Upload hasil POST-TEST Moodle (CSV) per hari
 *  - Upload kehadiran (CSV presensi / XLSX kickoff) per hari
 *  - Upload status 4 tugas (CSV: no,kode_desa,nama_desa)
 *  - Upload nilai keaktifan akhir (XLSX — 1 nilai per desa)
 *  - Rekap tabel HTML 545 desa + Export Excel (.xls)
 *
 * Stack: PHP 7.3 + mysqli. Tanpa Composer.
 * Auth : reuse sesi admin.php
 * =====================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/XlsxReader.php';
require_once __DIR__ . '/PdfTextReader.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

// --- 7 hari sesi pelatihan (fix sesuai format output xlsx) ---
$HARI = [
    'kickoff' => ['label' => 'Kick Off',  'tgl' => '7 Mei 2026',  'test' => false],
    'day1'    => ['label' => 'Day 1',     'tgl' => '12 Mei 2026', 'test' => true],
    'day2'    => ['label' => 'Day 2',     'tgl' => '13 Mei 2026', 'test' => true],
    'day3'    => ['label' => 'Day 3',     'tgl' => '19 Mei 2026', 'test' => true],
    'day4'    => ['label' => 'Day 4',     'tgl' => '20 Mei 2026', 'test' => true],
    'day5'    => ['label' => 'Day 5',     'tgl' => '21 Mei 2026', 'test' => true],
    'day6'    => ['label' => 'Day 6',     'tgl' => '25 Mei 2026', 'test' => true],
    'day7'    => ['label' => 'Day 7',     'tgl' => '26 Mei 2026', 'test' => true],
];
$TUGAS = [
    1 => 'Aspek Legal',
    2 => 'Penyaluran Dana Desa',
    3 => 'Tugas Tematik',
    4 => 'Laporan Keuangan BUMDes',
];

$msg = ''; $msgType = '';

// =====================================================
// Helper: ambil daftar desa peserta (skema Moodle)
//   firstname = "Desa X", lastname = "Kec. Y"
// =====================================================
function load_desa($mysqli) {
    $list = []; $byEmail = []; $byNama = [];
    $res = $mysqli->query("SELECT kode_desa, firstname, lastname, city, department, institution, email FROM desa_peserta");
    while ($r = $res->fetch_assoc()) {
        $nama = preg_replace('/^Desa\s+/i', '', trim($r['firstname']));
        $kec  = preg_replace('/^Kec\.?\s+/i', '', trim($r['lastname']));
        $d = [
            'kode'      => $r['kode_desa'],
            'nama_desa' => $nama,
            'kecamatan' => $kec ?: $r['department'],
            'kabupaten' => $r['city'],
            'provinsi'  => $r['institution'],
            'email'     => strtolower(trim($r['email'])),
        ];
        $list[$r['kode_desa']] = $d;
        if ($d['email'] && stripos($d['email'], '@brilian2026.id') === false) {
            $byEmail[$d['email']] = $r['kode_desa'];
        }
        $byNama[norm_nama($nama)] = $r['kode_desa'];
    }
    return [$list, $byEmail, $byNama];
}

// Normalisasi nama desa untuk fuzzy match
function norm_nama($s) {
    $s = strtolower($s);
    $s = preg_replace('/\bdesa\b/', '', $s);
    $s = preg_replace('/\bbumdes(a)?\b/', '', $s);
    $s = preg_replace('/\(.*?\)/', '', $s);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
}

// Cocokkan baris pre/post-test ke kode desa
function match_kode($email, $namaDesa, $byEmail, $byNama, $listDesa) {
    $email = strtolower(trim($email));
    if (preg_match('/kdmp(\d{10})@brilian2026\.id/i', $email, $m)) {
        if (isset($listDesa[$m[1]])) return $m[1];
    }
    if ($email && isset($byEmail[$email])) return $byEmail[$email];
    $nn = norm_nama(preg_replace('/^Desa\s+/i', '', $namaDesa));
    if ($nn !== '' && isset($byNama[$nn])) return $byNama[$nn];
    return null;
}

// --- Helper khusus PDF Submissions Joglo ---
function ekstrak_nama_tugas($e) {
    $e = preg_replace('/^(DK|BD)\s+/i', '', trim($e));
    $e = preg_replace('/^Bumdes\s+.*?\s+(?=Desa\s)/i', '', $e);
    $p = preg_split('/\s+Kec\.?\s+/i', $e);
    $nama = $p[0];
    $nama = preg_replace('/^Desa\s+/i', '', $nama);
    $nama = preg_replace('/^Desa\s+/i', '', $nama);
    return trim($nama);
}

function match_tugas_kode($entri, $listDesa, $byNamaT) {
    if (preg_match('/kdmp(\d{10})(?:\.dup)?@brilian2026\.id/i', $entri, $mk)) {
        if (isset($listDesa[$mk[1]])) return $mk[1];
    }
    $nama = ekstrak_nama_tugas($entri);
    $nn = norm_nama($nama);
    if ($nn !== '' && isset($byNamaT[$nn])) return $byNamaT[$nn][0];
    if ($nn !== '') {
        foreach ($listDesa as $kd => $d) {
            $np = norm_nama($d['nama_desa']);
            if ($np !== '' && (strpos($nn, $np) !== false || strpos($np, $nn) !== false)) {
                return $kd;
            }
        }
    }
    return null;
}

function read_csv_assoc($path) {
    $rows = [];
    if (($fp = fopen($path, 'r')) === false) return $rows;
    $header = fgetcsv($fp);
    if ($header) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);
    }
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') continue;
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = isset($row[$i]) ? $row[$i] : '';
        }
        $rows[] = $assoc;
    }
    fclose($fp);
    return $rows;
}

// =====================================================
// PROSES UPLOAD
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = 'Sesi tidak valid (CSRF). Muat ulang halaman.'; $msgType = 'error';
    } else {
        $aksi = $_POST['aksi'] ?? '';
        list($listDesa, $byEmail, $byNama) = load_desa($mysqli);

        // ---------- UPLOAD PRE / POST TEST ----------
        if ($aksi === 'upload_test') {
            $hari  = $_POST['hari'] ?? '';
            $jenis = $_POST['jenis'] ?? '';
            if (!isset($HARI[$hari]) || !in_array($jenis, ['pretest','posttest'], true)) {
                $msg = 'Hari / jenis tidak valid.'; $msgType = 'error';
            } elseif (empty($_FILES['file']['tmp_name'])) {
                $msg = 'File belum dipilih.'; $msgType = 'error';
            } else {
                $rows = read_csv_assoc($_FILES['file']['tmp_name']);
                $colEmail = $colNama = $colGrade = null;
                if ($rows) {
                    foreach (array_keys($rows[0]) as $c) {
                        $lc = strtolower($c);
                        if ($colEmail === null && strpos($lc, 'email') !== false) $colEmail = $c;
                        if ($colNama  === null && strpos($lc, 'first name') !== false) $colNama = $c;
                        if ($colGrade === null && strpos($lc, 'grade') !== false) $colGrade = $c;
                    }
                }
                if (!$colEmail || !$colGrade) {
                    $msg = 'Kolom Email / Grade tidak ditemukan di CSV. Pastikan ini export "Grades" dari Moodle.';
                    $msgType = 'error';
                } else {
                    $ok = 0; $gagal = 0; $unmatched = [];
                    $stmt = $mysqli->prepare(
                        "INSERT INTO gradebook_nilai (kode_desa,hari,jenis,nilai,email_match)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE nilai=VALUES(nilai), email_match=VALUES(email_match), uploaded_at=NOW()");
                    foreach ($rows as $r) {
                        $email = trim($r[$colEmail] ?? '');
                        $namaD = $colNama ? trim($r[$colNama] ?? '') : '';
                        $gradeRaw = trim($r[$colGrade] ?? '');
                        $gabung = strtolower(implode(' ', $r));
                        if (($email === '' && $namaD === '') ||
                            strpos($gabung, 'overall average') !== false) {
                            continue;
                        }
                        if ($gradeRaw === '' || strtoupper($gradeRaw) === '-' ||
                            !is_numeric(str_replace(',', '.', $gradeRaw))) {
                            continue;
                        }
                        $nilai = (float)str_replace(',', '.', $gradeRaw);
                        $kode  = match_kode($email, $namaD, $byEmail, $byNama, $listDesa);
                        if ($kode === null) { $gagal++; $unmatched[] = $email ?: $namaD; continue; }
                        $stmt->bind_param('sssds', $kode, $hari, $jenis, $nilai, $email);
                        $stmt->execute();
                        $ok++;
                    }
                    $stmt->close();
                    $jl = $jenis === 'pretest' ? 'Pre-test' : 'Post-test';
                    $msg = "$jl {$HARI[$hari]['label']}: <b>$ok</b> nilai tersimpan.";
                    if ($gagal) {
                        $msg .= " <b>$gagal</b> baris gagal dicocokkan: " .
                                h(implode(', ', array_slice($unmatched, 0, 8))) .
                                (count($unmatched) > 8 ? ' …' : '');
                    }
                    $msgType = $gagal ? 'warn' : 'ok';
                }
            }
        }

        // ---------- UPLOAD KEHADIRAN ----------
        elseif ($aksi === 'upload_hadir') {
            $hari = $_POST['hari'] ?? '';
            if (!isset($HARI[$hari])) {
                $msg = 'Hari tidak valid.'; $msgType = 'error';
            } elseif (empty($_FILES['file']['tmp_name'])) {
                $msg = 'File belum dipilih.'; $msgType = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $kodes = [];
                try {
                    if ($ext === 'xlsx') {
                        $data = XlsxReader::read($_FILES['file']['tmp_name']);
                        if ($data) {
                            $hdr = array_map(function($x){ return strtolower(trim($x)); }, $data[0]);
                            $ci = array_search('kode desa', $hdr);
                            if ($ci === false) $ci = 1;
                            for ($i = 1; $i < count($data); $i++) {
                                $kd = preg_replace('/\D/', '', (string)($data[$i][$ci] ?? ''));
                                if (strlen($kd) >= 8) $kodes[str_pad($kd, 10, '0', STR_PAD_LEFT)] = true;
                            }
                        }
                    } else {
                        $rows = read_csv_assoc($_FILES['file']['tmp_name']);
                        foreach ($rows as $r) {
                            $kd = '';
                            foreach ($r as $k => $v) {
                                if (stripos($k, 'kode') !== false) { $kd = $v; break; }
                            }
                            $kd = preg_replace('/\D/', '', (string)$kd);
                            if (strlen($kd) >= 8) $kodes[str_pad($kd, 10, '0', STR_PAD_LEFT)] = true;
                        }
                    }
                } catch (Exception $e) {
                    $msg = 'Gagal baca file: ' . h($e->getMessage()); $msgType = 'error';
                }
                if (!$msg) {
                    $ok = 0; $luar = 0;
                    $stmt = $mysqli->prepare(
                        "INSERT INTO gradebook_hadir (kode_desa,hari,hadir) VALUES (?,?,1)
                         ON DUPLICATE KEY UPDATE hadir=1, uploaded_at=NOW()");
                    foreach (array_keys($kodes) as $kd) {
                        if (!isset($listDesa[$kd])) { $luar++; continue; }
                        $stmt->bind_param('ss', $kd, $hari);
                        $stmt->execute();
                        $ok++;
                    }
                    $stmt->close();
                    $msg = "Kehadiran {$HARI[$hari]['label']}: <b>$ok</b> desa ditandai hadir.";
                    if ($luar) $msg .= " ($luar kode di luar daftar peserta diabaikan.)";
                    $msgType = 'ok';
                }
            }
        }

        // ---------- UPLOAD STATUS TUGAS (PDF Submissions Joglo) ----------
        elseif ($aksi === 'upload_tugas') {
            $tno = (int)($_POST['tugas_no'] ?? 0);
            if ($tno < 1 || $tno > 4) {
                $msg = 'Nomor tugas tidak valid.'; $msgType = 'error';
            } elseif (empty($_FILES['file']['tmp_name'])) {
                $msg = 'File belum dipilih.'; $msgType = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    $msg = 'File harus PDF (export "Submissions" dari Joglo).';
                    $msgType = 'error';
                } else {
                    try {
                        $teks = PdfTextReader::extract($_FILES['file']['tmp_name']);
                        $flat = preg_replace('/\s+/', ' ', $teks);

                        preg_match_all(
                            '/((?:DK |BD )?(?:Desa|DESA|Bumdes)\b.*?)(?=[Ss]ubmit(?:ted|te|s)?\s*for\s*grading)/i',
                            $flat, $mm);
                        $entries = $mm[1];
                        $jmlSubmit = preg_match_all('/[Ss]ubmit(?:ted|te|s)?\s*for\s*grading/i', $flat);

                        if (empty($entries)) {
                            $msg = 'Tidak ada submission terbaca dari PDF. '
                                 . 'Pastikan ini PDF "Submissions" dari Joglo (bukan hasil scan).';
                            $msgType = 'error';
                        } else {
                            $byNamaT = [];
                            foreach ($listDesa as $kd => $d) {
                                $byNamaT[norm_nama($d['nama_desa'])][] = $kd;
                            }
                            $ok = 0; $gagal = []; $sudahProses = [];
                            $stmt = $mysqli->prepare(
                                "INSERT INTO gradebook_tugas (kode_desa,tugas_no,kumpul) VALUES (?,?,1)
                                 ON DUPLICATE KEY UPDATE kumpul=1, uploaded_at=NOW()");

                            foreach ($entries as $e) {
                                $kode = match_tugas_kode($e, $listDesa, $byNamaT);
                                if ($kode === null) {
                                    $gagal[] = ekstrak_nama_tugas($e);
                                    continue;
                                }
                                if (isset($sudahProses[$kode])) continue;
                                $sudahProses[$kode] = true;
                                $stmt->bind_param('si', $kode, $tno);
                                $stmt->execute();
                                $ok++;
                            }
                            $stmt->close();

                            $msg = "Tugas $tno ({$TUGAS[$tno]}): terbaca <b>" . count($entries)
                                 . "</b> submission, <b>$ok</b> desa ditandai mengumpulkan.";
                            if ($gagal) {
                                $msg .= " <b>" . count($gagal) . "</b> gagal dicocokkan: "
                                      . h(implode(', ', array_slice($gagal, 0, 6)))
                                      . (count($gagal) > 6 ? ' …' : '');
                                $msgType = 'warn';
                            } else {
                                $msgType = 'ok';
                            }
                        }
                    } catch (Exception $ex) {
                        $msg = 'Gagal memproses PDF: ' . h($ex->getMessage());
                        $msgType = 'error';
                    }
                }
            }
        }

        // ---------- UPLOAD NILAI KEAKTIFAN (XLSX) ----------
        elseif ($aksi === 'upload_keaktifan') {
            if (empty($_FILES['file']['tmp_name'])) {
                $msg = 'File belum dipilih.'; $msgType = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'xlsx') {
                    $msg = 'File harus XLSX.'; $msgType = 'error';
                } else {
                    try {
                        // Baca sheet pertama ("Nilai Keaktifan")
                        $data = XlsxReader::read($_FILES['file']['tmp_name'], 0);
                        if (empty($data) || count($data) < 2) {
                            $msg = 'File kosong atau header tidak ditemukan.'; $msgType = 'error';
                        } else {
                            // Deteksi kolom dari header
                            $hdr = array_map(function($x){ return strtolower(trim($x)); }, $data[0]);
                            $colKode     = null;
                            $colNilai    = null;
                            $colKategori = null;
                            $colPoin     = null;
                            foreach ($hdr as $ci => $h) {
                                if ($colKode === null && strpos($h, 'kode_desa') !== false)       $colKode = $ci;
                                if ($colKode === null && $h === 'kode desa')                      $colKode = $ci;
                                if ($colNilai === null && strpos($h, 'nilai keaktifan') !== false) $colNilai = $ci;
                                if ($colKategori === null && strpos($h, 'kategori') !== false)     $colKategori = $ci;
                                if ($colPoin === null && strpos($h, 'total_poin') !== false)       $colPoin = $ci;
                                if ($colPoin === null && strpos($h, 'total poin') !== false)       $colPoin = $ci;
                            }

                            if ($colKode === null || $colNilai === null) {
                                $msg = 'Kolom "kode_desa" atau "Nilai Keaktifan" tidak ditemukan di header XLSX.'
                                     . ' Header terbaca: ' . h(implode(', ', $data[0]));
                                $msgType = 'error';
                            } else {
                                $ok = 0; $luar = 0; $skip = 0;
                                $stmt = $mysqli->prepare(
                                    "INSERT INTO gradebook_keaktifan (kode_desa, nilai, kategori, total_poin)
                                     VALUES (?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE nilai=VALUES(nilai), kategori=VALUES(kategori),
                                                             total_poin=VALUES(total_poin), uploaded_at=NOW()");

                                for ($i = 1; $i < count($data); $i++) {
                                    $row = $data[$i];
                                    $kd = preg_replace('/\D/', '', (string)($row[$colKode] ?? ''));
                                    if (strlen($kd) < 8) { $skip++; continue; }
                                    $kd = str_pad($kd, 10, '0', STR_PAD_LEFT);

                                    if (!isset($listDesa[$kd])) { $luar++; continue; }

                                    $nilaiRaw = (string)($row[$colNilai] ?? '');
                                    $nilaiVal = is_numeric($nilaiRaw) ? (float)$nilaiRaw : 0;
                                    $kat      = $colKategori !== null ? trim((string)($row[$colKategori] ?? '')) : '';
                                    $poin     = $colPoin !== null ? (int)($row[$colPoin] ?? 0) : 0;

                                    $stmt->bind_param('sdsi', $kd, $nilaiVal, $kat, $poin);
                                    $stmt->execute();
                                    $ok++;
                                }
                                $stmt->close();

                                $msg = "Nilai Keaktifan: <b>$ok</b> desa tersimpan.";
                                if ($luar) $msg .= " ($luar kode di luar daftar peserta diabaikan.)";
                                if ($skip) $msg .= " ($skip baris tanpa kode valid dilewati.)";
                                $msgType = $luar ? 'warn' : 'ok';
                            }
                        }
                    } catch (Exception $ex) {
                        $msg = 'Gagal memproses XLSX: ' . h($ex->getMessage());
                        $msgType = 'error';
                    }
                }
            }
        }

        // ---------- RESET DATA ----------
        elseif ($aksi === 'reset') {
            $target = $_POST['target'] ?? '';
            $map = [
                'nilai'     => 'gradebook_nilai',
                'hadir'     => 'gradebook_hadir',
                'tugas'     => 'gradebook_tugas',
                'keaktifan' => 'gradebook_keaktifan',
            ];
            if (isset($map[$target])) {
                $mysqli->query("TRUNCATE TABLE {$map[$target]}");
                $msg = "Data " . h($target) . " berhasil dikosongkan.";
                $msgType = 'ok';
            }
        }
    }
}

require_once __DIR__ . '/gradebook_view.php';

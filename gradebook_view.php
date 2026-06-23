<?php
/**
 * gradebook_view.php — bagian tampilan & export Grade Book.
 * Di-include oleh gradebook.php (variabel $HARI, $TUGAS, $msg, $msgType, $mysqli tersedia).
 */

// =====================================================
// KUMPULKAN DATA REKAP
// =====================================================
list($listDesa, , ) = load_desa($mysqli);

// nilai[kode][hari][jenis] = nilai
$nilai = [];
$res = $mysqli->query("SELECT kode_desa,hari,jenis,nilai FROM gradebook_nilai");
while ($r = $res->fetch_assoc()) {
    $nilai[$r['kode_desa']][$r['hari']][$r['jenis']] = $r['nilai'];
}
// hadir[kode][hari] = 1
$hadir = [];
$res = $mysqli->query("SELECT kode_desa,hari FROM gradebook_hadir WHERE hadir=1");
while ($r = $res->fetch_assoc()) {
    $hadir[$r['kode_desa']][$r['hari']] = 1;
}
// tugas[kode][no] = 1
$tugas = [];
$res = $mysqli->query("SELECT kode_desa,tugas_no FROM gradebook_tugas WHERE kumpul=1");
while ($r = $res->fetch_assoc()) {
    $tugas[$r['kode_desa']][(int)$r['tugas_no']] = 1;
}
// keaktifan[kode] = ['nilai'=>..,'kategori'=>..,'total_poin'=>..]
$keaktifan = [];
$res = $mysqli->query("SELECT kode_desa, nilai, kategori, total_poin FROM gradebook_keaktifan");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $keaktifan[$r['kode_desa']] = [
            'nilai'      => $r['nilai'],
            'kategori'   => $r['kategori'],
            'total_poin' => $r['total_poin'],
        ];
    }
}

// Urutkan desa: provinsi, kabupaten, kecamatan, nama
$desaSorted = array_values($listDesa);
usort($desaSorted, function($a, $b) {
    return [$a['provinsi'],$a['kabupaten'],$a['kecamatan'],$a['nama_desa']]
       <=> [$b['provinsi'],$b['kabupaten'],$b['kecamatan'],$b['nama_desa']];
});

// =====================================================
// EXPORT EXCEL (.xls — HTML table, dibaca Excel/LibreOffice)
// =====================================================
if (isset($_GET['export'])) {
    $fname = 'grade_book_brilian2026_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    // Judul — colspan: 6 info + 1 kickoff + 7×3 day + 4 tugas + 1 keaktifan + 1 ket = 34
    $totalCol = 6 + 1 + (7 * 3) + 4 + 1 + 1;
    echo '<tr><th colspan="' . $totalCol . '" style="background:#0a1a33;color:#fff;font-size:14px">'
       . 'DATA KEHADIRAN + TUGAS + KEAKTIFAN PESERTA DESA BRILIAN 2026 — BATCH 1</th></tr>';
    // Header baris 1
    echo '<tr>';
    echo '<th rowspan="2">No</th><th rowspan="2">Provinsi</th><th rowspan="2">Kabupaten</th>'
       . '<th rowspan="2">Kecamatan</th><th rowspan="2">Nama Desa</th><th rowspan="2">Kode Desa</th>';
    foreach ($HARI as $hk => $hv) {
        if (!$hv['test']) {
            echo '<th rowspan="2">' . h(strtoupper($hv['label'])) . '<br>' . h($hv['tgl']) . '</th>';
        } else {
            echo '<th colspan="3">' . h(strtoupper($hv['label'])) . ' (' . h($hv['tgl']) . ')</th>';
        }
    }
    echo '<th colspan="4">TUGAS</th>';
    echo '<th rowspan="2">KEAKTIFAN</th>';
    echo '<th rowspan="2">Keterangan</th>';
    echo '</tr>';
    // Header baris 2
    echo '<tr>';
    foreach ($HARI as $hk => $hv) {
        if ($hv['test']) echo '<th>KEHADIRAN</th><th>PRE TEST</th><th>POST TEST</th>';
    }
    foreach ($TUGAS as $tn => $tl) echo '<th>' . $tn . '</th>';
    echo '</tr>';
    // Data
    $no = 0;
    foreach ($desaSorted as $d) {
        $no++;
        $k = $d['kode'];
        echo '<tr>';
        echo '<td>' . $no . '</td>';
        echo '<td>' . h($d['provinsi']) . '</td>';
        echo '<td>' . h($d['kabupaten']) . '</td>';
        echo '<td>' . h($d['kecamatan']) . '</td>';
        echo '<td>' . h($d['nama_desa']) . '</td>';
        echo '<td>="' . h($k) . '"</td>';
        foreach ($HARI as $hk => $hv) {
            $hd = isset($hadir[$k][$hk]) ? 'HADIR' : '';
            if (!$hv['test']) {
                echo '<td>' . $hd . '</td>';
            } else {
                $pre  = isset($nilai[$k][$hk]['pretest'])  ? rtrim(rtrim($nilai[$k][$hk]['pretest'],'0'),'.')  : '';
                $post = isset($nilai[$k][$hk]['posttest']) ? rtrim(rtrim($nilai[$k][$hk]['posttest'],'0'),'.') : '';
                echo '<td>' . $hd . '</td><td>' . h($pre) . '</td><td>' . h($post) . '</td>';
            }
        }
        foreach ($TUGAS as $tn => $tl) {
            echo '<td>' . (isset($tugas[$k][$tn]) ? 'V' : '') . '</td>';
        }
        // Keaktifan (1 kolom: nilai numerik saja)
        if (isset($keaktifan[$k])) {
            echo '<td>' . h(rtrim(rtrim($keaktifan[$k]['nilai'],'0'),'.')) . '</td>';
        } else {
            echo '<td></td>';
        }
        echo '<td></td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// =====================================================
// STATISTIK RINGKAS
// =====================================================
$totalDesa = count($listDesa);
$statHadir = [];
foreach ($HARI as $hk => $hv) {
    $c = 0;
    foreach ($listDesa as $k => $d) if (isset($hadir[$k][$hk])) $c++;
    $statHadir[$hk] = $c;
}
$statTugas = [];
foreach ($TUGAS as $tn => $tl) {
    $c = 0;
    foreach ($listDesa as $k => $d) if (isset($tugas[$k][$tn])) $c++;
    $statTugas[$tn] = $c;
}
$statPre = []; $statPost = [];
foreach ($HARI as $hk => $hv) {
    if (!$hv['test']) continue;
    $cp = 0; $cq = 0;
    foreach ($listDesa as $k => $d) {
        if (isset($nilai[$k][$hk]['pretest']))  $cp++;
        if (isset($nilai[$k][$hk]['posttest'])) $cq++;
    }
    $statPre[$hk]  = $cp;
    $statPost[$hk] = $cq;
}
$totalPre  = array_sum($statPre);
$totalPost = array_sum($statPost);

// Statistik keaktifan
$totalKeaktifan = count($keaktifan);
$statKat = ['Sangat Aktif' => 0, 'Aktif' => 0, 'Sedikit Aktif' => 0, 'Kurang Aktif' => 0];
$sumNilaiKA = 0;
foreach ($keaktifan as $ka) {
    $kat = $ka['kategori'];
    if (isset($statKat[$kat])) $statKat[$kat]++;
    $sumNilaiKA += (float)$ka['nilai'];
}
$avgNilaiKA = $totalKeaktifan > 0 ? round($sumNilaiKA / $totalKeaktifan, 1) : 0;

// Filter tampilan tabel
$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$pp   = 50;

$filtered = $desaSorted;
if ($q !== '') {
    $ql = strtolower($q);
    $filtered = array_values(array_filter($desaSorted, function($d) use ($ql) {
        return strpos(strtolower($d['nama_desa']), $ql) !== false
            || strpos(strtolower($d['kabupaten']), $ql) !== false
            || strpos(strtolower($d['provinsi']), $ql) !== false
            || strpos($d['kode'], $ql) !== false;
    }));
}
$totalRow = count($filtered);
$totalPage = max(1, (int)ceil($totalRow / $pp));
$page = min($page, $totalPage);
$pageRows = array_slice($filtered, ($page - 1) * $pp, $pp);

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Grade Book — Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
  body { background:#eef1f6; }
  .gb-wrap { max-width:1200px; margin:0 auto; padding:18px 14px 40px; }
  .gb-h1 { font-size:20px; font-weight:800; color:#0a1a33; margin:18px 0 4px; }
  .gb-sub { color:#64748b; font-size:13px; margin-bottom:16px; }

  .gb-groups { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
    gap:13px; margin-bottom:22px; }
  .gb-group { background:#fff; border:1px solid #e3e8f0; border-radius:13px;
    overflow:hidden; box-shadow:0 1px 3px rgba(15,28,51,.05); }
  .gb-group-head { display:flex; align-items:center; gap:8px; padding:10px 14px;
    font-size:13px; font-weight:800; color:#fff; letter-spacing:.3px; }
  .gb-group-head .ic { font-size:16px; }
  .gb-group-head .tot { margin-left:auto; font-size:12px; font-weight:700;
    background:rgba(255,255,255,.22); padding:2px 9px; border-radius:20px; }
  .gb-group-body { padding:10px 12px 12px; display:grid; gap:6px; }
  .gb-row { display:flex; align-items:center; justify-content:space-between;
    padding:6px 9px; background:#f6f8fb; border-radius:8px; }
  .gb-row .rl { font-size:12px; color:#475569; font-weight:600; }
  .gb-row .rl small { color:#94a3b8; font-weight:500; }
  .gb-row .rn { font-size:15px; font-weight:800; color:#13294f; }
  .gb-row .rn small { font-size:11px; color:#94a3b8; font-weight:600; }
  /* warna per kelompok */
  .grp-kickoff   .gb-group-head { background:linear-gradient(135deg,#b8860b,#e0a800); }
  .grp-hadir     .gb-group-head { background:linear-gradient(135deg,#0a1a33,#1c3b6e); }
  .grp-pre       .gb-group-head { background:linear-gradient(135deg,#1d4ed8,#3b82f6); }
  .grp-post      .gb-group-head { background:linear-gradient(135deg,#047857,#10b981); }
  .grp-tugas     .gb-group-head { background:linear-gradient(135deg,#7c2d12,#c2410c); }
  .grp-keaktifan .gb-group-head { background:linear-gradient(135deg,#7e22ce,#a855f7); }
  .gb-big { display:flex; align-items:center; gap:14px; background:#fff;
    border:1px solid #e3e8f0; border-radius:13px; padding:14px 18px; margin-bottom:13px; }
  .gb-big .num { font-size:30px; font-weight:800; color:#13294f; }
  .gb-big .txt { font-size:13px; color:#64748b; font-weight:600; }

  .gb-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(270px,1fr));
    gap:14px; margin-bottom:22px; }
  .gb-card { background:#fff; border:1px solid #e3e8f0; border-radius:13px;
    padding:16px 16px 18px; box-shadow:0 1px 3px rgba(15,28,51,.05); }
  .gb-card h3 { margin:0 0 3px; font-size:15px; color:#0a1a33;
    display:flex; align-items:center; gap:8px; }
  .gb-card .hint { font-size:12px; color:#94a3b8; margin-bottom:12px; }
  .gb-card label { display:block; font-size:12px; font-weight:600;
    color:#475569; margin:9px 0 4px; }
  .gb-card select, .gb-card input[type=file] { width:100%; padding:8px 9px;
    border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#fff; }
  .gb-card input[type=file] { padding:6px; }
  .gb-btn { width:100%; margin-top:13px; padding:10px; border:0; border-radius:9px;
    background:linear-gradient(135deg,#13294f,#1c3b6e); color:#fff;
    font-weight:700; font-size:14px; cursor:pointer; }
  .gb-btn:hover { filter:brightness(1.12); }
  .gb-btn.gold { background:linear-gradient(135deg,#d99e00,#f2b800); color:#3a2c00; }
  .gb-btn.purple { background:linear-gradient(135deg,#7e22ce,#a855f7); color:#fff; }
  .gb-btn.red  { background:#dc2626; }

  .gb-msg { padding:11px 14px; border-radius:9px; margin-bottom:16px; font-size:13px; }
  .gb-msg.ok    { background:#dcfce7; color:#166534; border:1px solid #86efac; }
  .gb-msg.warn  { background:#fef3c7; color:#78350f; border:1px solid #fcd34d; }
  .gb-msg.error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

  .gb-toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center;
    margin-bottom:12px; }
  .gb-toolbar input[type=text] { flex:1; min-width:160px; padding:9px 11px;
    border:1px solid #cbd5e1; border-radius:8px; font-size:13px; }
  .gb-toolbar button, .gb-toolbar a.btn { padding:9px 14px; border-radius:8px;
    border:1px solid #cbd5e1; background:#fff; font-size:13px; font-weight:600;
    color:#374151; text-decoration:none; cursor:pointer; }
  .gb-toolbar a.btn.export { background:#16a34a; color:#fff; border-color:#16a34a; }

  .gb-tablewrap { overflow-x:auto; background:#fff; border:1px solid #e3e8f0;
    border-radius:11px; }
  table.gb { border-collapse:collapse; width:100%; font-size:12px; white-space:nowrap; }
  table.gb th, table.gb td { border:1px solid #e5e9f0; padding:5px 7px; text-align:center; }
  table.gb thead th { background:#13294f; color:#fff; font-weight:700;
    position:sticky; top:0; }
  table.gb thead tr.sub th { background:#24437a; font-size:11px; }
  table.gb td.l { text-align:left; }
  table.gb tbody tr:nth-child(even) { background:#f6f8fb; }
  .yes { color:#15803d; font-weight:800; }
  .no  { color:#cbd5e1; }
  .nv  { font-weight:700; color:#1d4ed8; }
  .day-kickoff { background:#fffbe6 !important; }
  .pager { display:flex; gap:5px; flex-wrap:wrap; margin-top:14px; justify-content:center; }
  .pager a { padding:6px 11px; border:1px solid #cbd5e1; border-radius:7px;
    text-decoration:none; color:#374151; font-size:13px; background:#fff; }
  .pager a.active { background:#13294f; color:#fff; border-color:#13294f; }

  /* Badge kategori keaktifan */
  .kat-badge { display:inline-block; font-size:10px; font-weight:700; padding:2px 7px;
    border-radius:10px; line-height:1.4; white-space:nowrap; }
  .kat-sa  { background:#dcfce7; color:#166534; }
  .kat-a   { background:#dbeafe; color:#1e40af; }
  .kat-sed { background:#fef3c7; color:#92400e; }
  .kat-ka  { background:#fee2e2; color:#991b1b; }
  .ka-val  { font-weight:800; color:#7e22ce; }
</style>
</head>
<body>

<?php $ADMIN_NAV_ACTIVE='gradebook'; $ADMIN_NAV_BASE=''; require __DIR__.'/_admin_nav.php'; ?>

<div class="gb-wrap">

  <div class="gb-h1">📊 Grade Book — Brilian 2026</div>
  <div class="gb-sub">Rekap kehadiran, nilai pre/post-test, pengumpulan 4 tugas, dan keaktifan untuk <?= $totalDesa ?> desa peserta.</div>

  <?php if ($msg): ?>
    <div class="gb-msg <?= h($msgType ?: 'ok') ?>"><?= $msg /* sudah di-escape per bagian */ ?></div>
  <?php endif; ?>

  <!-- ====== STATISTIK BERKELOMPOK ====== -->
  <div class="gb-big">
    <div class="num"><?= $totalDesa ?></div>
    <div class="txt">Total Desa Peserta Brilian 2026</div>
  </div>

  <div class="gb-groups">

    <!-- Kelompok: Kick Off -->
    <div class="gb-group grp-kickoff">
      <div class="gb-group-head"><span class="ic">🎯</span> Kick Off
        <span class="tot"><?= $statHadir['kickoff'] ?>/<?= $totalDesa ?></span></div>
      <div class="gb-group-body">
        <div class="gb-row">
          <span class="rl">Hadir <small><?= h($HARI['kickoff']['tgl']) ?></small></span>
          <span class="rn"><?= $statHadir['kickoff'] ?></span>
        </div>
      </div>
    </div>

    <!-- Kelompok: Kehadiran Pelatihan -->
    <div class="gb-group grp-hadir">
      <div class="gb-group-head"><span class="ic">📋</span> Kehadiran Pelatihan</div>
      <div class="gb-group-body">
        <?php foreach ($HARI as $hk => $hv): if (!$hv['test']) continue; ?>
          <div class="gb-row">
            <span class="rl"><?= h($hv['label']) ?> <small><?= h($hv['tgl']) ?></small></span>
            <span class="rn"><?= $statHadir[$hk] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Kelompok: Pre-Test -->
    <div class="gb-group grp-pre">
      <div class="gb-group-head"><span class="ic">📝</span> Nilai Pre-Test
        <span class="tot"><?= $totalPre ?> masuk</span></div>
      <div class="gb-group-body">
        <?php foreach ($HARI as $hk => $hv): if (!$hv['test']) continue; ?>
          <div class="gb-row">
            <span class="rl"><?= h($hv['label']) ?> <small><?= h($hv['tgl']) ?></small></span>
            <span class="rn"><?= $statPre[$hk] ?><small> nilai</small></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Kelompok: Post-Test -->
    <div class="gb-group grp-post">
      <div class="gb-group-head"><span class="ic">✅</span> Nilai Post-Test
        <span class="tot"><?= $totalPost ?> masuk</span></div>
      <div class="gb-group-body">
        <?php foreach ($HARI as $hk => $hv): if (!$hv['test']) continue; ?>
          <div class="gb-row">
            <span class="rl"><?= h($hv['label']) ?> <small><?= h($hv['tgl']) ?></small></span>
            <span class="rn"><?= $statPost[$hk] ?><small> nilai</small></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Kelompok: 4 Tugas -->
    <div class="gb-group grp-tugas">
      <div class="gb-group-head"><span class="ic">📂</span> Pengumpulan Tugas</div>
      <div class="gb-group-body">
        <?php foreach ($TUGAS as $tn => $tl): ?>
          <div class="gb-row">
            <span class="rl">Tugas <?= $tn ?> <small><?= h($tl) ?></small></span>
            <span class="rn"><?= $statTugas[$tn] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Kelompok: Keaktifan -->
    <div class="gb-group grp-keaktifan">
      <div class="gb-group-head"><span class="ic">⚡</span> Nilai Keaktifan
        <span class="tot"><?= $totalKeaktifan ?>/<?= $totalDesa ?></span></div>
      <div class="gb-group-body">
        <div class="gb-row">
          <span class="rl">Rata-rata Nilai</span>
          <span class="rn"><?= $avgNilaiKA ?></span>
        </div>
        <?php foreach ($statKat as $katLabel => $katCount): ?>
          <div class="gb-row">
            <span class="rl"><?= h($katLabel) ?></span>
            <span class="rn"><?= $katCount ?><small> desa</small></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- ====== KARTU UPLOAD ====== -->
  <div class="gb-cards">

    <!-- Pre/Post Test -->
    <div class="gb-card">
      <h3>📝 Upload Pre / Post-Test</h3>
      <div class="hint">File CSV "Grades" hasil export Moodle.</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="upload_test">
        <label>Jenis Test</label>
        <select name="jenis" required>
          <option value="pretest">Pre-Test</option>
          <option value="posttest">Post-Test</option>
        </select>
        <label>Hari Sesi</label>
        <select name="hari" required>
          <?php foreach ($HARI as $hk => $hv): if (!$hv['test']) continue; ?>
            <option value="<?= h($hk) ?>"><?= h($hv['label'].' — '.$hv['tgl']) ?></option>
          <?php endforeach; ?>
        </select>
        <label>File CSV</label>
        <input type="file" name="file" accept=".csv" required>
        <button class="gb-btn">Unggah Nilai Test</button>
      </form>
    </div>

    <!-- Kehadiran -->
    <div class="gb-card">
      <h3>📋 Upload Kehadiran</h3>
      <div class="hint">CSV presensi atau XLSX kickoff. Hanya tandai hadir/tidak.</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="upload_hadir">
        <label>Hari Sesi</label>
        <select name="hari" required>
          <?php foreach ($HARI as $hk => $hv): ?>
            <option value="<?= h($hk) ?>"><?= h($hv['label'].' — '.$hv['tgl']) ?></option>
          <?php endforeach; ?>
        </select>
        <label>File (CSV / XLSX)</label>
        <input type="file" name="file" accept=".csv,.xlsx" required>
        <button class="gb-btn">Unggah Kehadiran</button>
      </form>
    </div>

    <!-- 4 Tugas -->
    <div class="gb-card">
      <h3>📂 Upload Status 4 Tugas</h3>
      <div class="hint">PDF "Submissions" hasil export dari Joglo. Otomatis hitung "Submit for grading".</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="upload_tugas">
        <label>Tugas</label>
        <select name="tugas_no" required>
          <?php foreach ($TUGAS as $tn => $tl): ?>
            <option value="<?= $tn ?>">Tugas <?= $tn ?> — <?= h($tl) ?></option>
          <?php endforeach; ?>
        </select>
        <label>File PDF Submissions</label>
        <input type="file" name="file" accept=".pdf" required>
        <button class="gb-btn">Unggah & Proses PDF</button>
      </form>
    </div>

    <!-- Nilai Keaktifan -->
    <div class="gb-card">
      <h3>⚡ Upload Nilai Keaktifan</h3>
      <div class="hint">File XLSX nilai keaktifan akhir (1 nilai per desa). Kolom: kode_desa, Nilai Keaktifan, Kategori, Total_Poin.</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="upload_keaktifan">
        <label>File XLSX Keaktifan</label>
        <input type="file" name="file" accept=".xlsx" required>
        <button class="gb-btn purple">Unggah Nilai Keaktifan</button>
      </form>
    </div>

  </div>

  <!-- ====== TOOLBAR REKAP ====== -->
  <form class="gb-toolbar" method="get">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari kode / desa / kabupaten / provinsi…">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn" href="gradebook.php">Reset</a><?php endif; ?>
    <a class="btn export" href="?export=1<?= $q ? '&q='.urlencode($q) : '' ?>">⬇ Export Excel</a>
  </form>

  <!-- ====== TABEL REKAP ====== -->
  <div class="gb-tablewrap">
    <table class="gb">
      <thead>
        <tr>
          <th rowspan="2">No</th>
          <th rowspan="2">Kode Desa</th>
          <th rowspan="2">Nama Desa</th>
          <th rowspan="2">Kecamatan</th>
          <th rowspan="2">Kabupaten</th>
          <?php foreach ($HARI as $hk => $hv): ?>
            <?php if (!$hv['test']): ?>
              <th rowspan="2" class="day-kickoff"><?= h($hv['label']) ?><br><small><?= h($hv['tgl']) ?></small></th>
            <?php else: ?>
              <th colspan="3"><?= h($hv['label']) ?><br><small><?= h($hv['tgl']) ?></small></th>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php foreach ($TUGAS as $tn => $tl): ?>
            <th rowspan="2" title="<?= h($tl) ?>">T<?= $tn ?></th>
          <?php endforeach; ?>
          <th colspan="2" style="background:linear-gradient(135deg,#7e22ce,#a855f7)">Keaktifan</th>
        </tr>
        <tr class="sub">
          <?php foreach ($HARI as $hk => $hv): if (!$hv['test']) continue; ?>
            <th>Hadir</th><th>Pre</th><th>Post</th>
          <?php endforeach; ?>
          <th style="background:#6b21a8">Nilai</th><th style="background:#6b21a8">Kategori</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($pageRows)): ?>
        <tr><td colspan="34">Tidak ada data.</td></tr>
      <?php else: $no = ($page - 1) * $pp; foreach ($pageRows as $d): $no++; $k = $d['kode']; ?>
        <tr>
          <td><?= $no ?></td>
          <td class="mono"><?= h($k) ?></td>
          <td class="l"><b><?= h($d['nama_desa']) ?></b></td>
          <td class="l"><?= h($d['kecamatan']) ?></td>
          <td class="l"><?= h($d['kabupaten']) ?></td>
          <?php foreach ($HARI as $hk => $hv): ?>
            <?php $hd = isset($hadir[$k][$hk]); ?>
            <?php if (!$hv['test']): ?>
              <td class="day-kickoff"><?= $hd ? '<span class="yes">✓</span>' : '<span class="no">–</span>' ?></td>
            <?php else: ?>
              <td><?= $hd ? '<span class="yes">✓</span>' : '<span class="no">–</span>' ?></td>
              <?php
                $pre  = isset($nilai[$k][$hk]['pretest'])  ? rtrim(rtrim($nilai[$k][$hk]['pretest'],'0'),'.')  : null;
                $post = isset($nilai[$k][$hk]['posttest']) ? rtrim(rtrim($nilai[$k][$hk]['posttest'],'0'),'.') : null;
              ?>
              <td><?= $pre  !== null ? '<span class="nv">'.h($pre).'</span>'  : '<span class="no">–</span>' ?></td>
              <td><?= $post !== null ? '<span class="nv">'.h($post).'</span>' : '<span class="no">–</span>' ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php foreach ($TUGAS as $tn => $tl): ?>
            <td><?= isset($tugas[$k][$tn]) ? '<span class="yes">✓</span>' : '<span class="no">–</span>' ?></td>
          <?php endforeach; ?>
          <?php /* Kolom Keaktifan */ ?>
          <?php if (isset($keaktifan[$k])):
            $ka = $keaktifan[$k];
            $katClass = 'kat-ka';
            if ($ka['kategori'] === 'Sangat Aktif')  $katClass = 'kat-sa';
            elseif ($ka['kategori'] === 'Aktif')     $katClass = 'kat-a';
            elseif ($ka['kategori'] === 'Sedikit Aktif') $katClass = 'kat-sed';
          ?>
            <td><span class="ka-val"><?= h(rtrim(rtrim($ka['nilai'],'0'),'.')) ?></span></td>
            <td><span class="kat-badge <?= $katClass ?>"><?= h($ka['kategori']) ?></span></td>
          <?php else: ?>
            <td><span class="no">–</span></td>
            <td><span class="no">–</span></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPage > 1): ?>
  <nav class="pager">
    <?php for ($i = 1; $i <= $totalPage; $i++):
      $u = '?page='.$i.($q ? '&q='.urlencode($q) : ''); ?>
      <a class="<?= $i === $page ? 'active' : '' ?>" href="<?= h($u) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>

  <!-- ====== RESET DATA ====== -->
  <div style="margin-top:26px;padding-top:16px;border-top:1px dashed #cbd5e1">
    <div style="font-size:12px;color:#94a3b8;margin-bottom:8px">Zona reset — kosongkan data jika salah unggah:</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach (['nilai'=>'Nilai Test','hadir'=>'Kehadiran','tugas'=>'Status Tugas','keaktifan'=>'Keaktifan'] as $tg => $lbl): ?>
        <form method="post" onsubmit="return confirm('Yakin kosongkan data <?= h($lbl) ?>? Tidak bisa dibatalkan.');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="aksi" value="reset">
          <input type="hidden" name="target" value="<?= h($tg) ?>">
          <button class="gb-btn red" style="width:auto;padding:7px 13px;font-size:12px;margin:0">
            Reset <?= h($lbl) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

</div>
</body>
</html>

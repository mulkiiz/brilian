<?php
/**
 * =====================================================
 * KLAIM PRESENSI MANUAL — Brilian 2026
 * =====================================================
 * Alur:
 *  1. Cari nama desa
 *  2. Pilih desa -> tombol "Klaim"
 *  3. Form checkbox tanggal sesi; tanggal yang sudah
 *     terisi presensi -> disabled
 *  4. Simpan: tulis ke presensi_desa DAN gradebook_hadir
 *
 * Stack: PHP 7.3 + mysqli. Auth: reuse sesi admin.php
 * =====================================================
 */
require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

// 7 hari pelatihan: tanggal => kode hari gradebook
$SESI = [
    '2026-05-12' => ['hari' => 'day1', 'label' => 'Day 1'],
    '2026-05-13' => ['hari' => 'day2', 'label' => 'Day 2'],
    '2026-05-19' => ['hari' => 'day3', 'label' => 'Day 3'],
    '2026-05-20' => ['hari' => 'day4', 'label' => 'Day 4'],
    '2026-05-21' => ['hari' => 'day5', 'label' => 'Day 5'],
    '2026-05-25' => ['hari' => 'day6', 'label' => 'Day 6'],
    '2026-05-26' => ['hari' => 'day7', 'label' => 'Day 7'],
];

// Format tanggal Indonesia
function tgl_id($ymd) {
    $bln = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',
            7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
    $t = explode('-', $ymd);
    return (int)$t[2] . ' ' . $bln[(int)$t[1]] . ' ' . $t[0];
}

$msg = ''; $msgType = '';
$desaTerpilih = null;       // desa yg sedang diklaim
$sudahSesi    = [];         // tanggal yg sudah ada presensi utk desa terpilih

// =====================================================
// PROSES SIMPAN KLAIM
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_klaim') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = 'Sesi tidak valid (CSRF). Muat ulang halaman.'; $msgType = 'error';
    } else {
        $kode = preg_replace('/\D/', '', $_POST['kode_desa'] ?? '');
        $tglDipilih = $_POST['tanggal'] ?? [];
        if (!is_array($tglDipilih)) $tglDipilih = [];

        // Ambil identitas desa dari desa_peserta
        $stmt = $mysqli->prepare(
            "SELECT kode_desa, firstname, lastname, city, department, institution
             FROM desa_peserta WHERE kode_desa = ?");
        $stmt->bind_param('s', $kode);
        $stmt->execute();
        $d = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$d) {
            $msg = 'Desa tidak ditemukan.'; $msgType = 'error';
        } elseif (empty($tglDipilih)) {
            $msg = 'Belum ada tanggal yang dipilih.'; $msgType = 'error';
        } else {
            $namaDesa = preg_replace('/^Desa\s+/i',   '', trim($d['firstname']));
            $kec      = preg_replace('/^Kec\.?\s+/i', '', trim($d['lastname']));
            if ($kec === '') $kec = $d['department'];

            // Cek tanggal yang sudah ada presensi (tidak boleh ditimpa)
            $stmt = $mysqli->prepare(
                "SELECT tanggal_sesi FROM presensi_desa WHERE kode_desa = ?");
            $stmt->bind_param('s', $kode);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = [];
            while ($r = $res->fetch_assoc()) $existing[$r['tanggal_sesi']] = true;
            $stmt->close();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ok = 0; $lewat = 0;

            $stP = $mysqli->prepare(
                "INSERT INTO presensi_desa
                   (kode_desa,nama_desa,kecamatan,kabupaten,provinsi,
                    tanggal_sesi,status,selisih_detik,ip_address,user_agent)
                 VALUES (?,?,?,?,?,?,'ontime',0,?, 'KLAIM MANUAL')");
            $stG = $mysqli->prepare(
                "INSERT INTO gradebook_hadir (kode_desa,hari,hadir) VALUES (?,?,1)
                 ON DUPLICATE KEY UPDATE hadir=1, uploaded_at=NOW()");

            foreach ($tglDipilih as $tgl) {
                if (!isset($SESI[$tgl])) continue;
                if (isset($existing[$tgl])) { $lewat++; continue; } // sudah ada, skip
                // 1) tulis ke presensi_desa
                $stP->bind_param('sssssss', $kode, $namaDesa, $kec,
                                 $d['city'], $d['institution'], $tgl, $ip);
                $stP->execute();
                // 2) tulis ke gradebook_hadir
                $hari = $SESI[$tgl]['hari'];
                $stG->bind_param('ss', $kode, $hari);
                $stG->execute();
                $ok++;
            }
            $stP->close();
            $stG->close();

            $msg = "Klaim presensi <b>Desa " . h($namaDesa) . "</b> berhasil: "
                 . "<b>$ok</b> tanggal direkam (Presensi + Grade Book).";
            if ($lewat) $msg .= " $lewat tanggal dilewati karena sudah terisi.";
            $msgType = 'ok';
        }
    }
}

// =====================================================
// PENCARIAN DESA
// =====================================================
$q = trim($_GET['q'] ?? '');
$hasilCari = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $mysqli->prepare(
        "SELECT kode_desa, firstname, lastname, city, institution
         FROM desa_peserta
         WHERE kode_desa LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR city LIKE ?
         ORDER BY institution, city, firstname
         LIMIT 60");
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $hasilCari[] = [
            'kode'      => $r['kode_desa'],
            'nama_desa' => preg_replace('/^Desa\s+/i',   '', trim($r['firstname'])),
            'kecamatan' => preg_replace('/^Kec\.?\s+/i', '', trim($r['lastname'])),
            'kabupaten' => $r['city'],
            'provinsi'  => $r['institution'],
        ];
    }
    $stmt->close();
}

// =====================================================
// DESA TERPILIH (klik tombol "Klaim")
// =====================================================
$kodePilih = preg_replace('/\D/', '', $_GET['klaim'] ?? '');
if ($kodePilih !== '') {
    $stmt = $mysqli->prepare(
        "SELECT kode_desa, firstname, lastname, city, institution
         FROM desa_peserta WHERE kode_desa = ?");
    $stmt->bind_param('s', $kodePilih);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) {
        $desaTerpilih = [
            'kode'      => $r['kode_desa'],
            'nama_desa' => preg_replace('/^Desa\s+/i',   '', trim($r['firstname'])),
            'kecamatan' => preg_replace('/^Kec\.?\s+/i', '', trim($r['lastname'])),
            'kabupaten' => $r['city'],
            'provinsi'  => $r['institution'],
        ];
        // tanggal yg sudah terisi presensi
        $stmt = $mysqli->prepare(
            "SELECT tanggal_sesi FROM presensi_desa WHERE kode_desa = ?");
        $stmt->bind_param('s', $kodePilih);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($x = $res->fetch_assoc()) $sudahSesi[$x['tanggal_sesi']] = true;
        $stmt->close();
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Klaim Presensi Manual — Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=7">
<style>
  body { background:#eef1f6; }
  .km-wrap { max-width:840px; margin:0 auto; padding:18px 14px 50px; }
  .km-h1 { font-size:20px; font-weight:800; color:#0a1a33; margin:16px 0 4px; }
  .km-sub { color:#64748b; font-size:13px; margin-bottom:18px; }

  .km-card { background:#fff; border:1px solid #e3e8f0; border-radius:13px;
    padding:18px; margin-bottom:16px; box-shadow:0 1px 3px rgba(15,28,51,.05); }
  .km-card h3 { margin:0 0 12px; font-size:15px; color:#0a1a33;
    display:flex; align-items:center; gap:8px; }

  .km-search { display:flex; gap:8px; flex-wrap:wrap; }
  .km-search input[type=text] { flex:1; min-width:180px; padding:10px 12px;
    border:1px solid #cbd5e1; border-radius:9px; font-size:14px; }
  .km-btn { padding:10px 18px; border:0; border-radius:9px;
    background:linear-gradient(135deg,#13294f,#1c3b6e); color:#fff;
    font-weight:700; font-size:14px; cursor:pointer; text-decoration:none;
    display:inline-block; }
  .km-btn:hover { filter:brightness(1.13); }
  .km-btn.gold { background:linear-gradient(135deg,#d99e00,#f2b800); color:#3a2c00; }
  .km-btn.sm { padding:6px 14px; font-size:13px; }

  table.km { border-collapse:collapse; width:100%; font-size:13px; margin-top:4px; }
  table.km th, table.km td { border:1px solid #e5e9f0; padding:7px 9px; text-align:left; }
  table.km thead th { background:#13294f; color:#fff; font-weight:700; }
  table.km tbody tr:nth-child(even) { background:#f6f8fb; }
  table.km td.act { text-align:center; white-space:nowrap; }

  .km-msg { padding:11px 14px; border-radius:9px; margin-bottom:16px; font-size:13px; }
  .km-msg.ok    { background:#dcfce7; color:#166534; border:1px solid #86efac; }
  .km-msg.error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

  .km-desa-info { background:#f0f5ff; border:1px solid #c7d8f5; border-radius:10px;
    padding:12px 14px; margin-bottom:16px; font-size:13px; }
  .km-desa-info b { color:#13294f; font-size:15px; }

  .km-dates { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:10px; margin:6px 0 16px; }
  .km-date { display:flex; align-items:center; gap:10px; padding:11px 13px;
    border:1.5px solid #cbd5e1; border-radius:10px; cursor:pointer;
    transition:all .12s; background:#fff; }
  .km-date:hover { border-color:#1c3b6e; background:#f6f9ff; }
  .km-date input { width:18px; height:18px; cursor:pointer; accent-color:#13294f; }
  .km-date .dt { font-weight:700; color:#0a1a33; font-size:13px; }
  .km-date .dl { font-size:11px; color:#64748b; }
  .km-date.locked { background:#f1f5f9; border-color:#e2e8f0; cursor:not-allowed; }
  .km-date.locked .dt { color:#94a3b8; }
  .km-date.locked .badge { margin-left:auto; font-size:10px; font-weight:700;
    background:#16a34a; color:#fff; padding:2px 8px; border-radius:20px; }
  .km-empty { color:#94a3b8; font-size:13px; padding:14px 0; text-align:center; }
  .km-back { color:#64748b; font-size:13px; text-decoration:none; }
  .km-back:hover { color:#13294f; }
</style>
</head>
<body>

<?php $ADMIN_NAV_ACTIVE='presensi'; $ADMIN_NAV_BASE=''; require __DIR__.'/_admin_nav.php'; ?>

<div class="km-wrap">

  <div class="km-h1">✍️ Klaim Presensi Manual</div>
  <div class="km-sub">
    Rekam kehadiran desa secara manual. Data tersimpan ke halaman Presensi
    <b>dan</b> Grade Book sekaligus.
    &nbsp;<a class="km-back" href="admin_presensi.php">← Kembali ke Admin Presensi</a>
  </div>

  <?php if ($msg): ?>
    <div class="km-msg <?= h($msgType ?: 'ok') ?>"><?= $msg ?></div>
  <?php endif; ?>

  <?php if ($desaTerpilih): ?>
    <!-- ====== STEP 2: FORM CHECKBOX TANGGAL ====== -->
    <div class="km-card">
      <h3>🗓️ Pilih Tanggal Presensi yang Diklaim</h3>
      <div class="km-desa-info">
        <b>Desa <?= h($desaTerpilih['nama_desa']) ?></b>
        &nbsp;·&nbsp; Kode: <?= h($desaTerpilih['kode']) ?><br>
        Kec. <?= h($desaTerpilih['kecamatan']) ?>,
        <?= h($desaTerpilih['kabupaten']) ?>,
        <?= h($desaTerpilih['provinsi']) ?>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="simpan_klaim">
        <input type="hidden" name="kode_desa" value="<?= h($desaTerpilih['kode']) ?>">

        <div class="km-dates">
          <?php foreach ($SESI as $tgl => $info): $locked = isset($sudahSesi[$tgl]); ?>
            <label class="km-date <?= $locked ? 'locked' : '' ?>">
              <input type="checkbox" name="tanggal[]" value="<?= h($tgl) ?>"
                     <?= $locked ? 'disabled' : '' ?>>
              <span>
                <span class="dt"><?= h($info['label']) ?></span><br>
                <span class="dl"><?= h(tgl_id($tgl)) ?></span>
              </span>
              <?php if ($locked): ?><span class="badge">SUDAH HADIR</span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="km-btn">✓ Simpan Klaim Presensi</button>
        <a href="presensi_klaim.php<?= $q !== '' ? '?q='.urlencode($q) : '' ?>"
           class="km-btn gold" style="margin-left:6px">Batal / Cari Desa Lain</a>
      </form>
    </div>
  <?php endif; ?>

  <!-- ====== STEP 1: CARI DESA ====== -->
  <div class="km-card">
    <h3>🔍 Cari Nama Desa</h3>
    <form method="get" class="km-search">
      <input type="text" name="q" value="<?= h($q) ?>"
             placeholder="Ketik nama desa / kode / kecamatan / kabupaten…" autofocus>
      <button type="submit" class="km-btn">Cari</button>
    </form>

    <?php if ($q !== ''): ?>
      <?php if (empty($hasilCari)): ?>
        <div class="km-empty">Tidak ada desa cocok dengan "<?= h($q) ?>".</div>
      <?php else: ?>
        <table class="km">
          <thead>
            <tr><th>Kode Desa</th><th>Nama Desa</th><th>Kecamatan</th>
                <th>Kabupaten</th><th>Aksi</th></tr>
          </thead>
          <tbody>
          <?php foreach ($hasilCari as $d): ?>
            <tr>
              <td class="mono"><?= h($d['kode']) ?></td>
              <td><b><?= h($d['nama_desa']) ?></b></td>
              <td><?= h($d['kecamatan']) ?></td>
              <td><?= h($d['kabupaten']) ?></td>
              <td class="act">
                <a class="km-btn sm" href="?klaim=<?= urlencode($d['kode']) ?>&q=<?= urlencode($q) ?>">Klaim</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="km-sub" style="margin-top:8px">
          Menampilkan <?= count($hasilCari) ?> hasil<?= count($hasilCari) >= 60 ? ' (maks 60, persempit pencarian)' : '' ?>.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
</body>
</html>

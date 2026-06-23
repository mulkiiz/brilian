<?php
require_once __DIR__ . '/config.php';
session_start();

// Reuse auth admin.php — peserta admin harus login dulu via admin.php
if (!isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

$ALLOWED_DATES = [
    '2026-05-12','2026-05-13',
    '2026-05-19','2026-05-20','2026-05-21',
    '2026-05-25','2026-05-26',
];

// --- Export CSV ---
if (isset($_GET['export'])) {
    $mode = $_GET['export'];
    $tgl  = $_GET['tgl'] ?? '';

    if ($mode === 'hadir' && in_array($tgl, $ALLOWED_DATES, true)) {
        $fname = 'presensi_HADIR_' . $tgl . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['No','Kode Desa','Nama Desa','Kecamatan','Kabupaten','Provinsi',
                       'Tanggal Sesi','Waktu Submit (Server)','Status','Selisih (detik)','IP']);
        $stmt = $mysqli->prepare("SELECT * FROM presensi_desa WHERE tanggal_sesi = ? ORDER BY submitted_at ASC");
        $stmt->bind_param('s', $tgl);
        $stmt->execute();
        $res = $stmt->get_result();
        $no = 0;
        while ($r = $res->fetch_assoc()) {
            $no++;
            fputcsv($out, [
                $no,
                $r['kode_desa'], $r['nama_desa'], $r['kecamatan'], $r['kabupaten'], $r['provinsi'],
                $r['tanggal_sesi'], $r['submitted_at'], $r['status'], $r['selisih_detik'], $r['ip_address']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($mode === 'belum' && in_array($tgl, $ALLOWED_DATES, true)) {
        $fname = 'presensi_BELUM_' . $tgl . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['No','Kode Desa','Nama Desa','Kecamatan','Kabupaten','Provinsi']);
        $sql = "SELECT d.kode_desa, d.firstname, d.lastname, d.city, d.institution
                FROM desa_peserta d
                LEFT JOIN presensi_desa p ON p.kode_desa = d.kode_desa AND p.tanggal_sesi = ?
                WHERE p.id IS NULL
                ORDER BY d.institution, d.city, d.lastname, d.firstname";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $tgl);
        $stmt->execute();
        $res = $stmt->get_result();
        $no = 0;
        while ($r = $res->fetch_assoc()) {
            $no++;
            $nama_desa = preg_replace('/^Desa\s+/i',   '', $r['firstname']);
            $kec       = preg_replace('/^Kec\.?\s+/i', '', $r['lastname']);
            fputcsv($out, [$no, $r['kode_desa'], $nama_desa, $kec, $r['city'], $r['institution']]);
        }
        fclose($out);
        exit;
    }

    if ($mode === 'all') {
        $fname = 'presensi_SEMUA_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['No','Kode Desa','Nama Desa','Kecamatan','Kabupaten','Provinsi',
                       'Tanggal Sesi','Waktu Submit (Server)','Status','Selisih (detik)','IP']);
        $res = $mysqli->query("SELECT * FROM presensi_desa ORDER BY tanggal_sesi ASC, submitted_at ASC");
        $no = 0;
        while ($r = $res->fetch_assoc()) {
            $no++;
            fputcsv($out, [
                $no,
                $r['kode_desa'], $r['nama_desa'], $r['kecamatan'], $r['kabupaten'], $r['provinsi'],
                $r['tanggal_sesi'], $r['submitted_at'], $r['status'], $r['selisih_detik'], $r['ip_address']
            ]);
        }
        fclose($out);
        exit;
    }
}

// --- Filter & pagination ---
$q       = trim($_GET['q'] ?? '');
$tgl     = $_GET['tgl'] ?? 'all';
$page    = max(1, (int)($_GET['page'] ?? 1));

// Per-page configurable: 30 / 50 / 100 / 200
$ppAllowed = [30, 50, 100, 200];
$perPage = (int)($_GET['pp'] ?? 30);
if (!in_array($perPage, $ppAllowed, true)) $perPage = 30;

$offset  = ($page - 1) * $perPage;

$cond = []; $params = []; $types = '';
if ($q !== '') {
    $cond[] = "(kode_desa LIKE ? OR nama_desa LIKE ? OR kabupaten LIKE ? OR provinsi LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (in_array($tgl, $ALLOWED_DATES, true)) {
    $cond[] = "tanggal_sesi = ?";
    $params[] = $tgl;
    $types .= 's';
}
$where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';

$stmt = $mysqli->prepare("SELECT COUNT(*) c FROM presensi_desa$where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT * FROM presensi_desa$where ORDER BY submitted_at DESC LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($sql);
if ($params) {
    $args = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types . 'ii', ...$args);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Rekap per tanggal
$rekap = [];
foreach ($ALLOWED_DATES as $d) $rekap[$d] = 0;
$res = $mysqli->query("SELECT tanggal_sesi, COUNT(*) c FROM presensi_desa GROUP BY tanggal_sesi");
while ($r = $res->fetch_assoc()) {
    if (isset($rekap[$r['tanggal_sesi']])) $rekap[$r['tanggal_sesi']] = (int)$r['c'];
}
$totalDesa = (int)$mysqli->query("SELECT COUNT(*) c FROM desa_peserta")->fetch_assoc()['c'];
$totalPresensi = (int)$mysqli->query("SELECT COUNT(*) c FROM presensi_desa")->fetch_assoc()['c'];
?>
<!doctype html><html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Presensi - Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=7">
<style>
  .admin-bar { background:#0f1c33; color:#fff; padding:12px 0; }
  .admin-bar, .admin-bar * { color:#fff; }
  .admin-bar .wrap { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
  .admin-bar a { color:#ffd700 !important; }
  .admin-bar b { color:#fff !important; }
  .actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .actions .btn { padding:9px 14px; background:#fff; border:1px solid #d1d5db; border-radius:8px;
    text-decoration:none; color:#374151; font-size:14px; font-weight:500; }
  .actions .btn.primary { background:#0f766e; color:#fff; border-color:#0f766e; }
  .actions .btn.danger  { background:#dc2626; color:#fff; border-color:#dc2626; }
  .actions .btn.active  { background:#003d7a; color:#fff; border-color:#003d7a; }
  .desa-table th, .desa-table td { font-size:13px; padding:8px 10px; vertical-align:top; }
  .rekap-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; margin:14px 0; }
  .rekap-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px 6px; text-align:center; }
  .rekap-card .rk-day { font-size:22px; font-weight:800; color:#0f766e; line-height:1; }
  .rekap-card .rk-cnt { font-size:14px; color:#0f172a; margin-top:6px; font-weight:600; }
  .rekap-card .rk-lbl { font-size:11px; color:#6b7280; margin-top:2px; }
  .rekap-card a { text-decoration:none; color:inherit; display:block; }
  .rekap-card:hover { border-color:#0f766e; }
  .badge.late  { background:#fed7aa; color:#9a3412; }
  .badge.early { background:#bfdbfe; color:#1e40af; }
  .badge.ontime{ background:#bbf7d0; color:#166534; }

  .pager .dots { padding:6px 8px; color:#9ca3af; }
  .pager a.disabled { opacity:.4; pointer-events:none; }
  .pager-info-bar {
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:10px;
    font-size:13px; color:#374151;
    padding:10px 0; margin-top:6px;
  }
  .pp-select a {
    display:inline-block;
    padding:3px 9px; margin-left:4px;
    border:1px solid #d1d5db; border-radius:6px;
    text-decoration:none; color:#374151; background:#fff;
    font-size:12px;
  }
  .pp-select a.active { background:#003d7a; color:#fff; border-color:#003d7a; }
  .pp-select a:hover:not(.active) { border-color:#003d7a; color:#003d7a; }
  @media (max-width:700px) {
    .rekap-grid { grid-template-columns:repeat(4,1fr); }
  }
</style>
</head><body>

<?php $ADMIN_NAV_ACTIVE='presensi'; $ADMIN_NAV_BASE=''; require __DIR__.'/_admin_nav.php'; ?>

<div style="max-width:1200px;margin:0 auto;padding:10px 14px 0;display:flex;gap:8px;flex-wrap:wrap">
  <a href="presensi_upload.php" style="display:inline-block;background:#13294f;color:#fff;
     text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:8px">
     📤 Upload Excel Presensi</a>
  <a href="presensi_klaim.php" style="display:inline-block;background:#d99e00;color:#3a2c00;
     text-decoration:none;font-size:13px;font-weight:700;padding:8px 14px;border-radius:8px">
     ✍️ Klaim Presensi Manual</a>
</div>

<main class="wrap" style="padding-top:16px;padding-bottom:30px">

  <h2 style="margin:8px 0 12px">Rekap per Tanggal</h2>
  <div class="rekap-grid">
    <?php foreach ($ALLOWED_DATES as $d): $jml = $rekap[$d]; ?>
      <div class="rekap-card">
        <a href="?tgl=<?= $d ?>">
          <div class="rk-day"><?= (int)substr($d,8,2) ?></div>
          <div class="rk-lbl">Mei 2026</div>
          <div class="rk-cnt"><?= number_format($jml,0,',','.') ?> hadir</div>
          <div class="rk-lbl"><?= number_format($totalDesa - $jml,0,',','.') ?> belum</div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="stats">
    <div class="stat-item"><div class="stat-num"><?= number_format($totalDesa,0,',','.') ?></div><div class="stat-lbl">Total Desa</div></div>
    <div class="stat-item ok"><div class="stat-num"><?= number_format($totalPresensi,0,',','.') ?></div><div class="stat-lbl">Total Submit Presensi</div></div>
    <div class="stat-item"><div class="stat-num"><?= count($ALLOWED_DATES) ?></div><div class="stat-lbl">Sesi Berjadwal</div></div>
  </div>

  <?php
    // Helper buat query string preserving pp
    $qsKeep = function(array $extra) use ($q, $tgl, $perPage) {
        $base = [
            'q'   => $q !== '' ? $q : null,
            'tgl' => $tgl !== 'all' ? $tgl : null,
            'pp'  => $perPage !== 30 ? $perPage : null,
        ];
        return http_build_query(array_filter(array_merge($base, $extra)));
    };
  ?>

  <div class="actions">
    <a class="btn primary" href="?export=all">⬇ Export Semua Presensi</a>
    <?php if (in_array($tgl, $ALLOWED_DATES, true)): ?>
      <a class="btn primary" href="?export=hadir&tgl=<?= $tgl ?>">⬇ Export HADIR (<?= h(date('d M Y', strtotime($tgl))) ?>)</a>
      <a class="btn danger"  href="?export=belum&tgl=<?= $tgl ?>">⬇ Export BELUM HADIR (<?= h(date('d M Y', strtotime($tgl))) ?>)</a>
    <?php endif; ?>
    <a class="btn <?= $tgl==='all'?'active':'' ?>" href="?<?= h($qsKeep(['tgl' => 'all'])) ?>">Semua Tanggal</a>
    <?php foreach ($ALLOWED_DATES as $d): ?>
      <a class="btn <?= $tgl===$d?'active':'' ?>" href="?<?= h($qsKeep(['tgl' => $d])) ?>"><?= (int)substr($d,8,2) ?> Mei</a>
    <?php endforeach; ?>
  </div>

  <form class="search-bar" method="get">
    <?php if ($tgl !== 'all'): ?><input type="hidden" name="tgl" value="<?= h($tgl) ?>"><?php endif; ?>
    <?php if ($perPage !== 30): ?><input type="hidden" name="pp" value="<?= (int)$perPage ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari kode, desa, kabupaten, provinsi...">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn-clear" href="admin_presensi.php<?= $tgl!=='all'?'?tgl='.$tgl:'' ?>">Reset</a><?php endif; ?>
  </form>

  <div class="table-wrap" style="display:block">
    <table class="desa-table">
      <thead>
        <tr>
          <th>Waktu Submit</th>
          <th>Tgl Sesi</th>
          <th>Kode</th>
          <th>Desa & Lokasi</th>
          <th>Status</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="empty">Belum ada data presensi.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="mono"><?= h(date('d-m-Y H:i:s', strtotime($r['submitted_at']))) ?></td>
          <td><?= h(date('d M Y', strtotime($r['tanggal_sesi']))) ?></td>
          <td class="mono"><?= h($r['kode_desa']) ?></td>
          <td>
            <b><?= h($r['nama_desa']) ?></b><br>
            <small><?= h($r['kecamatan']) ?>, <?= h($r['kabupaten']) ?></small><br>
            <small><?= h($r['provinsi']) ?></small>
          </td>
          <td>
            <span class="badge <?= h($r['status']) ?>"><?= h(strtoupper($r['status'])) ?></span>
            <?php if ($r['status'] !== 'ontime'): ?>
              <br><small style="color:#6b7280"><?= (int)round($r['selisih_detik']/3600) ?> jam</small>
            <?php endif; ?>
          </td>
          <td class="mono" style="font-size:11px"><?= h($r['ip_address']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php
    $base = '?' . http_build_query(array_filter(['q' => $q, 'tgl' => $tgl !== 'all' ? $tgl : null, 'pp' => $perPage !== 30 ? $perPage : null]));
    $sep  = ($base === '?') ? '' : '&';
  ?>
  <?php if ($totalPages > 1): ?>
  <nav class="pager">
    <?php
      $mk = function($p, $label = null, $disabled = false, $active = false) use ($base, $sep) {
          $cls = ($disabled ? 'disabled' : '') . ($active ? ' active' : '');
          $href = $disabled ? '#' : ($base . $sep . 'page=' . $p);
          $label = $label ?? $p;
          return '<a class="' . trim($cls) . '" href="' . h($href) . '">' . $label . '</a>';
      };
      echo $mk(max(1, $page - 1), '« Sebelumnya', $page <= 1);

      $start = max(1, $page - 2);
      $end   = min($totalPages, $page + 2);
      if ($start > 1) {
        echo $mk(1, '1');
        if ($start > 2) echo '<span class="dots">…</span>';
      }
      for ($i = $start; $i <= $end; $i++) {
        echo $mk($i, (string)$i, false, $i === $page);
      }
      if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="dots">…</span>';
        echo $mk($totalPages, (string)$totalPages);
      }

      echo $mk(min($totalPages, $page + 1), 'Berikutnya »', $page >= $totalPages);
    ?>
  </nav>
  <?php endif; ?>

  <div class="pager-info-bar">
    <div>
      Halaman <b><?= $page ?></b> dari <b><?= $totalPages ?></b>
      &middot; Menampilkan <b><?= count($rows) ?></b> dari <b><?= number_format($total,0,',','.') ?></b> baris
    </div>
    <div class="pp-select">
      Tampilkan:
      <?php foreach ([30, 50, 100, 200] as $opt): ?>
        <a href="?<?= h(http_build_query(array_filter([
            'q' => $q,
            'tgl' => $tgl !== 'all' ? $tgl : null,
            'pp' => $opt,
            'page' => 1,
        ]))) ?>" class="<?= $perPage === $opt ? 'active' : '' ?>"><?= $opt ?></a>
      <?php endforeach; ?>
    </div>
  </div>

</main>
</body></html>

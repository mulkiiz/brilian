<?php
require_once __DIR__ . '/config.php';
session_start();

if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if (!isset($_SESSION['admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['user'] ?? '') === ADMIN_USER && ($_POST['pass'] ?? '') === ADMIN_PASS) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: admin.php');
            exit;
        } else { $loginErr = 'Login gagal.'; }
    }
    ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Login</title><link rel="stylesheet" href="assets/style.css?v=3">
    </head><body>
    <div style="max-width:380px;margin:80px auto;padding:0 16px">
      <div class="info-card">
        <h2>🔐 Admin Login</h2>
        <form method="post">
          <div class="form-group"><label>Username</label><input type="text" name="user" required autofocus></div>
          <div class="form-group"><label>Password</label><input type="password" name="pass" required></div>
          <?php if (!empty($loginErr)): ?><div class="msg error"><?= h($loginErr) ?></div><?php endif; ?>
          <button class="btn-primary" style="width:100%;margin-top:10px">Login</button>
        </form>
      </div>
    </div></body></html><?php exit;
}

// --- Halaman riwayat per desa ---
if (isset($_GET['riwayat'])) {
    $kode = preg_replace('/\D/', '', $_GET['riwayat']);
    $stmt = $mysqli->prepare("SELECT * FROM konfirmasi_riwayat WHERE kode_desa = ? ORDER BY revision_no ASC");
    $stmt->bind_param('s', $kode);
    $stmt->execute();
    $hist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Riwayat <?= h($kode) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    </head><body>
    <div class="admin-bar" style="background:#0f1c33;color:#fff;padding:12px 0">
      <div class="wrap" style="display:flex;justify-content:space-between">
        <b>Riwayat Konfirmasi: <?= h($kode) ?></b>
        <a href="admin.php" style="color:#ffd700">← Kembali</a>
      </div>
    </div>
    <main class="wrap" style="padding:18px 14px">
      <?php if (empty($hist)): ?>
        <p>Belum ada riwayat untuk kode ini.</p>
      <?php else: foreach ($hist as $r): ?>
        <div class="card" style="margin-bottom:10px">
          <div class="card-head">
            <b>Revisi #<?= (int)$r['revision_no'] ?></b>
            <span style="font-size:12px;color:#6b7280"><?= h($r['submitted_at']) ?></span>
          </div>
          <div class="card-row"><span>Nama Desa</span><b><?= h($r['nama_desa']) ?></b></div>
          <div class="card-row"><span>Kec/Kab/Prov</span><b><?= h($r['kecamatan'] . ' / ' . $r['kabupaten'] . ' / ' . $r['provinsi']) ?></b></div>
          <div class="card-row"><span>Nama Kades</span><b><?= h($r['nama_kades']) ?></b></div>
          <div class="card-row"><span>HP</span><b class="mono"><?= h($r['hp_kades']) ?></b></div>
          <div class="card-row"><span>Email</span><b><?= h($r['email']) ?></b></div>
          <?php if ($r['edited_fields']): ?>
            <div style="margin-top:8px;padding:6px 10px;background:#fef3c7;border-radius:6px;font-size:12px;color:#78350f">
              <b>Field diedit:</b> <?= h($r['edited_fields']) ?>
            </div>
          <?php else: ?>
            <div style="margin-top:8px;padding:6px 10px;background:#dcfce7;border-radius:6px;font-size:12px;color:#166534">
              ✓ Tidak ada perubahan dari data asli
            </div>
          <?php endif; ?>
          <div style="margin-top:6px;font-size:11px;color:#9ca3af">IP: <?= h($r['ip_address']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </main></body></html><?php exit;
}

// --- Export CSV ---
if (isset($_GET['export'])) {
    $mode = $_GET['export'];

    // Helper: format HP 628xxx -> 08xxx
    $fmt_hp = function($hp) {
        $h = preg_replace('/\D/', '', (string)$hp);
        if (strpos($h, '62') === 0 && strlen($h) > 2) {
            return '0' . substr($h, 2);
        }
        return $h;
    };

    if ($mode === 'sudah') {
        $fname = 'konfirmasi_SUDAH_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Kode Desa','Nama Desa','Kecamatan','Kabupaten','Provinsi',
                       'Nama Kades','HP','Email','Field Diedit','Total Submit',
                       'Confirmed At','Updated At','IP']);
        $res = $mysqli->query("SELECT * FROM konfirmasi_desa ORDER BY confirmed_at DESC");
        while ($r = $res->fetch_assoc()) {
            fputcsv($out, [
                $r['kode_desa'], $r['nama_desa'], $r['kecamatan'], $r['kabupaten'], $r['provinsi'],
                $r['nama_kades'],
                $fmt_hp($r['hp_kades']),
                strtolower(trim($r['email'])),
                $r['edited_fields'], $r['total_edits'],
                $r['confirmed_at'], $r['updated_at'], $r['ip_address']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($mode === 'belum') {
        $fname = 'konfirmasi_BELUM_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Kode Desa','Nama Desa','Kecamatan','Kabupaten','Provinsi',
                       'Username LMS','Email LMS',
                       'BRI Regional','BRI Kanca','BRI Unit']);
        // LEFT JOIN: desa_peserta + info_bri, FILTER yg TIDAK ada di konfirmasi_desa
        $sql = "SELECT d.kode_desa, d.firstname, d.lastname, d.city, d.institution, d.email,
                       b.regional, b.kanca, b.unit
                FROM desa_peserta d
                LEFT JOIN konfirmasi_desa k ON k.kode_desa = d.kode_desa
                LEFT JOIN info_bri b        ON b.kode_desa = d.kode_desa
                WHERE k.id IS NULL
                ORDER BY b.regional, b.kanca, b.unit, d.institution, d.city, d.firstname";
        $res = $mysqli->query($sql);
        while ($r = $res->fetch_assoc()) {
            // Strip prefix untuk display nama desa & kecamatan
            $nama_desa = preg_replace('/^Desa\s+/i',   '', $r['firstname']);
            $kec       = preg_replace('/^Kec\.?\s+/i', '', $r['lastname']);
            // Email placeholder dianggap kosong
            $email     = (stripos($r['email'], '@brilian2026.id') !== false) ? '' : strtolower(trim($r['email']));
            fputcsv($out, [
                $r['kode_desa'], $nama_desa, $kec, $r['city'], $r['institution'],
                $r['kode_desa'],  // Username LMS = kode desa
                $email,
                $r['regional'] ?? '',
                $r['kanca']    ?? '',
                $r['unit']     ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}

// --- List konfirmasi ---
$q       = trim($_GET['q'] ?? '');
$filter  = $_GET['filter'] ?? 'all'; // all | edited | clean | revisi
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$cond = []; $params = []; $types = '';
if ($q !== '') {
    $cond[] = "(kode_desa LIKE ? OR nama_desa LIKE ? OR kabupaten LIKE ? OR hp_kades LIKE ? OR email LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($filter === 'edited') {
    $cond[] = "edited_fields IS NOT NULL AND edited_fields != ''";
} elseif ($filter === 'clean') {
    $cond[] = "(edited_fields IS NULL OR edited_fields = '')";
} elseif ($filter === 'revisi') {
    $cond[] = "total_edits > 1";
}
$where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';

$stmt = $mysqli->prepare("SELECT COUNT(*) c FROM konfirmasi_desa$where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT * FROM konfirmasi_desa$where ORDER BY updated_at DESC LIMIT ? OFFSET ?";
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

$totalDesa  = (int)$mysqli->query("SELECT COUNT(*) c FROM desa_peserta")->fetch_assoc()['c'];
$totalKonf  = (int)$mysqli->query("SELECT COUNT(*) c FROM konfirmasi_desa")->fetch_assoc()['c'];
$totalEdit  = (int)$mysqli->query("SELECT COUNT(*) c FROM konfirmasi_desa WHERE edited_fields IS NOT NULL AND edited_fields != ''")->fetch_assoc()['c'];
$totalRev   = (int)$mysqli->query("SELECT COUNT(*) c FROM konfirmasi_desa WHERE total_edits > 1")->fetch_assoc()['c'];
?>
<!doctype html><html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - Konfirmasi Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
  .admin-bar { background:#0f1c33; color:#fff; padding:12px 0; }
  .admin-bar .wrap { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
  .admin-bar a { color:#ffd700; }
  .actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .actions .btn { padding:9px 14px; background:#fff; border:1px solid #d1d5db; border-radius:8px;
    text-decoration:none; color:#374151; font-size:14px; font-weight:500; }
  .actions .btn.primary { background:#16a34a; color:#fff; border-color:#16a34a; }
  .actions .btn.active { background:#003d7a; color:#fff; border-color:#003d7a; }
  .desa-table th, .desa-table td { font-size:13px; padding:8px 10px; vertical-align:top; }
  .edit-tag { display:inline-block;background:#fef3c7;color:#78350f;padding:1px 6px;
    border-radius:4px;font-size:11px;margin:1px 2px 0 0;font-weight:500;}
  .stats { grid-template-columns: repeat(4, 1fr); }
</style>
</head><body>

<?php $ADMIN_NAV_ACTIVE='konfirmasi'; $ADMIN_NAV_BASE=''; require __DIR__.'/_admin_nav.php'; ?>

<main class="wrap" style="padding-top:16px;padding-bottom:30px">

  <div class="stats">
    <div class="stat-item"><div class="stat-num"><?= number_format($totalDesa, 0, ',', '.') ?></div><div class="stat-lbl">Total Desa</div></div>
    <div class="stat-item ok"><div class="stat-num"><?= number_format($totalKonf, 0, ',', '.') ?></div><div class="stat-lbl">Sudah Konfirmasi</div></div>
    <div class="stat-item warn"><div class="stat-num"><?= number_format($totalEdit, 0, ',', '.') ?></div><div class="stat-lbl">Ada Perbaikan</div></div>
    <div class="stat-item"><div class="stat-num"><?= number_format($totalRev, 0, ',', '.') ?></div><div class="stat-lbl">Sudah Revisi</div></div>
  </div>

  <div class="actions">
    <a class="btn primary" href="?export=sudah">⬇ Export Sudah Konfirmasi (<?= number_format($totalKonf, 0, ',', '.') ?>)</a>
    <a class="btn primary" href="?export=belum" style="background:#dc2626;border-color:#dc2626">⬇ Export Belum Konfirmasi (<?= number_format($totalDesa - $totalKonf, 0, ',', '.') ?>)</a>
    <a class="btn <?= $filter==='all'?'active':'' ?>" href="?filter=all<?= $q?'&q='.urlencode($q):'' ?>">Semua</a>
    <a class="btn <?= $filter==='edited'?'active':'' ?>" href="?filter=edited<?= $q?'&q='.urlencode($q):'' ?>">Ada Perbaikan</a>
    <a class="btn <?= $filter==='clean'?'active':'' ?>" href="?filter=clean<?= $q?'&q='.urlencode($q):'' ?>">Tanpa Perubahan</a>
    <a class="btn <?= $filter==='revisi'?'active':'' ?>" href="?filter=revisi<?= $q?'&q='.urlencode($q):'' ?>">Sudah Revisi</a>
  </div>

  <form class="search-bar" method="get">
    <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari kode, desa, kab, HP, email...">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn-clear" href="admin.php<?= $filter!=='all'?'?filter='.$filter:'' ?>">Reset</a><?php endif; ?>
  </form>

  <div class="table-wrap" style="display:block">
    <table class="desa-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Kode</th>
          <th>Desa & Lokasi</th>
          <th>Kades / Kontak</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="empty">Belum ada konfirmasi.</td></tr>
      <?php else: foreach ($rows as $r):
        $edited = $r['edited_fields'] ? explode(',', $r['edited_fields']) : [];
      ?>
        <tr>
          <td><?= h(date('d-m H:i', strtotime($r['updated_at']))) ?></td>
          <td class="mono"><?= h($r['kode_desa']) ?></td>
          <td>
            <b><?= h($r['nama_desa']) ?></b><br>
            <small><?= h($r['kecamatan']) ?>, <?= h($r['kabupaten']) ?><br><?= h($r['provinsi']) ?></small>
          </td>
          <td>
            <b><?= h($r['nama_kades']) ?></b><br>
            <small class="mono"><?php
              $h = preg_replace('/\D/', '', $r['hp_kades']);
              echo h(strpos($h,'62')===0 && strlen($h)>2 ? '0'.substr($h,2) : $h);
            ?></small><br>
            <small><?= h(strtolower(trim($r['email']))) ?></small>
          </td>
          <td>
            <?php if ($r['total_edits'] > 1): ?>
              <span class="badge warn">Revisi #<?= (int)$r['total_edits'] ?></span><br>
            <?php endif; ?>
            <?php if ($edited): ?>
              <?php foreach ($edited as $f): ?><span class="edit-tag"><?= h($f) ?></span><?php endforeach; ?>
            <?php else: ?>
              <span class="badge ok">✓ Tanpa edit</span>
            <?php endif; ?>
          </td>
          <td>
            <a class="btn-fix" href="?riwayat=<?= h($r['kode_desa']) ?>" style="text-decoration:none">Riwayat</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="pager">
    <?php
      $base = '?' . http_build_query(array_filter(['q' => $q, 'filter' => $filter !== 'all' ? $filter : null]));
      $sep  = ($base === '?') ? '' : '&';
      for ($i = 1; $i <= $totalPages; $i++) {
        $cls = $i === $page ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . h($base . $sep . 'page=' . $i) . '">' . $i . '</a>';
      }
    ?>
  </nav>
  <?php endif; ?>

</main>
</body></html>

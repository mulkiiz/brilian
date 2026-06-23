<?php
require_once __DIR__ . '/config.php';
session_start();

// Pakai sesi admin yang sama dengan ../admin.php
if (!isset($_SESSION['admin'])) {
    header('Location: ../admin.php');
    exit;
}

$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter  = $_GET['filter'] ?? 'all';

$sql = "SELECT d.kode_desa, d.firstname, d.lastname, d.city, d.institution,
               COALESCE(ks.sections_filled, 0) AS sections_filled,
               COALESCE(ks.total_photos, 0) AS total_photos,
               ks.last_updated
        FROM desa_peserta d
        LEFT JOIN katalog_status ks ON ks.kode_desa = d.kode_desa";

$where = []; $params = []; $types = '';
if ($q !== '') {
    $where[] = "(d.kode_desa LIKE ? OR d.firstname LIKE ? OR d.city LIKE ? OR d.institution LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($filter === 'filled')   $where[] = "COALESCE(ks.sections_filled,0) = 11";
elseif ($filter === 'partial') $where[] = "COALESCE(ks.sections_filled,0) > 0 AND COALESCE(ks.sections_filled,0) < 11";
elseif ($filter === 'empty')   $where[] = "COALESCE(ks.sections_filled,0) = 0";

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ks.last_updated IS NULL, ks.last_updated DESC, d.institution, d.city LIMIT 500';

$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDesa  = (int)$mysqli->query("SELECT COUNT(*) c FROM desa_peserta")->fetch_assoc()['c'];
$fullDesa   = (int)$mysqli->query("SELECT COUNT(*) c FROM katalog_status WHERE sections_filled=11")->fetch_assoc()['c'];
$partDesa   = (int)$mysqli->query("SELECT COUNT(*) c FROM katalog_status WHERE sections_filled>0 AND sections_filled<11")->fetch_assoc()['c'];
$totalFotos = (int)$mysqli->query("SELECT COUNT(*) c FROM katalog_foto")->fetch_assoc()['c'];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Katalog &mdash; Brilian 2026</title>
<link rel="stylesheet" href="../assets/style.css?v=7">
<style>
  .admin-bar { background: #0f1c33; color: #fff; padding: 12px 0; }
  .admin-bar .wrap { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
  .admin-bar a { color: #ffd700; text-decoration: none; margin-left: 12px; }
  .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; margin: 12px 0; }
  .filter-pills a {
    padding: 6px 14px; border-radius: 16px; background: #fff;
    border: 1px solid #d1d5db; color: #374151; text-decoration: none; font-size: 13px;
  }
  .filter-pills a.active { background: #003d7a; color: #fff; border-color: #003d7a; }
  .katalog-table { width: 100%; border-collapse: collapse; background: #fff; }
  .katalog-table th, .katalog-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eef0f4; font-size: 14px; }
  .katalog-table th { background: #f5f7fa; font-weight: 600; color: #374151; }
  .katalog-table tr:hover { background: #fafbfc; }
  .progress-mini { background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden; width: 100px; display: inline-block; vertical-align: middle; margin-right: 6px; }
  .progress-mini-fill { background: linear-gradient(90deg, #ffd700 0%, #f59e0b 100%); height: 100%; }
  .badge-num { background: #003d7a; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
  .btn-mini { padding: 5px 10px; border-radius: 6px; border: 1px solid #003d7a; background: #fff; color: #003d7a; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
  .btn-mini:hover { background: #003d7a; color: #fff; }
  .btn-compile { background: #16a34a; color: #fff; border: none; padding: 9px 18px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-block; }
  .btn-compile:hover { background: #15803d; }
  .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 14px 0; }
  .stats-row .stat-item { background: #fff; border-radius: 10px; padding: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border-top: 3px solid #003d7a; }
  .stat-item .stat-num { font-size: 22px; font-weight: 700; color: #003d7a; }
  .stat-item .stat-lbl { font-size: 12px; color: #6b7280; }
  .table-wrap { background: #fff; border-radius: 12px; overflow: auto; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .search-bar { display: flex; gap: 8px; margin-bottom: 8px; }
  .search-bar input[type="text"] { flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
  .search-bar button { padding: 8px 16px; border: none; background: #003d7a; color: #fff; border-radius: 8px; cursor: pointer; }
  .btn-clear { padding: 8px 14px; color: #6b7280; text-decoration: none; }
</style>
</head>
<body>

<?php $ADMIN_NAV_ACTIVE='katalog'; $ADMIN_NAV_BASE='../'; require __DIR__.'/../_admin_nav.php'; ?>

<main class="wrap" style="padding:18px 14px">

  <div class="stats-row">
    <div class="stat-item"><div class="stat-num"><?= number_format($totalDesa,0,',','.') ?></div><div class="stat-lbl">Total Desa</div></div>
    <div class="stat-item"><div class="stat-num"><?= number_format($fullDesa,0,',','.') ?></div><div class="stat-lbl">Katalog Lengkap (11/11)</div></div>
    <div class="stat-item"><div class="stat-num"><?= number_format($partDesa,0,',','.') ?></div><div class="stat-lbl">Sebagian Terisi</div></div>
    <div class="stat-item"><div class="stat-num"><?= number_format($totalFotos,0,',','.') ?></div><div class="stat-lbl">Total Foto</div></div>
  </div>

  <form method="get" action="" class="search-bar">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari kode/nama desa/kab/prov..." autocomplete="off">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn-clear" href="admin.php?filter=<?= h($filter) ?>">Reset</a><?php endif; ?>
  </form>

  <div class="filter-pills">
    <a href="?filter=all<?= $q?'&q='.urlencode($q):'' ?>"     class="<?= $filter==='all'    ?'active':''?>">Semua</a>
    <a href="?filter=filled<?= $q?'&q='.urlencode($q):'' ?>"  class="<?= $filter==='filled' ?'active':''?>">Lengkap (11/11)</a>
    <a href="?filter=partial<?= $q?'&q='.urlencode($q):'' ?>" class="<?= $filter==='partial'?'active':''?>">Sebagian</a>
    <a href="?filter=empty<?= $q?'&q='.urlencode($q):'' ?>"   class="<?= $filter==='empty'  ?'active':''?>">Belum Diisi</a>
  </div>

  <div style="margin-bottom:14px;padding:14px;background:#fffbeb;border-radius:10px;border-left:4px solid #f59e0b">
    <b>📚 Kompilasi Katalog Brilian 2026:</b>
    Setelah desa-desa selesai mengisi, klik tombol di bawah untuk membuat <b>HTML jilid</b> yang siap dicetak/disimpan PDF (gunakan menu <i>Print → Save as PDF</i> di browser).
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
      <a href="compile.php?scope=all" class="btn-compile" target="_blank">📖 Kompilasi Semua Desa</a>
      <a href="compile.php?scope=filled" class="btn-compile" target="_blank" style="background:#003d7a">📖 Hanya yang Lengkap (11/11)</a>
    </div>
  </div>

  <div class="table-wrap">
    <table class="katalog-table">
      <thead>
        <tr>
          <th>Kode</th>
          <th>Desa / Kec</th>
          <th>Kab / Prov</th>
          <th>Progres</th>
          <th>Foto</th>
          <th>Update Terakhir</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:24px">Tidak ada data.</td></tr>
        <?php else: foreach ($rows as $r):
          $nama_desa = preg_replace('/^Desa\s+/i', '', $r['firstname']);
          $kec       = preg_replace('/^Kec\.?\s+/i', '', $r['lastname']);
          $pct       = (int)round(($r['sections_filled'] / 11) * 100);
        ?>
          <tr>
            <td class="mono"><?= h($r['kode_desa']) ?></td>
            <td><b><?= h($nama_desa) ?></b><br><small style="color:#6b7280">Kec. <?= h($kec) ?></small></td>
            <td><?= h($r['city']) ?><br><small style="color:#6b7280"><?= h($r['institution']) ?></small></td>
            <td>
              <div class="progress-mini"><div class="progress-mini-fill" style="width:<?= $pct ?>%"></div></div>
              <span class="badge-num"><?= (int)$r['sections_filled'] ?>/11</span>
            </td>
            <td><?= (int)$r['total_photos'] ?></td>
            <td style="font-size:12px;color:#6b7280"><?= h($r['last_updated'] ?? '-') ?></td>
            <td>
              <a href="compile.php?scope=one&amp;kode=<?= h($r['kode_desa']) ?>" class="btn-mini" target="_blank">👁 Lihat</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (count($rows) >= 500): ?>
    <p style="margin-top:10px;color:#9ca3af;font-size:13px">Hanya menampilkan 500 baris pertama. Gunakan filter / pencarian untuk mempersempit.</p>
  <?php endif; ?>

</main>

</body>
</html>

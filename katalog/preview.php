<?php
require_once __DIR__ . '/config.php';
$kode = katalog_require_auth();
$nama = $_SESSION['katalog_nama'];
$kec  = $_SESSION['katalog_kec'];
$kab  = $_SESSION['katalog_kab'];
$prov = $_SESSION['katalog_prov'];

$narasi_map = [];
$stmt = $mysqli->prepare("SELECT section_key, narasi_html FROM katalog_desa WHERE kode_desa=?");
$stmt->bind_param('s', $kode);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $narasi_map[$r['section_key']] = $r['narasi_html'];
$stmt->close();

$foto_map = [];
$stmt = $mysqli->prepare("SELECT section_key, filename, caption FROM katalog_foto WHERE kode_desa=? ORDER BY section_key, urutan");
$stmt->bind_param('s', $kode);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $foto_map[$r['section_key']][] = $r;
$stmt->close();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Preview Katalog &mdash; <?= h($nama) ?></title>
<link rel="stylesheet" href="../assets/style.css?v=7">
<style>
  body { background: #f5f7fa; }
  .preview-wrap { max-width: 800px; margin: 0 auto; padding: 20px 16px; }
  .cover {
    background: linear-gradient(135deg, #003d7a 0%, #0057b8 100%);
    color: #fff; padding: 40px 24px; border-radius: 14px;
    text-align: center; margin-bottom: 22px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  .cover .badge-katalog {
    display: inline-block; background: #ffd700; color: #003d7a;
    padding: 4px 12px; border-radius: 14px; font-size: 12px;
    font-weight: 700; letter-spacing: 1px; margin-bottom: 14px;
  }
  .cover h1 { font-size: 28px; margin: 0 0 8px; }
  .cover .sub { opacity: .95; margin: 4px 0; }
  .sec-box {
    background: #fff; border-radius: 12px; padding: 22px;
    margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  }
  .sec-box h2 {
    color: #003d7a; font-size: 19px; margin: 0 0 14px;
    border-bottom: 2px solid #ffd700; padding-bottom: 8px;
  }
  .sec-narasi { line-height: 1.7; color: #1f2937; }
  .sec-narasi p { margin: 0 0 10px; }
  .sec-narasi ul, .sec-narasi ol { margin: 8px 0 12px 24px; }
  .sec-empty { color: #9ca3af; font-style: italic; }
  .sec-photos {
    display: flex; flex-direction: column;
    gap: 16px; margin-top: 14px;
  }
  .sec-photos figure { margin: 0; }
  .sec-photos img {
    width: 100%; height: auto;
    max-height: 80vh; object-fit: contain;
    border-radius: 8px; display: block;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
  }
  .sec-photos figcaption {
    font-size: 14px; color: #1f2937; margin-top: 8px; text-align: center;
    font-weight: 500;
  }
  .back-bar {
    background: #0f1c33; color: #fff; padding: 10px 0;
  }
  .back-bar .wrap { display: flex; justify-content: space-between; align-items: center; }
  .back-bar a { color: #ffd700; text-decoration: none; }
  @media print {
    .back-bar { display: none; }
    body { background: #fff; }
  }
</style>
</head>
<body>

<div class="back-bar">
  <div class="wrap">
    <b>👁 Preview Katalog</b>
    <a href="editor.php">← Kembali Edit</a>
  </div>
</div>

<div class="preview-wrap">

  <div class="cover">
    <div class="badge-katalog">KATALOG DESA BRILIAN 2026</div>
    <h1>Desa <?= h($nama) ?></h1>
    <div class="sub">Kec. <?= h($kec) ?></div>
    <div class="sub">Kab. <?= h($kab) ?> &middot; <?= h($prov) ?></div>
  </div>

  <?php foreach ($KATALOG_SECTIONS as $key => $sec):
        $type   = $sec['type'] ?? 'narasi';
        $narasi = $narasi_map[$key] ?? '';
        $fotos  = $foto_map[$key] ?? [];
  ?>
    <div class="sec-box">
      <h2><?= $sec['no'] ?>. <?= $sec['icon'] ?> <?= h($sec['judul']) ?></h2>

      <?php if ($type === 'narasi'): ?>
        <div class="sec-narasi">
          <?php if (trim(strip_tags($narasi)) !== ''): ?>
            <?= $narasi ?>
          <?php else: ?>
            <p class="sec-empty">(Belum diisi)</p>
          <?php endif; ?>
        </div>
      <?php else: /* foto */ ?>
        <?php if (!empty($fotos)): ?>
          <div class="sec-photos">
            <?php foreach ($fotos as $idx => $f):
                  $no = $idx + 1;
                  $cap = trim($f['caption']);
                  $captionText = $cap !== ''
                      ? 'Gambar ' . $no . '. ' . $cap
                      : 'Gambar ' . $no . '. Dokumentasi Kegiatan Desa';
            ?>
              <figure>
                <img src="<?= h(KATALOG_UPLOAD_URL . '/' . $kode . '/' . $f['filename']) ?>" alt="">
                <figcaption><?= h($captionText) ?></figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="sec-narasi"><p class="sec-empty">(Belum ada foto)</p></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

</div>

</body>
</html>

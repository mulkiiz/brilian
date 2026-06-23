<?php
require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['admin'])) { header('Location: ../admin.php'); exit; }

$scope = $_GET['scope'] ?? 'filled';
$kode_filter = $_GET['kode'] ?? null;

$sql = "SELECT d.kode_desa, d.firstname, d.lastname, d.city, d.institution,
               COALESCE(ks.sections_filled,0) AS sections_filled
        FROM desa_peserta d
        LEFT JOIN katalog_status ks ON ks.kode_desa = d.kode_desa";

if ($scope === 'filled') {
    $sql .= " WHERE COALESCE(ks.sections_filled,0) = 11";
} elseif ($scope === 'one' && $kode_filter) {
    $kode_filter = preg_replace('/\D/', '', $kode_filter);
    $sql .= " WHERE d.kode_desa = '" . $mysqli->real_escape_string($kode_filter) . "'";
} elseif ($scope === 'all') {
    $sql .= " WHERE COALESCE(ks.sections_filled,0) > 0";
}
$sql .= " ORDER BY d.institution, d.city, d.firstname";

$desa_list = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);

$narasi_map = []; $foto_map = [];
if (!empty($desa_list)) {
    $kodes = array_column($desa_list, 'kode_desa');
    $placeholders = implode(',', array_fill(0, count($kodes), '?'));
    $types = str_repeat('s', count($kodes));

    $stmt = $mysqli->prepare("SELECT kode_desa, section_key, narasi_html FROM katalog_desa WHERE kode_desa IN ($placeholders)");
    $stmt->bind_param($types, ...$kodes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $narasi_map[$r['kode_desa']][$r['section_key']] = $r['narasi_html'];
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT kode_desa, section_key, filename, caption FROM katalog_foto WHERE kode_desa IN ($placeholders) ORDER BY urutan");
    $stmt->bind_param($types, ...$kodes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $foto_map[$r['kode_desa']][$r['section_key']][] = $r;
    $stmt->close();
}

$total = count($desa_list);
$title_scope = $scope === 'filled' ? 'Desa dengan Katalog Lengkap'
             : ($scope === 'one' ? '1 Desa' : 'Semua Desa');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Katalog Desa Brilian 2026 &mdash; <?= h($title_scope) ?></title>
<style>
  @page { size: A4; margin: 18mm 16mm 18mm 16mm; }
  * {
    box-sizing: border-box;
    /* PENTING: paksa browser merender semua background color/image saat print/PDF */
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
  }
  body {
    margin: 0; font-family: Georgia, "Times New Roman", serif;
    color: #1f2937; background: #e5e7eb; font-size: 11.5pt; line-height: 1.55;
  }
  .toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #0f1c33; color: #fff; padding: 10px 16px;
    display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap;
  }
  .toolbar a, .toolbar button {
    background: #ffd700; color: #003d7a; padding: 7px 14px; border-radius: 6px;
    text-decoration: none; font-weight: 600; border: none; cursor: pointer; font-family: inherit;
  }
  .toolbar a.secondary { background: #fff; color: #0f1c33; }
  .book { background: #fff; max-width: 210mm; margin: 14px auto; padding: 0; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
  .page { padding: 16mm 14mm; min-height: 280mm; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .cover {
    background: linear-gradient(135deg, #003d7a 0%, #0057b8 60%, #0f1c33 100%);
    color: #fff; text-align: center; display: flex; flex-direction: column;
    justify-content: center; align-items: center; padding: 40mm 14mm;
  }
  .cover .pill {
    background: #ffd700; color: #003d7a; padding: 5px 16px; border-radius: 20px;
    font-size: 11pt; font-weight: 700; letter-spacing: 2px; margin-bottom: 20px;
    font-family: Helvetica, Arial, sans-serif;
  }
  .cover h1 { font-size: 38pt; margin: 0 0 14px; line-height: 1.15; font-family: Georgia, serif; }
  .cover .subtitle { font-size: 16pt; opacity: .95; margin-bottom: 30px; }
  .cover .org { font-size: 12pt; margin-top: 80px; opacity: .9; font-family: Helvetica, Arial, sans-serif; }
  .cover .year { font-size: 48pt; font-weight: 700; margin: 16px 0 4px; }
  .cover .batch {
    font-size: 18pt; font-weight: 600;
    color: #ffd700; letter-spacing: 2px;
    margin-top: 0;
    font-family: Helvetica, Arial, sans-serif;
  }
  .toc h2 { color: #003d7a; border-bottom: 3px solid #ffd700; padding-bottom: 8px; font-size: 22pt; }
  .toc ol { padding-left: 24px; }
  .toc li { padding: 5px 0; border-bottom: 1px dotted #d1d5db; font-size: 11pt; }
  .toc li b { color: #003d7a; }
  .desa-cover {
    background: #fffbeb; border-left: 8px solid #ffd700; padding: 22mm 14mm;
    min-height: 280mm; display: flex; flex-direction: column; justify-content: center;
  }
  .desa-cover .nomor { font-size: 16pt; color: #6b7280; margin-bottom: 6px; font-family: Helvetica, Arial, sans-serif; }
  .desa-cover h2 { color: #003d7a; font-size: 32pt; margin: 0 0 10px; line-height: 1.15; }
  .desa-cover .lokasi { font-size: 13pt; color: #374151; margin: 4px 0; }
  .desa-cover .kode { margin-top: 24px; font-family: ui-monospace, monospace; font-size: 11pt; color: #6b7280; }
  .section { margin-bottom: 14px; page-break-inside: avoid; }
  .section h3 {
    color: #003d7a; font-size: 14pt; margin: 0 0 8px;
    padding: 8px 12px; background: #f0f9ff;
    border-left: 4px solid #003d7a; border-radius: 4px;
  }
  .section h3 .no { color: #6b7280; font-weight: 400; font-size: 12pt; margin-right: 6px; }
  .narasi { line-height: 1.65; text-align: justify; }
  .narasi p { margin: 0 0 8px; }
  .narasi ul, .narasi ol { margin: 6px 0 10px 22px; }
  .narasi a { color: #003d7a; }
  .narasi h2 { font-size: 13pt; color: #003d7a; margin: 10px 0 6px; }
  .narasi h3 { font-size: 12pt; color: #003d7a; margin: 8px 0 4px; background: none; padding: 0; border: none; }
  .narasi blockquote {
    border-left: 3px solid #ffd700; margin: 8px 0; padding: 4px 10px;
    background: #fffbeb; color: #374151; font-style: italic;
  }
  .empty-note { color: #9ca3af; font-style: italic; }
  /* Foto: 1 per baris, fit lebar kertas, tidak dipotong */
  .photos { display: flex; flex-direction: column; gap: 8mm; margin-top: 10px; }
  .photos figure { margin: 0; page-break-inside: avoid; text-align: center; }
  .photos img {
    width: 100%;
    max-height: 170mm;          /* sisa kertas A4 setelah margin + heading + caption */
    height: auto;
    object-fit: contain;         /* JANGAN crop */
    border-radius: 4px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    display: block;
    margin: 0 auto;
  }
  .photos figcaption {
    font-size: 10pt; color: #1f2937; text-align: center;
    margin-top: 6px; font-weight: 600;
    font-family: Helvetica, Arial, sans-serif;
  }
  .footer-info { margin-top: 14px; padding-top: 8px; border-top: 1px dashed #d1d5db; font-size: 9pt; color: #9ca3af; text-align: right; }
  @media print {
    body { background: #fff; font-size: 10.5pt; }
    .toolbar, .no-print { display: none !important; }
    .book { box-shadow: none; margin: 0; max-width: none; }
    .page { padding: 0; min-height: auto; }
    .desa-cover {
      min-height: auto; padding: 20mm 0;
      background: #fffbeb !important;
      border-left: 8px solid #ffd700 !important;
    }
    .cover {
      padding: 30mm 0; min-height: auto;
      background: linear-gradient(135deg, #003d7a 0%, #0057b8 60%, #0f1c33 100%) !important;
      color: #fff !important;
    }
    .cover .pill {
      background: #ffd700 !important; color: #003d7a !important;
    }
    .section h3 {
      background: #f0f9ff !important;
      border-left: 4px solid #003d7a !important;
    }
    .narasi blockquote {
      background: #fffbeb !important;
      border-left: 3px solid #ffd700 !important;
    }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div><b>📖 Katalog Desa Brilian 2026</b> &middot; <?= h($title_scope) ?> &middot; <?= $total ?> desa</div>
  <div>
    <button onclick="window.print()">🖨 Print / Simpan PDF</button>
    <a href="admin.php" class="secondary">← Kembali</a>
  </div>
</div>
<div class="no-print" style="background:#fef3c7;color:#78350f;padding:8px 16px;font-size:13px;text-align:center;border-bottom:1px solid #fbbf24">
  💡 <b>Penting:</b> di dialog Print, buka <b>More settings</b> → centang <b>"Background graphics"</b> agar warna cover & header ikut tercetak.
</div>

<div class="book">

  <div class="page cover">
    <div class="pill">KATALOG</div>
    <h1>Katalog Desa Brilian</h1>
    <div class="subtitle">Profil &amp; Potensi Desa Peserta</div>
    <div class="year">2026</div>
    <div class="batch">Batch 1</div>
    <div class="subtitle" style="margin-top:20px;font-size:13pt"><?= $total ?> Desa Peserta</div>
    <div class="org">
      Kolaborasi Universitas Jenderal Soedirman dan<br>
      PT. Bank Rakyat Indonesia (Persero) Tbk.
    </div>
  </div>

  <?php if ($total > 0): ?>
  <div class="page toc">
    <h2>Daftar Isi</h2>
    <ol>
      <?php foreach ($desa_list as $d):
        $nama_desa = preg_replace('/^Desa\s+/i', '', $d['firstname']);
        $kec       = preg_replace('/^Kec\.?\s+/i', '', $d['lastname']);
      ?>
        <li><b>Desa <?= h($nama_desa) ?></b> &mdash; Kec. <?= h($kec) ?>, <?= h($d['city']) ?>, <?= h($d['institution']) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endif; ?>

  <?php foreach ($desa_list as $idx => $d):
    $kode       = $d['kode_desa'];
    $nama_desa  = preg_replace('/^Desa\s+/i', '', $d['firstname']);
    $kec        = preg_replace('/^Kec\.?\s+/i', '', $d['lastname']);
    $kab        = $d['city'];
    $prov       = $d['institution'];
    $narasi_d   = $narasi_map[$kode] ?? [];
    $foto_d     = $foto_map[$kode] ?? [];
  ?>

    <div class="page desa-cover">
      <div class="nomor">#<?= str_pad((string)($idx+1), 3, '0', STR_PAD_LEFT) ?></div>
      <h2>Desa <?= h($nama_desa) ?></h2>
      <div class="lokasi">Kecamatan <?= h($kec) ?></div>
      <div class="lokasi"><b>Kabupaten <?= h($kab) ?></b></div>
      <div class="lokasi"><?= h($prov) ?></div>
      <div class="kode">Kode Desa: <?= h($kode) ?></div>
    </div>

    <div class="page">
      <?php foreach ($KATALOG_SECTIONS as $key => $sec):
        $type   = $sec['type'] ?? 'narasi';
        $narasi = $narasi_d[$key] ?? '';
        $fotos  = $foto_d[$key] ?? [];
      ?>
        <div class="section">
          <h3><span class="no"><?= $sec['no'] ?>.</span> <?= h($sec['judul']) ?></h3>

          <?php if ($type === 'narasi'): ?>
            <div class="narasi">
              <?php if (trim(strip_tags($narasi)) !== ''): ?>
                <?= $narasi ?>
              <?php else: ?>
                <p class="empty-note">(Belum diisi)</p>
              <?php endif; ?>
            </div>
          <?php else: /* foto */ ?>
            <?php if (!empty($fotos)): ?>
              <div class="photos">
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
              <div class="narasi"><p class="empty-note">(Belum ada foto)</p></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="footer-info">
        Katalog Desa Brilian 2026 &middot; Desa <?= h($nama_desa) ?> &middot; <?= h($kode) ?>
      </div>
    </div>

  <?php endforeach; ?>

  <?php if ($total === 0): ?>
    <div class="page">
      <p style="text-align:center;color:#9ca3af;padding:60px 0">Belum ada desa yang memenuhi kriteria.</p>
    </div>
  <?php endif; ?>

</div>

</body>
</html>

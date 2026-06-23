<?php
require_once __DIR__ . '/config.php';
$kode_desa = katalog_require_auth();

$auth_nama  = $_SESSION['katalog_nama'];
$auth_kec   = $_SESSION['katalog_kec'];
$auth_kab   = $_SESSION['katalog_kab'];
$auth_prov  = $_SESSION['katalog_prov'];

// Ambil data existing
$sections_data = [];
$stmt = $mysqli->prepare("SELECT section_key, narasi_html FROM katalog_desa WHERE kode_desa=?");
$stmt->bind_param('s', $kode_desa);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $sections_data[$r['section_key']] = $r['narasi_html'];
$stmt->close();

$photos_by_section = [];
$stmt = $mysqli->prepare("SELECT section_key, id, filename, caption, urutan FROM katalog_foto WHERE kode_desa=? ORDER BY section_key, urutan");
$stmt->bind_param('s', $kode_desa);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $photos_by_section[$r['section_key']][] = $r;
$stmt->close();

$filled = katalog_count_filled($mysqli, $kode_desa);
$total  = count($KATALOG_SECTIONS);
$pct    = (int)round(($filled / $total) * 100);
$csrf   = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="theme-color" content="#003d7a">
<title>Editor Katalog &mdash; Desa <?= h($auth_nama) ?></title>
<link rel="stylesheet" href="../assets/style.css?v=7">
<link rel="stylesheet" href="assets/katalog.css?v=4">
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
</head>
<body>

<header class="topbar">
  <div class="wrap">
    <div class="brand">
      <div class="logo">B</div>
      <div>
        <div class="title">Brilian 2026</div>
        <div class="subtitle">Editor Katalog Desa</div>
      </div>
    </div>
  </div>
</header>

<main class="wrap">

  <div class="info-card katalog-header">
    <div class="kh-row">
      <div>
        <h2>📒 Katalog Desa <?= h($auth_nama) ?></h2>
        <p class="kh-meta">
          Kec. <?= h($auth_kec) ?> &middot; Kab. <?= h($auth_kab) ?> &middot; Prov. <?= h($auth_prov) ?>
        </p>
        <p class="kh-meta mono">Kode: <?= h($kode_desa) ?></p>
      </div>
      <button type="button" class="btn-logout" id="btn-logout">Keluar</button>
    </div>

    <div class="progress-box">
      <div class="progress-label">
        Progres pengisian: <b><?= $filled ?> dari <?= $total ?> bagian</b> (<?= $pct ?>%)
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
    </div>

    <div class="info-box" style="margin-top:12px">
      ℹ️ Klik bagian di bawah untuk membuka editor. Setiap bagian: isi <b>narasi</b> (teks dengan format) dan unggah <b>maks 4 foto</b> (@ maks 250 KB). Klik <b>Simpan</b> di tiap bagian. Anda bisa kembali kapan saja untuk melanjutkan.
    </div>
  </div>

  <!-- ===== Section Accordion ===== -->
  <div class="sections-list">
  <?php foreach ($KATALOG_SECTIONS as $key => $sec):
        $type      = $sec['type'] ?? 'narasi';
        $narasi    = $sections_data[$key] ?? '';
        $photos    = $photos_by_section[$key] ?? [];
        $hasNarasi = trim(strip_tags($narasi)) !== '';
        $photoCount = count($photos);
        $isFilled  = ($type === 'foto') ? ($photoCount > 0) : $hasNarasi;
  ?>
    <details class="section-card type-<?= h($type) ?>" data-section="<?= h($key) ?>" data-type="<?= h($type) ?>">
      <summary>
        <div class="sec-summary">
          <div class="sec-num"><?= $sec['no'] ?></div>
          <div class="sec-title-wrap">
            <div class="sec-title"><?= $sec['icon'] ?> <?= h($sec['judul']) ?></div>
            <div class="sec-status">
              <?php if ($isFilled): ?>
                <span class="badge ok">✓ Terisi</span>
              <?php else: ?>
                <span class="badge warn">Belum diisi</span>
              <?php endif; ?>
              <?php if ($type === 'foto'): ?>
                <span class="sec-photo-count"><?= $photoCount ?>/<?= KATALOG_MAX_PHOTOS ?> foto</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="sec-chevron">▾</div>
        </div>
      </summary>

      <div class="sec-body">
        <p class="sec-hint"><?= h($sec['hint']) ?></p>

        <?php if ($type === 'narasi'): ?>

          <!-- NARASI-ONLY -->
          <label class="field-label">Narasi</label>
          <div class="quill-wrap">
            <div class="quill-editor" id="q-<?= h($key) ?>"><?= $narasi ?></div>
          </div>
          <div class="sec-actions">
            <button type="button" class="btn-primary btn-save-narasi" data-key="<?= h($key) ?>">💾 Simpan Narasi</button>
            <span class="save-status" id="status-<?= h($key) ?>"></span>
          </div>

        <?php else: /* type === 'foto' */ ?>

          <!-- FOTO-ONLY -->
          <label class="field-label">Foto Dokumentasi (maks <?= KATALOG_MAX_PHOTOS ?> @ <?= KATALOG_MAX_FILESIZE/1024 ?> KB)</label>

          <div class="dropzone" data-key="<?= h($key) ?>">
            <input type="file" class="dz-input" accept="image/jpeg,image/png,image/webp" multiple>
            <div class="dz-msg">
              📥 <b>Drag &amp; drop</b> foto ke sini, atau <u>klik untuk pilih file</u>.<br>
              <small>JPG/PNG/WebP &middot; maks <?= KATALOG_MAX_FILESIZE/1024 ?> KB per foto &middot; foto tidak akan dipotong</small>
            </div>
            <div class="dz-loading" aria-live="polite">
              <div class="dz-spinner"></div>
              <div class="dz-progress-text">Mengunggah foto...</div>
              <div class="dz-progress-detail">Mohon tunggu, jangan tutup halaman</div>
            </div>
          </div>

          <p style="margin:8px 0 12px;font-size:13px;color:#92400e;background:#fef9c3;padding:8px 12px;border-radius:6px;border-left:3px solid #f59e0b">
            💡 <b>Caption wajib diisi.</b> Setelah upload, Anda akan diminta mengisi caption. Format saat dicetak: <i>"Gambar 1. [caption Anda]"</i>, <i>"Gambar 2. [caption Anda]"</i>, dst.
          </p>

          <div class="photo-grid" id="photos-<?= h($key) ?>">
            <?php foreach ($photos as $idx => $p):
                  $capMissing = trim($p['caption']) === '';
            ?>
              <div class="photo-item <?= $capMissing ? 'caption-missing' : '' ?>" data-id="<?= (int)$p['id'] ?>">
                <div class="photo-thumb">
                  <img src="<?= h(KATALOG_UPLOAD_URL . '/' . $kode_desa . '/' . $p['filename']) ?>" alt="">
                </div>
                <button type="button" class="photo-del" data-id="<?= (int)$p['id'] ?>" title="Hapus foto">×</button>
                <div class="photo-caption-display" data-id="<?= (int)$p['id'] ?>">
                  <?php if ($capMissing): ?>
                    <span class="cap-warn">⚠ Caption belum diisi</span>
                    <button type="button" class="btn-edit-caption" data-id="<?= (int)$p['id'] ?>">Isi caption</button>
                  <?php else: ?>
                    <span class="cap-text"><?= h($p['caption']) ?></span>
                    <button type="button" class="btn-edit-caption" data-id="<?= (int)$p['id'] ?>" title="Edit caption">✏</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>

  <div class="info-card" style="margin-top:18px;text-align:center">
    <p><b>Setelah semua bagian terisi</b>, panitia akan mengkompilasi katalog menjadi PDF jilid sebagai dokumentasi resmi.</p>
    <p style="margin-top:8px"><a href="preview.php" class="btn-primary" target="_blank">👁 Preview Katalog Desa Saya</a></p>
  </div>

</main>

<footer class="foot">
  <div class="wrap">
    <p>Brilian 2026 &middot; LPPM Unsoed × BRI &middot; Butuh bantuan? <a href="https://wa.me/6287887650978">Tri Wahyu (WA: 0878-8765-0978)</a></p>
  </div>
</footer>

<!-- ============================================================ -->
<!-- MODAL: Caption Foto                                           -->
<!-- ============================================================ -->
<div id="modal-caption" class="modal" aria-hidden="true">
  <div class="modal-overlay" data-modal-close></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="caption-title">
    <div class="modal-header">
      <div class="modal-icon">📝</div>
      <h3 id="caption-title">Caption Foto</h3>
      <p class="modal-sub">Isi judul/keterangan foto. Saat dicetak di PDF akan menjadi <i>"Gambar N. [caption Anda]"</i>.</p>
    </div>

    <div class="caption-preview-wrap">
      <img id="caption-photo-preview" src="" alt="">
      <div class="caption-figno" id="caption-figno">Gambar —</div>
    </div>

    <div class="form-group">
      <label for="caption-input">Caption foto <span style="color:#dc2626">*</span></label>
      <input type="text" id="caption-input" maxlength="500"
             placeholder="Contoh: Kegiatan Posyandu Desa Bangunkerta" autocomplete="off">
      <small class="hint">Maks 500 karakter. Wajib diisi minimal 3 karakter.</small>
    </div>

    <div id="caption-msg" class="msg"></div>

    <div class="form-actions">
      <button type="button" class="btn-cancel" data-modal-close>Nanti saja</button>
      <button type="button" class="btn-primary" id="btn-save-caption">💾 Simpan Caption</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
<?php
  $narasi_keys = [];
  $foto_keys   = [];
  foreach ($KATALOG_SECTIONS as $k => $s) {
      if (($s['type'] ?? 'narasi') === 'foto') $foto_keys[] = $k;
      else $narasi_keys[] = $k;
  }
?>
  window.KATALOG = {
    csrf: "<?= h($csrf) ?>",
    kode: "<?= h($kode_desa) ?>",
    maxPhotos: <?= KATALOG_MAX_PHOTOS ?>,
    maxBytes: <?= KATALOG_MAX_FILESIZE ?>,
    narasiKeys: <?= json_encode($narasi_keys) ?>,
    fotoKeys:   <?= json_encode($foto_keys) ?>
  };
</script>
<script src="assets/editor.js?v=4"></script>

</body>
</html>

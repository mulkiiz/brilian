<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Jika sudah ada sesi katalog, langsung ke editor
if (!empty($_SESSION['katalog_kode'])) {
    header('Location: editor.php');
    exit;
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="theme-color" content="#003d7a">
<title>Katalog Desa &mdash; Brilian 2026</title>
<link rel="stylesheet" href="../assets/style.css?v=7">
<link rel="stylesheet" href="assets/katalog.css?v=1">
</head>
<body class="katalog-body">

<header class="topbar">
  <div class="wrap">
    <div class="brand">
      <div class="logo">B</div>
      <div>
        <div class="title">Brilian 2026</div>
        <div class="subtitle">Katalog Desa Peserta</div>
      </div>
    </div>
  </div>
</header>

<main class="wrap">
  <div class="info-card landing-card">
    <h2>📒 Katalog Desa Brilian 2026</h2>
    <p>Halaman ini untuk mengisi profil lengkap desa Anda yang nantinya akan dijilid menjadi <b>Katalog Desa Brilian 2026</b> sebagai dokumentasi resmi program.</p>
    <p>Anda akan diminta mengisi 11 bagian profil desa: visi-misi, demografi, BUMDesa, KDMP, produk unggulan, inovasi, dokumentasi kegiatan, dan lain-lain. Setiap bagian terdiri dari <b>narasi</b> dan <b>maksimal 4 foto</b>.</p>
    <p style="margin-top:14px">Sedang memuat verifikasi&hellip;</p>
  </div>
</main>

<footer class="foot">
  <div class="wrap">
    <p>Brilian 2026 &middot; LPPM Unsoed × BRI &middot; Butuh bantuan? <a href="https://wa.me/6287887650978">Tri Wahyu (WA: 0878-8765-0978)</a></p>
  </div>
</footer>

<!-- ============================================================ -->
<!-- MODAL: Verifikasi Kode Desa (auto-buka saat halaman load)     -->
<!-- ============================================================ -->
<div id="modal-verify" class="modal is-open" aria-hidden="false">
  <div class="modal-overlay"></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">

    <div class="modal-header">
      <div class="modal-icon">📒</div>
      <h3 id="modal-title">Verifikasi Kode Desa</h3>
      <p class="modal-sub">Masukkan <b>10 digit Kode Desa</b> Anda untuk mulai mengisi katalog.</p>
    </div>

    <div class="form-group">
      <label for="kode-input">Kode Desa (10 digit angka)</label>
      <input type="tel" inputmode="numeric" pattern="[0-9]*" id="kode-input"
             maxlength="10" placeholder="Contoh: 1207232006" autocomplete="off" autofocus>
      <small class="hint">Kode desa terdiri dari 10 angka, dapat dilihat dari surat undangan atau data Kemendagri.</small>
    </div>

    <div id="msg" class="msg"></div>

    <div class="form-actions">
      <a href="../index.php" class="btn-cancel">← Kembali ke Portal</a>
      <button type="button" class="btn-primary" id="btn-verify">Lanjutkan »</button>
    </div>

  </div>
</div>

<script>
  window.KATALOG_CSRF = "<?= h($csrf) ?>";
</script>
<script src="assets/landing.js?v=1"></script>

</body>
</html>

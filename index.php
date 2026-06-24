<?php
require_once __DIR__ . '/config.php';

// ============================================================
// Helper untuk strip prefix dari kolom display
// ============================================================
function strip_desa_prefix($s) {
    return preg_replace('/^Desa\s+/i', '', $s);
}
function strip_kec_prefix($s) {
    return preg_replace('/^Kec\.?\s+/i', '', $s);
}

// ============================================================
// Parameter
// ============================================================
$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ============================================================
// Query: dari desa_peserta (struktur baru), JOIN konfirmasi_desa untuk status
// ============================================================
$where  = '';
$params = [];
$types  = '';
if ($q !== '') {
    $where = " WHERE d.kode_desa LIKE ? OR d.firstname LIKE ? OR d.lastname LIKE ? OR d.city LIKE ? OR d.institution LIKE ?";
    $like = '%' . $q . '%';
    $params = array_fill(0, 5, $like);
    $types  = 'sssss';
}

// Count
$sqlCount = "SELECT COUNT(*) AS c FROM desa_peserta d" . $where;
$stmt = $mysqli->prepare($sqlCount);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Data
$sql = "SELECT d.kode_desa, d.firstname, d.lastname, d.city, d.institution,
               d.email AS master_email,
               (SELECT id FROM konfirmasi_desa kd WHERE kd.kode_desa = d.kode_desa) AS sudah_konfirmasi
        FROM desa_peserta d
        $where
        ORDER BY d.institution, d.city, d.lastname, d.firstname
        LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($sql);
if ($params) {
    $types2 = $types . 'ii';
    $args   = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types2, ...$args);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$totalDesa = (int)$mysqli->query("SELECT COUNT(*) c FROM desa_peserta")->fetch_assoc()['c'];
$totalKonf = (int)$mysqli->query("SELECT COUNT(*) c FROM konfirmasi_desa")->fetch_assoc()['c'];

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="theme-color" content="#003d7a">
<title>Info Akun LMS &mdash; Brilian 2026</title>
<link rel="stylesheet" href="assets/style.css?v=7">
<link rel="stylesheet" href="assets/presensi.css?v=3">
<link rel="stylesheet" href="assets/gradebook.css?v=2">
</head>
<body>

<header class="topbar">
  <div class="wrap">
    <div class="brand">
      <div class="logo">B</div>
      <div>
        <div class="title">Brilian 2026</div>
        <div class="subtitle">Info Akun LMS Desa Peserta</div>
      </div>
    </div>
  </div>
</header>

<main class="wrap">

  <div class="info-card">
    <h2>Selamat datang, Bapak/Ibu Kepala Desa</h2>
    <p>Setiap desa peserta Brilian 2026 telah memiliki akun LMS Joglo. Klik tombol <b>Info LMS</b> untuk melihat akun, atau tombol <b>Gradebook</b> untuk melihat rekap nilai &amp; kehadiran seluruh desa peserta.</p>
    <ol class="steps">
      <li>Cari nama desa Anda di tabel di bawah</li>
      <li>Klik <b>Info LMS</b> (lihat akun / edit data) — perlu Kode Desa, atau <b>Gradebook</b> (lihat nilai, terbuka untuk semua)</li>
      <li>Untuk Info LMS, masukkan <b>10 digit Kode Desa</b> Anda</li>
    </ol>
  </div>

  <div class="stats">
    <div class="stat-item">
      <div class="stat-num"><?= number_format($totalDesa, 0, ',', '.') ?></div>
      <div class="stat-lbl">Total Desa</div>
    </div>
    <div class="stat-item ok">
      <div class="stat-num"><?= number_format($totalKonf, 0, ',', '.') ?></div>
      <div class="stat-lbl">Sudah Konfirmasi Data</div>
    </div>
    <div class="stat-item warn">
      <div class="stat-num"><?= number_format($totalDesa - $totalKonf, 0, ',', '.') ?></div>
      <div class="stat-lbl">Belum Konfirmasi Data</div>
    </div>
  </div>

  <form class="search-bar" method="get" action="">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari nama desa, kecamatan, kabupaten, atau provinsi..." autocomplete="off">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?>
      <a class="btn-clear" href="index.php">Reset</a>
    <?php endif; ?>
  </form>

  <?php if ($q !== ''): ?>
    <div class="result-info">Hasil pencarian "<b><?= h($q) ?></b>": <?= number_format($total, 0, ',', '.') ?> desa</div>
  <?php endif; ?>

  <!-- Tabel desktop -->
  <div class="table-wrap">
    <table class="desa-table">
      <thead>
        <tr>
          <th>Nama Desa</th>
          <th>Kecamatan</th>
          <th>Kabupaten</th>
          <th>Provinsi</th>
          <th>Status Data</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="empty">Data tidak ditemukan.</td></tr>
      <?php else: foreach ($rows as $r):
        $nama_desa  = strip_desa_prefix($r['firstname']);
        $kecamatan  = strip_kec_prefix($r['lastname']);
      ?>
        <tr>
          <td><b><?= h($nama_desa) ?></b></td>
          <td><?= h($kecamatan) ?></td>
          <td><?= h($r['city']) ?></td>
          <td><?= h($r['institution']) ?></td>
          <td>
            <?php if ($r['sudah_konfirmasi']): ?>
              <span class="badge ok">✓ Terkonfirmasi</span>
            <?php else: ?>
              <span class="badge warn">Belum konfirmasi</span>
            <?php endif; ?>
          </td>
          <td class="aksi-cell">
            <button class="btn-info-lms" data-nama="<?= h($nama_desa) ?>">
              🔑 Info LMS
            </button>
            <button class="btn-gradebook" data-nama="<?= h($nama_desa) ?>">
              📊 Gradebook
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Card view mobile -->
  <div class="cards">
    <?php if (empty($rows)): ?>
      <div class="card empty">Data tidak ditemukan.</div>
    <?php else: foreach ($rows as $r):
      $nama_desa  = strip_desa_prefix($r['firstname']);
      $kecamatan  = strip_kec_prefix($r['lastname']);
    ?>
      <div class="card">
        <div class="card-head">
          <div class="card-nama"><?= h($nama_desa) ?></div>
          <?php if ($r['sudah_konfirmasi']): ?>
            <span class="badge ok">✓</span>
          <?php else: ?>
            <span class="badge warn">!</span>
          <?php endif; ?>
        </div>
        <div class="card-row"><span>Kec.:</span><b><?= h($kecamatan) ?></b></div>
        <div class="card-row"><span>Kab.:</span><b><?= h($r['city']) ?></b></div>
        <div class="card-row"><span>Prov.:</span><b><?= h($r['institution']) ?></b></div>
        <div class="card-actions">
          <button class="btn-info-lms" data-nama="<?= h($nama_desa) ?>">
            🔑 Info LMS
          </button>
          <button class="btn-gradebook" data-nama="<?= h($nama_desa) ?>">
            📊 Gradebook
          </button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <nav class="pager">
    <?php
      $base = '?' . http_build_query(array_filter(['q' => $q]));
      $sep  = ($base === '?') ? '' : '&';
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
  <p class="pager-info">Halaman <b><?= $page ?></b> dari <b><?= $totalPages ?></b> &middot; Menampilkan <?= count($rows) ?> dari <?= number_format($total, 0, ',', '.') ?> desa</p>
  <?php endif; ?>

</main>

<footer class="foot">
  <div class="wrap">
    <p>Brilian 2026 &middot; LPPM Unsoed × BRI &middot; Butuh bantuan? Hubungi panitia: <a href="https://wa.me/6287887650978">Tri Wahyu (WA: 0878-8765-0978)</a></p>
  </div>
</footer>

<!-- Modal Konfirmasi/Edit Data (untuk pilih "Edit Data") -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="modal-overlay" data-close></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <button class="modal-close" data-close aria-label="Tutup">×</button>

    <div id="step-1" class="step">
      <h3 id="modal-title">Verifikasi Kode Desa</h3>
      <p>Untuk memastikan Anda perangkat desa <b id="m-nama"></b>, masukkan <b>10 digit Kode Desa</b> Anda:</p>
      <div class="form-group">
        <label for="kode-input">Kode Desa (10 digit angka)</label>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" id="kode-input" maxlength="10" placeholder="Contoh: 1207232006" autocomplete="off">
        <small class="hint">Kode desa Anda terdiri dari 10 angka, dapat dilihat dari surat undangan atau dari Kemendagri.</small>
      </div>
      <div id="step-1-msg" class="msg"></div>
      <div class="form-actions">
        <button class="btn-cancel" data-close>Batal</button>
        <button class="btn-primary" id="btn-verify">Lanjutkan »</button>
      </div>
    </div>

    <div id="step-2" class="step hidden">
      <h3>Konfirmasi Data Desa</h3>

      <div class="warn-box">
        <b>⚠ Perhatian:</b> Bacalah data di bawah dengan teliti. Bila ada yang salah, klik tombol <b>Perbaiki</b> di sebelah data tersebut. Pastikan semua benar sebelum submit.
      </div>

      <div id="revisi-banner" class="info-box hidden">
        ℹ Anda sudah pernah konfirmasi <b><span id="revisi-count">1</span>x</b>. Submit ini akan memperbarui data sebelumnya.
      </div>

      <div class="section-title">Data Desa</div>
      <div class="data-list">
        <div class="data-item" data-field="nama_desa">
          <div class="data-label">Nama Desa</div>
          <div class="data-row">
            <span class="data-value" id="v-nama_desa">-</span>
            <button type="button" class="btn-fix" data-target="nama_desa">Perbaiki</button>
          </div>
          <input type="text" class="data-input hidden" id="i-nama_desa" maxlength="255">
        </div>

        <div class="data-item" data-field="kec">
          <div class="data-label">Kecamatan</div>
          <div class="data-row">
            <span class="data-value" id="v-kec">-</span>
            <button type="button" class="btn-fix" data-target="kec">Perbaiki</button>
          </div>
          <input type="text" class="data-input hidden" id="i-kec" maxlength="255">
        </div>

        <div class="data-item" data-field="kab">
          <div class="data-label">Kabupaten</div>
          <div class="data-row">
            <span class="data-value" id="v-kab">-</span>
            <button type="button" class="btn-fix" data-target="kab">Perbaiki</button>
          </div>
          <input type="text" class="data-input hidden" id="i-kab" maxlength="255">
        </div>

        <div class="data-item" data-field="prov">
          <div class="data-label">Provinsi</div>
          <div class="data-row">
            <span class="data-value" id="v-prov">-</span>
            <button type="button" class="btn-fix" data-target="prov">Perbaiki</button>
          </div>
          <input type="text" class="data-input hidden" id="i-prov" maxlength="255">
        </div>
      </div>

      <div class="section-title">Data Kepala Desa</div>
      <div class="data-list">
        <div class="data-item" data-field="nama_kades">
          <div class="data-label">Nama Kepala Desa</div>
          <div class="data-row">
            <span class="data-value" id="v-nama_kades">-</span>
            <button type="button" class="btn-fix" data-target="nama_kades">Perbaiki</button>
          </div>
          <input type="text" class="data-input hidden" id="i-nama_kades" maxlength="255">
        </div>

        <div class="data-item" data-field="hp">
          <div class="data-label">Nomor HP Aktif <small>(harus valid &amp; aktif WA)</small></div>
          <div class="data-row">
            <span class="data-value" id="v-hp">-</span>
            <button type="button" class="btn-fix" data-target="hp">Perbaiki</button>
          </div>
          <input type="tel" inputmode="numeric" class="data-input hidden" id="i-hp" placeholder="08xxxxxxxxxx">
          <small class="hint hidden" id="h-hp">Contoh: 081234567890. Wajib aktif di WhatsApp.</small>
        </div>

        <div class="data-item" data-field="email">
          <div class="data-label">Email Aktif <small>(harus valid &amp; bisa diakses)</small></div>
          <div class="data-row">
            <span class="data-value" id="v-email">-</span>
            <button type="button" class="btn-fix" data-target="email">Perbaiki</button>
          </div>
          <input type="email" class="data-input hidden" id="i-email" placeholder="nama@gmail.com">
          <small class="hint hidden" id="h-email">Email ini akan menerima kredensial login LMS Brilian 2026.</small>
        </div>
      </div>

      <div class="confirm-box">
        <label class="checkbox-label">
          <input type="checkbox" id="chk-confirm">
          <span>Saya sudah memeriksa, semua data di atas sudah <b>BENAR</b> dan saya bertanggung jawab atas kebenarannya.</span>
        </label>
      </div>

      <div id="step-2-msg" class="msg"></div>

      <div class="form-actions">
        <button class="btn-cancel" data-close>Batal</button>
        <button class="btn-primary" id="btn-submit" disabled>✓ Submit Konfirmasi</button>
      </div>
    </div>

    <div id="step-3" class="step hidden">
      <div class="success-icon">✓</div>
      <h3 style="text-align:center">Berhasil Disimpan</h3>
      <p style="text-align:center">Terima kasih, data <b id="s-nama"></b> telah kami terima.</p>
      <div id="s-edited" class="info-box hidden">
        Anda memperbaiki <b><span id="s-edit-count">0</span></b> data: <span id="s-edit-list"></span>
      </div>
      <div class="form-actions center">
        <button class="btn-primary" data-close>Tutup</button>
      </div>
    </div>

  </div>
</div>

<!-- Modal Info LMS -->
<div id="modal-lms" class="modal" aria-hidden="true">
  <div class="modal-overlay" data-close-lms></div>
  <div class="modal-box" role="dialog" aria-modal="true">
    <button class="modal-close" data-close-lms aria-label="Tutup">×</button>

    <div id="lms-step-1" class="step">
      <h3>Verifikasi Kode Desa</h3>
      <p>Untuk membuka info LMS desa <b id="lms-m-nama"></b>, masukkan <b>10 digit Kode Desa</b> Anda:</p>
      <div class="form-group">
        <label for="lms-kode-input">Kode Desa (10 digit angka)</label>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" id="lms-kode-input" maxlength="10" placeholder="Contoh: 1207232006" autocomplete="off">
        <small class="hint">Kode desa Anda terdiri dari 10 angka.</small>
      </div>
      <div id="lms-step-1-msg" class="msg"></div>
      <div class="form-actions">
        <button class="btn-cancel" data-close-lms>Batal</button>
        <button class="btn-primary" id="lms-btn-verify">Lanjutkan »</button>
      </div>
    </div>

    <div id="lms-step-2" class="step hidden">
      <h3>Info LMS</h3>
      <p class="lms-desa-name" id="lms-desa-name">—</p>

      <div class="lms-choice-grid">
        <div class="lms-choice-card" id="lms-choice-cred">
          <div class="lms-choice-icon">🔑</div>
          <div class="lms-choice-title">Lihat Akun LMS</div>
          <div class="lms-choice-desc">Username & password login</div>
        </div>
        <div class="lms-choice-card" id="lms-choice-edit">
          <div class="lms-choice-icon">✏️</div>
          <div class="lms-choice-title">Edit Data</div>
          <div class="lms-choice-desc">Ubah No HP / Email</div>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn-cancel" data-close-lms>Tutup</button>
      </div>
    </div>

    <div id="lms-step-3" class="step hidden">
      <h3>Akun Login LMS Joglo</h3>
      <p class="lms-desa-name" id="lms-cred-desa">—</p>

      <div class="lms-cred-box">
        <div class="lms-cred-row">
          <div class="lms-cred-label">Username</div>
          <div class="lms-cred-value">
            <span id="lms-cred-username">—</span>
            <button type="button" class="lms-btn-copy" data-copy="lms-cred-username">Copy</button>
          </div>
        </div>
        <div class="lms-cred-row">
          <div class="lms-cred-label">Password</div>
          <div class="lms-cred-value">
            <span id="lms-cred-password">—</span>
            <button type="button" class="lms-btn-copy" data-copy="lms-cred-password">Copy</button>
          </div>
        </div>
      </div>

      <div class="lms-warn-note">
        ⚠️ Setelah login pertama kali, Anda akan diminta mengganti password. Pastikan password baru disimpan dengan baik.
      </div>

      <a href="https://joglo.unsoed.ac.id" target="_blank" rel="noopener" class="lms-link-btn">🚀 Buka LMS Joglo</a>

      <div class="form-actions">
        <button class="btn-cancel" data-close-lms>Tutup</button>
      </div>
    </div>

  </div>
</div>

<!-- ============================================================ -->
<!-- Modal Cek Presensi (read-only)                                -->
<!-- ============================================================ -->
<div id="modal-presensi" class="modal" aria-hidden="true">
  <div class="modal-overlay" data-close-pre></div>
  <div class="modal-box" role="dialog" aria-modal="true">
    <button class="modal-close" data-close-pre aria-label="Tutup">×</button>

    <!-- STEP 1: Verifikasi kode -->
    <div id="pre-step-1" class="step">
      <h3>Cek Status Presensi</h3>
      <p>Untuk melihat status kehadiran desa <b id="pre-m-nama"></b>, masukkan <b>10 digit Kode Desa</b> Anda:</p>
      <div class="form-group">
        <label for="pre-kode-input">Kode Desa (10 digit angka)</label>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" id="pre-kode-input" maxlength="10" placeholder="Contoh: 1207232006" autocomplete="off">
        <small class="hint">Kode desa Anda terdiri dari 10 angka.</small>
      </div>
      <div id="pre-step-1-msg" class="msg"></div>
      <div class="form-actions">
        <button class="btn-cancel" data-close-pre>Batal</button>
        <button class="btn-primary" id="pre-btn-verify">Lihat Status »</button>
      </div>
    </div>

    <!-- STEP 2: Tampilkan status presensi (read-only) -->
    <div id="pre-step-2" class="step hidden">
      <h3>Status Kehadiran</h3>
      <p class="pre-desa-name" id="pre-desa-name">—</p>

      <div class="info-box" style="margin-bottom:14px">
        ℹ Presensi dicatat <b>otomatis</b> oleh panitia berdasarkan kehadiran Anda di LMS Joglo (Activity Attendance).
        Pastikan login dan klik tombol kehadiran di Moodle saat sesi berlangsung.
      </div>

      <div class="pre-date-grid" id="pre-date-grid">
        <!-- diisi via JS -->
      </div>

      <div id="pre-step-2-msg" class="msg"></div>

      <div class="form-actions">
        <button class="btn-cancel" data-close-pre>Tutup</button>
      </div>
    </div>

  </div>
</div>

<!-- ============================================================ -->
<!-- Modal Gradebook (read-only, per desa, berbasis card)         -->
<!-- ============================================================ -->
<div id="modal-gradebook" class="modal" aria-hidden="true">
  <div class="modal-overlay" data-close-gb></div>
  <div class="modal-box" role="dialog" aria-modal="true">
    <button class="modal-close" data-close-gb aria-label="Tutup">×</button>

    <!-- STEP 1: Verifikasi kode -->
    <div id="gb-step-1" class="step">
      <h3>Lihat Grade Book</h3>
      <p>Untuk melihat nilai desa <b id="gb-m-nama"></b>, masukkan <b>10 digit Kode Desa</b> Anda:</p>
      <div class="form-group">
        <label for="gb-kode-input">Kode Desa (10 digit angka)</label>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" id="gb-kode-input" maxlength="10" placeholder="Contoh: 1207232006" autocomplete="off">
        <small class="hint">Kode desa Anda terdiri dari 10 angka.</small>
      </div>
      <div id="gb-step-1-msg" class="msg"></div>
      <div class="form-actions">
        <button class="btn-cancel" data-close-gb>Batal</button>
        <button class="btn-primary" id="gb-btn-verify">Lihat Nilai »</button>
      </div>
    </div>

    <!-- STEP 2: Tampilkan nilai (card) -->
    <div id="gb-step-2" class="step hidden">
      <h3>📊 Grade Book Desa</h3>
      <p class="pre-desa-name" id="gb-desa-name">—</p>
      <div id="gb-content"><!-- diisi via JS --></div>
      <div class="form-actions">
        <button class="btn-cancel" data-close-gb>Tutup</button>
      </div>
    </div>

  </div>
</div>

<script>window.CSRF_TOKEN = "<?= h($csrf) ?>";</script>
<script src="assets/app.js?v=7"></script>
<script src="assets/presensi.js?v=2"></script>
<script src="assets/gradebook.js?v=2"></script>
</body>
</html>

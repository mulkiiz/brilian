<?php
/**
 * Header navigasi admin Brilian 2026 — dipakai bersama oleh:
 *   admin.php, admin_presensi.php, gradebook.php, katalog/admin.php
 *
 * Cara pakai (letakkan tepat setelah <body>):
 *   <?php $ADMIN_NAV_ACTIVE='gradebook'; $ADMIN_NAV_BASE=''; require __DIR__.'/_admin_nav.php'; ?>
 *
 * $ADMIN_NAV_ACTIVE : konfirmasi | presensi | gradebook | katalog
 * $ADMIN_NAV_BASE   : '' jika file di root, '../' jika di folder katalog/
 */
$_base   = isset($ADMIN_NAV_BASE) ? $ADMIN_NAV_BASE : '';
$_active = isset($ADMIN_NAV_ACTIVE) ? $ADMIN_NAV_ACTIVE : '';

$_items = [
    'konfirmasi' => ['icon' => '🏠', 'label' => 'Konfirmasi', 'url' => $_base . 'admin.php'],
    'presensi'   => ['icon' => '📋', 'label' => 'Presensi',   'url' => $_base . 'admin_presensi.php'],
    'gradebook'  => ['icon' => '📊', 'label' => 'Grade Book', 'url' => $_base . 'gradebook.php'],
    'katalog'    => ['icon' => '📒', 'label' => 'Katalog',    'url' => $_base . 'katalog/admin.php'],
];
?>
<style>
  .gnav { background: linear-gradient(135deg,#0a1a33 0%,#13294f 55%,#1c3b6e 100%);
    box-shadow: 0 2px 14px rgba(10,26,51,.35); }
  .gnav-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center;
    flex-wrap: wrap; gap: 6px; padding: 0 14px; }
  .gnav-brand { display: flex; align-items: center; gap: 9px; color: #fff;
    font-weight: 800; font-size: 15px; letter-spacing: .5px; padding: 13px 16px 13px 0;
    margin-right: 6px; white-space: nowrap; }
  .gnav-brand .dot { width: 9px; height: 9px; border-radius: 50%;
    background: #ffd34d; box-shadow: 0 0 9px #ffd34d; }
  .gnav-tabs { display: flex; flex: 1; flex-wrap: wrap; gap: 4px; }
  .gnav-tab { display: flex; align-items: center; gap: 7px; text-decoration: none;
    color: #c8d6ee; font-size: 14px; font-weight: 600; padding: 10px 15px;
    border-radius: 9px 9px 0 0; transition: all .15s; position: relative; top: 1px; }
  .gnav-tab .ic { font-size: 16px; line-height: 1; }
  .gnav-tab:hover { background: rgba(255,255,255,.08); color: #fff; }
  .gnav-tab.active { background: #f4f6fb; color: #0a1a33; font-weight: 800; }
  .gnav-tab.active .ic { filter: none; }
  .gnav-right { display: flex; align-items: center; gap: 4px; }
  .gnav-link { color: #ffd34d; text-decoration: none; font-size: 13px;
    font-weight: 600; padding: 9px 12px; border-radius: 8px; white-space: nowrap; }
  .gnav-link:hover { background: rgba(255,211,77,.14); }
  .gnav-link.exit { color: #ff9b9b; }
  @media (max-width: 640px) {
    .gnav-brand { font-size: 13px; padding-right: 8px; }
    .gnav-tab { padding: 9px 11px; font-size: 13px; }
    .gnav-tab .label-txt { display: none; }
    .gnav-tab .ic { font-size: 18px; }
  }
</style>
<div class="gnav">
  <div class="gnav-inner">
    <div class="gnav-brand"><span class="dot"></span>BRILIAN&nbsp;2026</div>
    <nav class="gnav-tabs">
      <?php foreach ($_items as $key => $it): ?>
        <a class="gnav-tab <?= $_active === $key ? 'active' : '' ?>" href="<?= h($it['url']) ?>">
          <span class="ic"><?= $it['icon'] ?></span>
          <span class="label-txt"><?= h($it['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="gnav-right">
      <a class="gnav-link" href="<?= h($_base) ?>index.php">↗ Lihat User</a>
      <a class="gnav-link exit" href="<?= h($_base) ?>admin.php?logout=1">Keluar</a>
    </div>
  </div>
</div>

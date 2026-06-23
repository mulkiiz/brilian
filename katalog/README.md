# Katalog Desa Brilian 2026 — Prototype

Modul self-contained di subfolder `katalog/`. Tidak menyentuh file `index.php` atau struktur portal yang sudah ada.

## URL yang dipakai

- **`brilian.jurnalsinta.id/katalog`** atau **`brilian.jurnalsinta.id/katalog/`**
  → Otomatis buka modal popup verifikasi kode desa. Setelah berhasil → redirect ke editor.
- **`brilian.jurnalsinta.id/katalog/admin.php`**
  → Dashboard admin (pakai sesi admin dari `../admin.php`).

## Struktur file

```
brilian.jurnalsinta.id/
├── (file existing tidak diubah)
└── katalog/                  ← upload folder ini saja
    ├── index.php             ← landing + modal popup auto-open
    ├── editor.php            ← editor 11 section
    ├── preview.php           ← preview untuk desa
    ├── api.php               ← AJAX endpoint
    ├── admin.php             ← dashboard admin katalog
    ├── compile.php           ← HTML jilid siap PDF
    ├── config.php            ← include ../config.php + definisi 11 section
    ├── schema.sql            ← SQL tabel baru
    ├── README.md
    ├── assets/
    │   ├── katalog.css
    │   ├── landing.js        ← logic modal popup verifikasi
    │   └── editor.js         ← logic Quill + drag-drop upload
    └── uploads/              ← foto desa (.htaccess blokir PHP exec)
        ├── .htaccess
        └── index.html
```

## Cara pasang

### 1. Import SQL
phpMyAdmin → DB `jurz2196_brilian_bot` → tab **SQL** → paste isi `schema.sql` → **Go**.
Terbuat 3 tabel: `katalog_desa`, `katalog_foto`, `katalog_status`.

### 2. Upload folder via File Manager
- Buka File Manager cPanel ke root `brilian.jurnalsinta.id`
- Upload folder `katalog/` apa adanya (struktur sama persis)
- Pastikan `katalog/uploads/` permission **755** (atau **775** kalau strict)

### 3. Test
- Buka `https://brilian.jurnalsinta.id/katalog` → langsung popup minta kode desa
- Masukkan kode desa valid (10 digit) → otomatis ke `editor.php`
- Isi 1 section dengan Quill, upload 1 foto via drag/drop → klik Save → muncul ✓ Tersimpan
- Klik **👁 Preview** untuk lihat hasil
- Untuk admin: login di `../admin.php` (sesi existing) → buka `katalog/admin.php` atau langsung URL `brilian.jurnalsinta.id/katalog/admin.php`
- Klik **📖 Kompilasi Semua Desa** → tab baru → **Ctrl+P** → **Save as PDF**

## Pola interaksi (prototype)

1. Desa peserta dapat link `brilian.jurnalsinta.id/katalog` (lewat WA blast, undangan, dll)
2. Buka URL → muncul modal popup **Verifikasi Kode Desa** (background landing page diredam)
3. Input 10 digit kode → tombol Lanjutkan
4. Jika valid → sesi tersimpan (~60 menit default PHP) → redirect ke `editor.php`
5. Editor accordion 11 section, tiap section ada Quill + dropzone
6. Bisa keluar via tombol **Keluar** di header editor → balik ke index/popup

## Keamanan

- **Sanitasi HTML Quill**: whitelist tag aman (`<p>`, `<strong>`, `<em>`, `<u>`, `<a>`, `<ul>`, `<ol>`, `<li>`, `<h1-4>`, `<blockquote>`, `<span>`). Tag berbahaya seperti `<script>`, `<iframe>`, `<style>`, `<form>`, `<img onerror>`, `onclick=`, `javascript:` URLs semuanya dibersihkan. Sudah ditest.
- **Validasi foto**: cek MIME asli pakai `finfo`, bukan extension. Hanya JPG/PNG/WebP.
- **Anti PHP execution di upload**: `.htaccess` di `uploads/` blokir `*.php`, `*.sh`, dll.
- **Rate limit verifikasi**: pakai fungsi `check_rate_limit()` & `log_attempt()` dari `../config.php` (sama dengan Cek Presensi).
- **CSRF**: semua POST cek token, pola sama dengan `submit.php`/`lms_info.php`.
- **Folder per desa**: foto dipisah `uploads/{kode_desa}/`, filename pakai random hex (anti-guess).

## Catatan

- Foto tidak di-resize otomatis di server (PHP 7.3 GD opsional). Batas 250 KB cukup untuk foto 800×600 quality 75. Bisa diarahkan ke tools online seperti tinypng.com kalau ada desa yang foto-nya selalu kebesaran.
- PDF generator: pakai browser **Print → Save as PDF** karena reliable dan tidak butuh library tambahan. Hasil sudah disesuaikan dengan `@page A4` dan `@media print`.
- Setelah prototype OK, kalau perlu PDF langsung tanpa browser, bisa ditambahkan mPDF (sama caranya seperti PHPMailer dulu — upload zip manual).

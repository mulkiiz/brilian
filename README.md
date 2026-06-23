# Konfirmasi Data Desa - Brilian 2026

Aplikasi mandiri untuk Kepala Desa peserta Brilian 2026 mengonfirmasi nomor HP & email mereka.

## Cara Install

### 1. Upload semua file ke cPanel
Upload folder ini ke salah satu path berikut (pilih salah satu):
- `public_html/miz/konfirmasi/` → akses: `https://miz.jurnalsinta.id/konfirmasi/`
- atau buat subdomain baru, misal `konfirmasi.jurnalsinta.id`

### 2. Edit `config.php`
Wajib edit dan sesuaikan:
- `DB_USER` & `DB_PASS` — kredensial DB `jurz2196_brilian_bot`
- `TBL_DESA` — nama tabel desa di DB (cek di phpMyAdmin)
- `COL_KODE`, `COL_NAMA`, `COL_KEC`, `COL_KAB`, `COL_PROV`, `COL_HP`, `COL_EMAIL` — nama kolom sesuai schema desa di DB
- `ADMIN_PASS` — ganti dari default `brilian2026`

### 3. Jalankan `install.sql`
Buka phpMyAdmin → pilih database `jurz2196_brilian_bot` → tab SQL → paste isi `install.sql` → Go.

Akan dibuat 2 tabel baru:
- `konfirmasi_desa` — hasil konfirmasi (data asli desa TIDAK diubah)
- `konfirmasi_attempts` — log percobaan untuk rate limit

### 4. Test
1. Buka `https://miz.jurnalsinta.id/konfirmasi/` → harus muncul tabel desa
2. Klik **Konfirmasi** di salah satu desa → masukkan kode 10 digit → konfirmasi data
3. Buka `admin.php` (login: `admin` / password yang Anda set) → harus terlihat hasilnya

## Alur User (Kepala Desa)

1. Buka link aplikasi
2. Cari nama desa di kotak pencarian
3. Klik tombol **Konfirmasi**
4. Modal popup → masukkan **10 digit Kode Desa** untuk verifikasi
5. Periksa nama desa, kec, kab, prov (read-only)
6. Edit nomor HP & email jika perlu
7. Simpan ✓

## Fitur Keamanan

- CSRF token di semua form POST
- Rate limit 10x percobaan salah kode/IP/jam
- Prepared statement (anti SQL injection)
- Data desa asli **tidak diubah** — hasil konfirmasi disimpan di tabel terpisah
- Audit log: IP, user agent, timestamp tersimpan
- HTTPS dipaksa via .htaccess

## Catatan Penting

- **Pre-fill HP/email**: jika data lama berupa placeholder (`0000`, `email_tidak_valid+...`), field dikosongkan supaya user mengisi dari nol
- **Format HP**: user bebas input `081234...`, sistem otomatis normalisasi ke `6281234...` saat disimpan
- **Re-konfirmasi**: jika sudah pernah konfirmasi, klik tombol akan jadi "Edit" — data lama jadi pre-fill

## Export Data

Admin → tombol **⬇ Export CSV** → file CSV lengkap dengan BOM UTF-8 (siap dibuka di Excel).

## Troubleshooting

**Tabel desa nama kolomnya beda?**
Edit `config.php` bagian `COL_*`. Misal jika kolom HP namanya `phone_number`:
```php
define('COL_HP', 'phone_number');
```

**Modal tidak muncul?**
Cek browser console (F12). Pastikan `assets/app.js` ter-load dan tidak ada error JS.

**Error DB connection?**
Cek `DB_USER` & `DB_PASS` di `config.php`. Di cPanel biasanya format `jurz2196_xxx`.

**Mau tambah/cek tabel desa existing?**
Buka phpMyAdmin → DB `jurz2196_brilian_bot` → lihat list tabel. Tabel utama desa seharusnya ada di sana dari setup bot Brilian sebelumnya.

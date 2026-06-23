# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"Brilian 2026" — a suite of PHP admin tools for managing village-head (Kepala Desa) participants of a training program. Each participant = one *desa* (village) keyed by a 10-digit `kode_desa`. Plain PHP 7.3 + mysqli, **no Composer, no build step, no test suite**. Designed to run on cPanel shared hosting; locally it runs under XAMPP from `c:\xampp\htdocs\brilian`. UI text and comments are Indonesian.

## Running / developing

- No build, no lint, no tests. Edit `.php` and reload via XAMPP (`http://localhost/brilian/`).
- DB is MySQL/MariaDB `jurz2196_brilian_bot`. Credentials + table/column names live in `config.php` (gitignored — copy from a working instance, don't commit). Local default user `root`, empty pass.
- Katalog module has its own DDL: import `katalog/schema.sql` into the same DB.
- Cron endpoints are token-gated (`hash_equals` against a `CRON_TOKEN` constant in the file) and accept the token via `?token=` or `argv[1]`. Run manually e.g. `php cron_backfill_lms.php <token>`.

## Architecture

**One shared `config.php`** is the spine: it opens the global `$mysqli` connection and defines every shared helper. Almost all files `require_once __DIR__.'/config.php'` first. Key helpers (use these, don't reinvent):
- `h()` — htmlspecialchars escaping for output.
- `csrf_token()` / `csrf_check()` — every POST endpoint must validate CSRF.
- `check_rate_limit()` / `log_attempt()` — gate code-entry attempts via the `konfirmasi_attempts` table (10/IP/hour).
- `normalize_hp()` — phone normalization `08xx`/`8xx` → `628xx`; `valid_hp()`, `valid_email()`.
- `get_ip()`, `json_response()`.
- The `COL_*` / `TBL_DESA` constants indirect column names so the source table schema can vary per deployment — **reference columns through these constants, not hardcoded names**, in the konfirmasi flow.

**Four admin subsystems** share one admin session (`$_SESSION['admin']`, set in `admin.php`) and the common nav bar `_admin_nav.php` (set `$ADMIN_NAV_ACTIVE` + `$ADMIN_NAV_BASE` before requiring it):
1. **Konfirmasi** (`index.php`, `verify.php`, `submit.php`, `admin.php`) — public-facing. Villager searches the source table `desa_peserta`, enters their 10-digit `kode_desa` to verify, then confirms/edits HP + email. Results are written to a **separate** `konfirmasi_desa` table; the source data is never mutated.
2. **Presensi** (`presensi*.php`, `admin_presensi.php`) — imports Moodle "Activity Attendance" `.xlsx` exports via `XlsxReader.php`, fuzzy-matches village names, parses session-header dates.
3. **Gradebook** (`gradebook.php`, `gradebook_view.php`) — aggregates per-day pre/post-test CSVs, attendance, 4 task statuses, and final activeness score across 7 fixed training days into a 545-village recap; exports `.xls`.
4. **Katalog** (`katalog/`) — self-contained village-profile editor (11 sections, 10 narrative + 1 photo). Has its own session key (`$_SESSION['katalog_kode']`, see `katalog_require_auth()`), its own `katalog/config.php` (which still includes the root config), its own tables (`katalog_*`), and a POST API in `katalog/api.php` (action-dispatched). Rich-text narration is sanitized with `katalog_sanitize_html()` (regex strip of script/style/iframe/on*-handlers + tag allowlist) — route any user HTML through it.

**Moodle/LMS integration** is the downstream consumer:
- `lms_password` for each village = `REVERSE(kode_desa)`; Moodle `username` = `kode_desa`. `cron_backfill_lms.php` backfills the password column.
- `cron_generate_moodle_csv.php` emits a Moodle bulk-user CSV (UTF-8 BOM, CRLF) from confirmed rows into `user_moodle/`, mapping confirmation fields to Moodle columns (firstname=`Desa {nama}`, lastname=`Kec. {kecamatan}`, city=`Kabupaten {kabupaten}`, etc.).
- `XlsxReader.php` and `PdfTextReader.php` are dependency-free pure-PHP parsers (ZipArchive+SimpleXML; FlateDecode + ToUnicode CMap) for ingesting Moodle exports. PdfTextReader handles only text PDFs, not scans.

## Conventions & guardrails

- Always parameterize SQL with prepared statements (`bind_param`) — the codebase does this everywhere; match it.
- Never write to the source `desa_peserta` table from the confirmation flow; new state goes in `konfirmasi_desa` / `katalog_*`.
- `.htaccess` forces HTTPS and denies direct web access to `*.sql`, `*.md`, and `config.php` — keep secrets in those file types.
- Don't commit `config.php`, `uploads/`, or `*.log` (see `.gitignore`). `error_log` in the repo root is runtime noise, not source.

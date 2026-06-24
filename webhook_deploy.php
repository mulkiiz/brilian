<?php
/**
 * Deploy webhook — dipanggil GitHub Actions setelah push.
 * Token disimpan di /home/jurz2196/.deploy_token (luar public_html).
 */

// Baca secret dari file di luar web root
$token_file = '/home/jurz2196/.deploy_token';
if (!is_readable($token_file)) {
    http_response_code(500);
    die(json_encode(['error' => 'Token file tidak ditemukan di server.']));
}

$expected = trim(file_get_contents($token_file));
$provided = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if (empty($expected) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden.']));
}

// Jalankan deploy
$repo = '/home/jurz2196/repo/brilian';
$dest = '/home/jurz2196/public_html/brilian.jurnalsinta.id';
$log  = [];

exec("cd {$repo} && git pull origin master 2>&1", $log, $code);

if ($code !== 0) {
    http_response_code(500);
    die(json_encode(['error' => 'git pull gagal.', 'log' => $log]));
}

// Pastikan folder target ada
foreach (['assets', 'katalog', 'katalog/assets', 'user_moodle'] as $d) {
    exec("/bin/mkdir -p {$dest}/{$d} 2>&1", $log);
}

// Copy folder ke production (config.php tidak ikut karena di-gitignore)
$dirs = ['assets', 'katalog'];
foreach ($dirs as $d) {
    if (is_dir("{$repo}/{$d}")) {
        exec("/bin/cp -R {$repo}/{$d}/. {$dest}/{$d}/ 2>&1", $log);
    }
}

// Copy file di root (config.php di-gitignore, tidak ikut)
$files = [
    'index.php', 'submit.php', 'verify.php', 'admin.php', '_admin_nav.php',
    'presensi.php', 'presensi_klaim.php', 'presensi_status.php', 'presensi_upload.php',
    'admin_presensi.php', 'gradebook.php', 'gradebook_view.php', 'gradebook_status.php', 'lms_info.php',
    'cron_backfill_lms.php', 'cron_generate_moodle_csv.php',
    'XlsxReader.php', 'PdfTextReader.php', '.htaccess', 'webhook_deploy.php',
];
foreach ($files as $f) {
    if (file_exists("{$repo}/{$f}")) {
        exec("/bin/cp {$repo}/{$f} {$dest}/{$f} 2>&1", $log);
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'log' => $log]);

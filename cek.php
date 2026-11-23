<?php
// Halaman diagnosa sementara. HAPUS setelah masalah selesai. Tidak menampilkan password.
header('Content-Type: text/plain; charset=utf-8');
echo "PHP: " . PHP_VERSION . "\n";
foreach (['pdo_mysql', 'mbstring', 'zip', 'ctype', 'json', 'curl'] as $e) echo str_pad($e, 10) . (extension_loaded($e) ? 'OK' : 'TIDAK AKTIF') . "\n";
echo "\nDOCUMENT_ROOT: " . (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '-') . "\n";
echo "Folder aplikasi: " . __DIR__ . "\n";
foreach (['inc/core.php', 'inc/reports.php', 'inc/importer.php', 'api.php', 'install.php'] as $f) echo str_pad($f, 20) . (is_file(__DIR__ . '/' . $f) ? 'ada' : 'TIDAK ADA') . "\n";
echo "uploads dapat ditulis: " . (is_writable(__DIR__ . '/uploads') ? 'ya' : 'TIDAK') . "\n\n";
try {
  ini_set('display_errors', '1'); error_reporting(E_ALL);
  require __DIR__ . '/inc/core.php';
  echo "core.php dimuat: OK\n";
  echo "Folder yang dicari untuk ims_config.php:\n  " . implode("\n  ", config_dirs()) . "\n";
  $c = config_path();
  echo "ims_config.php: " . ($c ? "ditemukan di $c" : "TIDAK DITEMUKAN") . "\n";
  if ($c) {
    cfg('db');
    echo "Koneksi database: ";
    try { db(); echo "OK\n"; $n = val('SELECT COUNT(*) FROM projects'); echo "Tabel projects: $n baris\n"; }
    catch (Throwable $e) { echo "GAGAL - " . $e->getMessage() . "\n"; }

    echo "\nVersi berkas di server:\n";
    $imp = (string)@file_get_contents(__DIR__ . '/inc/importer.php'); $api = (string)@file_get_contents(__DIR__ . '/api.php'); $rep = (string)@file_get_contents(__DIR__ . '/inc/reports.php');
    echo "  importer.php mendukung import Master Unit : " . (strpos($imp, 'function imp_units') !== false ? 'ya' : 'TIDAK (berkas lama, unggah ulang)') . "\n";
    echo "  api.php: import untuk Admin dan Manager   : " . (preg_match("/'r_importRun', 'w' => 1, 'mgr' => 1/", $api) ? 'ya' : 'TIDAK (berkas lama, unggah ulang)') . "\n";
    echo "  api.php: hapus riwayat penggantian        : " . (strpos($api, 'r_deleteReplacement') !== false ? 'ya' : 'TIDAK (berkas lama)') . "\n";
    echo "  api.php + core.php: status Not Required    : " . (strpos($api, 'Not Required') !== false && strpos((string)@file_get_contents(__DIR__ . '/inc/core.php'), 'STATUSES') !== false ? 'ya' : 'TIDAK (berkas lama)') . "\n";
    echo "  mode debug (ims_config.php)               : " . (cfg('debug', false) ? 'AKTIF' : 'mati') . "\n";

    $k = (string)cfg('install_key', '');
    if ($k !== '' && isset($_GET['key']) && hash_equals($k, (string)$_GET['key'])) {
      echo "\nIsi database:\n";
      foreach (['projects', 'users', 'units', 'devices', 'installations', 'replacements'] as $t) echo "  " . str_pad($t, 14) . val("SELECT COUNT(*) FROM $t") . " baris\n";
      echo "\nJumlah unit per project:\n";
      foreach (rows('SELECT p.name, p.id, COUNT(u.id) c FROM projects p LEFT JOIN units u ON u.project_id = p.id GROUP BY p.id, p.name') as $r) echo "  " . $r['name'] . " (" . substr($r['id'], 0, 8) . "...): " . $r['c'] . " unit\n";
      echo "\nContoh 10 unit terakhir di tabel units:\n";
      foreach (rows('SELECT unit_no, type, active, project_id FROM units LIMIT 10') as $r) echo "  " . $r['unit_no'] . " | " . $r['type'] . " | aktif=" . $r['active'] . " | project " . substr($r['project_id'], 0, 8) . "...\n";
    } else {
      echo "\n(Untuk melihat isi database, buka alamat ini dengan tambahan ?key=ISI_install_key)\n";
    }
  }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n"; }

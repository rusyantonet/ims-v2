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
  }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n"; }

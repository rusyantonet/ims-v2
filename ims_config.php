<?php
// Salin file ini menjadi  ims_config.php  dan taruh DI ATAS folder public_html
// (mis. /home/NAMAUSER/ims_config.php) agar tidak bisa dibuka lewat browser.
return [
  // Database (dari cPanel > MySQL Database Wizard). Nama biasanya berawalan username cPanel.
  'host' => 'localhost',
  'db'   => 'namaakun_ims',
  'user' => 'namaakun_imsuser',
  'pass' => 'PASSWORD_DATABASE',

  // Kunci untuk membuka install.php (isi acak panjang). Hapus install.php setelah selesai dipakai.
  'install_key' => 'GANTI_DENGAN_TEKS_ACAK_PANJANG',

  // Salt lama dari Apps Script (Project Settings > Script properties > SALT).
  // Diisi agar password user lama tetap berlaku setelah migrasi (otomatis di-upgrade ke bcrypt saat login pertama).
  // Kosongkan jika tidak mau; user harus direset passwordnya oleh Admin.
  'legacy_salt' => '',

  // Lama sesi login (detik). Default 6 jam seperti versi Apps Script.
  'session_seconds' => 6 * 3600,

  // Opsional: URL dasar situs untuk tautan foto di laporan, mis. 'https://ims.contoh.com'. Kosongkan = otomatis.
  'base_url' => '',

  // Simpan foto baru ke Google Drive (opsional). Isi dari hasil deploy photo-endpoint.gs. Kosongkan = simpan di folder uploads/ hosting.
  'photo_drive_url'      => '',      // contoh: 'https://script.google.com/macros/s/XXXX/exec'
  'photo_drive_secret'   => '',      // harus sama dengan SECRET yang ditampilkan setup() di Apps Script
  'photo_drive_fallback' => true,    // true = bila Drive gagal, foto disimpan lokal agar tidak hilang; false = tampilkan error

  'timezone' => 'Asia/Makassar',   // WITA (Balikpapan). Ganti 'Asia/Jayapura' untuk Papua / lokasi Freeport.
];

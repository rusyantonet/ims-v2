<?php
// Fungsi inti: konfigurasi, koneksi database, helper umum. Dipakai api.php dan install.php.
// Target: PHP 7.4+

error_reporting(E_ALL);
ini_set('display_errors', '0');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

class ApiError extends Exception {}

const APP_TITLE        = 'Dokumentasi Instalasi Unit';
const REPORT_SUBTITLE  = 'MineSafeDrive - PT. Freeport Indonesia';   // cadangan jika nama project kosong
const REQUIRE_PHOTO    = false;      // true = foto wajib saat status Installed
const MAX_REPORT_PHOTOS = 30;
const REASONS = ['Rusak', 'Hilang', 'Upgrade', 'Lainnya'];
const ROLES   = ['Admin', 'Manager', 'Engineer', 'Viewer'];
const GUIDE_ROLES = ['Manager', 'Engineer', 'Viewer'];
const DEFAULT_DEVICES = [
  ['DMS Camera', 1, 1], ['ADAS Camera', 1, 1], ['INCAB Camera', 1, 1], ['GPIO Box', 1, 1],
  ['Seat Vibrator', 0, 0], ['Buzzer', 0, 0], ['Strobe Lamp', 0, 0], ['GPS Antenna', 0, 0],
  ['GSM Antenna', 0, 0], ['Wifi Antenna', 0, 0], ['Bluetooth Antenna', 0, 0],
  ['Source Power', 0, 0], ['SIM Card', 1, 0], ['SSD', 0, 0]
];

/* ---------- konfigurasi ---------- */
function config_dirs() {
  $starts = [];
  if (!empty($_SERVER['DOCUMENT_ROOT'])) $starts[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
  $starts[] = dirname(__DIR__);
  $dirs = [];
  foreach ($starts as $d) {
    for ($i = 0; $i < 4; $i++) {
      $d = dirname($d);
      if ($d === '' || $d === '.' || $d === '/' || $d === '\\') break;
      $dirs[$d] = 1;
    }
  }
  return array_keys($dirs);
}
function config_path() {
  foreach (config_dirs() as $d) if (is_file($d . '/ims_config.php')) return $d . '/ims_config.php';
  return null;
}
function cfg($key, $default = null) {
  static $cfg = null;
  if ($cfg === null) {
    $f = config_path();
    if (!$f) throw new ApiError('File ims_config.php tidak ditemukan. Taruh di salah satu folder ini: ' . implode(' , ', config_dirs()));
    $cfg = require $f;
    if (!is_array($cfg)) throw new ApiError('ims_config.php tidak valid.');
    date_default_timezone_set(isset($cfg['timezone']) ? $cfg['timezone'] : 'Asia/Makassar');
  }
  return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/* ---------- database ---------- */
function db() {
  static $pdo = null;
  if ($pdo === null) {
    cfg('db');   // memuat konfigurasi + timezone
    $dsn = 'mysql:host=' . cfg('host', 'localhost') . ';dbname=' . cfg('db') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, cfg('user'), cfg('pass'), [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
  }
  return $pdo;
}
function q($sql, $args = []) { $st = db()->prepare($sql); $st->execute(array_values($args)); return $st; }
function rows($sql, $args = []) { return q($sql, $args)->fetchAll(); }
function row1($sql, $args = []) { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function val($sql, $args = []) { $r = q($sql, $args)->fetch(PDO::FETCH_NUM); return $r === false ? null : $r[0]; }
function placeholders($arr) { return implode(',', array_fill(0, count($arr), '?')); }

/* ---------- helper umum ---------- */
function fail($msg) { throw new ApiError($msg); }
function uuid() {
  $b = random_bytes(16);
  $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
  $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
  $h = bin2hex($b);
  return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
function now_iso() { return gmdate('Y-m-d\TH:i:s') . '.000Z'; }
function bool_($v) { return $v === true || $v === 1 || $v === '1' || strtolower((string)$v) === 'true'; }
function to_ids($v) {
  $a = is_array($v) ? $v : explode(',', (string)$v);
  $o = [];
  foreach ($a as $s) { $s = trim((string)$s); if ($s !== '') $o[] = $s; }
  return $o;
}
function str_($v) { return $v === null ? '' : trim((string)$v); }
function is_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1; }
function natcmp($a, $b) { return strnatcasecmp((string)$a, (string)$b); }
function json_flags() { return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE; }
function jenc($v) { return json_encode($v, json_flags()); }
function jdec_arr($s) { $d = json_decode((string)($s === null || $s === '' ? '[]' : $s), true); return is_array($d) ? $d : []; }

function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : ''; }

function base_url() {
  $b = cfg('base_url', '');
  if ($b) return rtrim($b, '/');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $dir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
  return ($https ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . $dir;
}
function upload_dir() {
  $d = dirname(__DIR__) . '/uploads';
  if (!is_dir($d)) @mkdir($d, 0755, true);
  return $d;
}
// URL foto untuk laporan: file lokal (loc_xxx) atau Google Drive (data lama)
function photo_url($id) {
  $id = (string)$id;
  if (strpos($id, 'loc_') === 0 && preg_match('/^loc_[0-9a-f]{32}$/', $id)) return base_url() . '/uploads/' . substr($id, 4) . '.jpg';
  if (preg_match('/^[\w-]{10,}$/', $id)) return 'https://drive.google.com/thumbnail?sz=w600&id=' . $id;
  return '';
}

/* ---------- password ---------- */
function verify_pw($u, $pw) {
  $h = (string)$u['pass_hash'];
  if (strlen($h) === 64 && ctype_xdigit($h)) {          // hash lama dari Apps Script: sha256(SALT + password)
    $salt = (string)cfg('legacy_salt', '');
    if ($salt === '') return false;
    return hash_equals(strtolower($h), hash('sha256', $salt . $pw));
  }
  return password_verify($pw, $h);
}

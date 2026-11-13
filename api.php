<?php
// API IMS: satu pintu untuk semua aksi (pengganti function api() di Code.gs).
// Request: POST JSON { action, token, p }   Response: { ok, data } atau { ok:false, error }
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/reports.php';
require __DIR__ . '/inc/importer.php';

$GLOBALS['CUR'] = ['id' => '', 'name' => ''];
function cur_id() { return $GLOBALS['CUR']['id']; }
function cur_name() { return $GLOBALS['CUR']['name']; }

function out($arr) {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
  }
  echo jenc($arr);
  exit;
}

/* ============ OUTPUT MAPPER ============ */
function project_out($x) { return ['id' => $x['id'], 'name' => $x['name'], 'active' => (bool)$x['active']]; }
function unit_out($u) { return ['id' => $u['id'], 'unitNo' => $u['unit_no'], 'type' => $u['type'], 'notes' => $u['notes'], 'active' => (bool)$u['active']]; }
function device_out($d) { return ['id' => $d['id'], 'name' => $d['name'], 'needSN' => (bool)$d['need_sn'], 'needPhoto' => (bool)$d['need_photo'], 'active' => (bool)$d['active'], 'sort' => (int)$d['sort']]; }
function user_projects($uid) { $o = []; foreach (rows('SELECT project_id FROM user_projects WHERE user_id = ?', [$uid]) as $r) $o[] = $r['project_id']; return $o; }
function pub_user($u) { return ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'active' => (bool)$u['active'], 'projectIds' => user_projects($u['id'])]; }
function can_access_project($u, $pid) { return $u['role'] === 'Admin' || in_array($pid, user_projects($u['id']), true); }
function inst_out($i) {
  $d = jdec_arr($i['details']);
  $n = 0; foreach ($d as $x) if (isset($x['status']) && $x['status'] === 'Installed') $n++;
  $t = count($d);
  return ['id' => $i['id'], 'unitId' => $i['unit_id'], 'unitNo' => $i['unit_no'], 'date' => $i['date'], 'remarks' => $i['remarks'], 'details' => $d,
          'installed' => $n, 'total' => $t, 'pct' => $t ? (int)round($n * 100 / $t) : 0, 'createdBy' => $i['created_by'], 'updatedAt' => $i['updated_at']];
}
function guide_out($g) {
  return ['id' => $g['id'], 'title' => $g['title'], 'body' => $g['body'], 'roles' => to_ids($g['roles']), 'public' => (bool)$g['is_public'],
          'active' => (bool)$g['active'], 'sort' => (int)$g['sort'], 'updatedAt' => $g['updated_at'], 'updatedBy' => $g['updated_by']];
}

/* ============ AUTH ============ */
function login_($p) {
  $un = strtolower(trim((string)(isset($p['username']) ? $p['username'] : '')));
  $pw = (string)(isset($p['password']) ? $p['password'] : '');
  $ip = client_ip(); $since = time() - 900;
  if ((int)val('SELECT COUNT(*) FROM login_fails WHERE ip = ? AND ts > ?', [$ip, $since]) >= 15
      || (int)val('SELECT COUNT(*) FROM login_fails WHERE username = ? AND ts > ?', [$un, $since]) >= 10)
    throw new ApiError('Terlalu banyak percobaan login gagal. Coba lagi dalam 15 menit.');
  $u = $un === '' ? null : row1('SELECT * FROM users WHERE username = ?', [$un]);
  if (!$u || !$u['active'] || !verify_pw($u, $pw)) {
    q('INSERT INTO login_fails (ip, username, ts) VALUES (?,?,?)', [$ip, substr($un, 0, 100), time()]);
    throw new ApiError('Username atau password salah, atau akun nonaktif.');
  }
  $pr = row1('SELECT * FROM projects WHERE id = ?', [isset($p['projectId']) ? (string)$p['projectId'] : '']);
  if (!$pr || !$pr['active']) throw new ApiError('Pilih project yang valid.');
  if (!can_access_project($u, $pr['id'])) throw new ApiError('Anda tidak memiliki akses ke project ini.');
  // upgrade hash lama (sha256) ke bcrypt
  if (strlen($u['pass_hash']) === 64 && ctype_xdigit($u['pass_hash']))
    q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
  q('DELETE FROM login_fails WHERE username = ? OR ts < ?', [$un, $since]);
  q('DELETE FROM sessions WHERE expires_at < ?', [time()]);
  $token = bin2hex(random_bytes(32));
  q('INSERT INTO sessions (token, user_id, project_id, expires_at) VALUES (?,?,?,?)', [$token, $u['id'], $pr['id'], time() + (int)cfg('session_seconds', 6 * 3600)]);
  $user = pub_user($u);
  $user['projectId'] = $pr['id']; $user['projectName'] = $pr['name'];
  return ['token' => $token, 'user' => $user];
}
function auth_($token) {
  $s = $token ? row1('SELECT * FROM sessions WHERE token = ? AND expires_at > ?', [$token, time()]) : null;
  $u = $s ? row1('SELECT * FROM users WHERE id = ?', [$s['user_id']]) : null;
  if (!$u || !$u['active']) throw new ApiError('Sesi berakhir, silakan login kembali.');
  return [$u, $s];
}

/* ============ MASTER PROJECT ============ */
function seed_devices($pid) {
  foreach (DEFAULT_DEVICES as $i => $d)
    q('INSERT INTO devices (id, project_id, name, need_sn, need_photo, active, sort) VALUES (?,?,?,?,?,1,?)', [uuid(), $pid, $d[0], $d[1], $d[2], $i + 1]);
}
function copy_devices($from, $to) {
  foreach (rows('SELECT * FROM devices WHERE project_id = ? ORDER BY sort', [$from]) as $d)
    q('INSERT INTO devices (id, project_id, name, need_sn, need_photo, active, sort) VALUES (?,?,?,?,?,?,?)', [uuid(), $to, $d['name'], $d['need_sn'], $d['need_photo'], $d['active'], $d['sort']]);
}
function r_listProjects($p, $u) { return array_map('project_out', rows('SELECT * FROM projects ORDER BY created_at, name')); }
function r_assignableProjects($p, $u) {
  return array_values(array_map('project_out', array_filter(rows('SELECT * FROM projects ORDER BY created_at, name'), function ($x) use ($u) { return can_access_project($u, $x['id']); })));
}
function r_saveProject($p, $u) {
  $name = str_(isset($p['name']) ? $p['name'] : '');
  if ($name === '') fail('Nama project wajib diisi');
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ((int)val('SELECT COUNT(*) FROM projects WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $id]) > 0) fail('Nama project sudah ada');
  $active = !empty($p['active']) ? 1 : 0;
  if ($id !== '' && $id === cur_id() && !$active) fail('Tidak bisa menonaktifkan project yang sedang dipakai');
  $src = !empty($p['copyFrom']) ? (string)$p['copyFrom'] : '__default';
  if ($id === '' && $src !== '__default' && $src !== '__empty' && !row1('SELECT id FROM projects WHERE id = ?', [$src])) fail('Project sumber tidak ditemukan');
  if ($id !== '') {
    if (!row1('SELECT id FROM projects WHERE id = ?', [$id])) fail('Data tidak ditemukan');
    q('UPDATE projects SET name = ?, active = ? WHERE id = ?', [$name, $active, $id]);
    return $id;
  }
  $id = uuid();
  q('INSERT INTO projects (id, name, active, created_at) VALUES (?,?,?,?)', [$id, $name, $active, now_iso()]);
  if ($src === '__default') seed_devices($id); elseif ($src !== '__empty') copy_devices($src, $id);
  return $id;
}
function r_deleteProject($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ($id === cur_id()) fail('Tidak bisa menghapus project yang sedang dipakai');
  foreach (['units', 'installations', 'replacements'] as $t)
    if ((int)val("SELECT COUNT(*) FROM $t WHERE project_id = ?", [$id]) > 0) fail('Project masih punya data unit/instalasi. Nonaktifkan saja, atau hapus datanya dulu.');
  if (!row1('SELECT id FROM projects WHERE id = ?', [$id])) fail('Data tidak ditemukan');
  q('DELETE FROM devices WHERE project_id = ?', [$id]);
  q('DELETE FROM user_projects WHERE project_id = ?', [$id]);
  q('DELETE FROM projects WHERE id = ?', [$id]);
  return null;
}

/* ============ MASTER UNIT & DEVICE ============ */
function r_listUnits($p, $u) {
  $r = rows('SELECT * FROM units WHERE project_id = ?', [cur_id()]);
  usort($r, function ($a, $b) { return natcmp($a['unit_no'], $b['unit_no']); });
  return array_map('unit_out', $r);
}
function r_saveUnit($p, $u) {
  $no = str_(isset($p['unitNo']) ? $p['unitNo'] : '');
  if ($no === '') fail('Nomor unit wajib diisi');
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ((int)val('SELECT COUNT(*) FROM units WHERE project_id = ? AND LOWER(unit_no) = LOWER(?) AND id <> ?', [cur_id(), $no, $id]) > 0) fail('Nomor unit sudah ada di project ini');
  $type = (string)(isset($p['type']) ? $p['type'] : ''); $notes = (string)(isset($p['notes']) ? $p['notes'] : ''); $active = !empty($p['active']) ? 1 : 0;
  if ($id !== '') {
    if (!row1('SELECT id FROM units WHERE id = ? AND project_id = ?', [$id, cur_id()])) fail('Unit bukan milik project ini');
    q('UPDATE units SET unit_no = ?, type = ?, notes = ?, active = ? WHERE id = ?', [$no, $type, $notes, $active, $id]);
    q('UPDATE installations SET unit_no = ? WHERE unit_id = ? AND project_id = ?', [$no, $id, cur_id()]);   // nomor unit tampil di data instalasi
    return $id;
  }
  $id = uuid();
  q('INSERT INTO units (id, project_id, unit_no, type, notes, active) VALUES (?,?,?,?,?,?)', [$id, cur_id(), $no, $type, $notes, $active]);
  return $id;
}
function r_deleteUnit($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ((int)val('SELECT COUNT(*) FROM installations WHERE project_id = ? AND unit_id = ?', [cur_id(), $id]) > 0)
    fail('Unit sudah punya data instalasi. Nonaktifkan saja, atau hapus data instalasinya dulu.');
  if (!row1('SELECT id FROM units WHERE id = ?', [$id])) fail('Data tidak ditemukan');
  if (!row1('SELECT id FROM units WHERE id = ? AND project_id = ?', [$id, cur_id()])) fail('Data bukan milik project ini');
  q('DELETE FROM units WHERE id = ?', [$id]);
  return null;
}
function r_listDevices($p, $u) { return array_map('device_out', rows('SELECT * FROM devices WHERE project_id = ? ORDER BY sort', [cur_id()])); }
function r_saveDevice($p, $u) {
  $name = str_(isset($p['name']) ? $p['name'] : '');
  if ($name === '') fail('Nama device wajib diisi');
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ($id !== '' && !row1('SELECT id FROM devices WHERE id = ? AND project_id = ?', [$id, cur_id()])) fail('Device bukan milik project ini');
  if ((int)val('SELECT COUNT(*) FROM devices WHERE project_id = ? AND LOWER(name) = LOWER(?) AND id <> ?', [cur_id(), $name, $id]) > 0) fail('Nama device sudah ada di project ini');
  $sn = !empty($p['needSN']) ? 1 : 0; $ph = !empty($p['needPhoto']) ? 1 : 0; $act = !empty($p['active']) ? 1 : 0;
  if ($id !== '') {
    q('UPDATE devices SET name = ?, need_sn = ?, need_photo = ?, active = ? WHERE id = ?', [$name, $sn, $ph, $act, $id]);   // urutan tidak ditimpa
    return $id;
  }
  $sort = (int)val('SELECT COALESCE(MAX(sort), 0) FROM devices WHERE project_id = ?', [cur_id()]) + 1;
  $id = uuid();
  q('INSERT INTO devices (id, project_id, name, need_sn, need_photo, active, sort) VALUES (?,?,?,?,?,?,?)', [$id, cur_id(), $name, $sn, $ph, $act, $sort]);
  return $id;
}
function r_deleteDevice($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  $d = row1('SELECT * FROM devices WHERE id = ?', [$id]);
  if (!$d) fail('Data tidak ditemukan');
  if ($d['project_id'] !== cur_id()) fail('Data bukan milik project ini');
  q('DELETE FROM devices WHERE id = ?', [$id]);
  return null;
}

/* ============ USER MANAGEMENT ============ */
function r_listUsers($p, $me) {
  $names = []; foreach (rows('SELECT id, name FROM projects') as $x) $names[$x['id']] = $x['name'];
  $adm = $me['role'] === 'Admin'; $mine = user_projects($me['id']); $out = [];
  foreach (rows('SELECT * FROM users ORDER BY name') as $x) {
    $ids = user_projects($x['id']);
    if (!$adm && ($x['role'] === 'Admin' || !in_array(cur_id(), $ids, true))) continue;
    $o = pub_user($x);
    if (!$adm) $o['projectIds'] = array_values(array_filter($o['projectIds'], function ($id) use ($mine) { return in_array($id, $mine, true); }));
    $nm = []; foreach ($o['projectIds'] as $id) if (isset($names[$id])) $nm[] = $names[$id];
    $o['projects'] = implode(', ', $nm);
    $out[] = $o;
  }
  return $out;
}
function set_user_projects($uid, $ids) {
  q('DELETE FROM user_projects WHERE user_id = ?', [$uid]);
  foreach (array_unique($ids) as $pid) q('INSERT INTO user_projects (user_id, project_id) VALUES (?,?)', [$uid, $pid]);
}
function r_saveUser($p, $me) {
  $un = strtolower(str_(isset($p['username']) ? $p['username'] : ''));
  $name = str_(isset($p['name']) ? $p['name'] : '');
  if ($un === '' || $name === '') fail('Username dan nama wajib diisi');
  $role = isset($p['role']) ? (string)$p['role'] : '';
  if (!in_array($role, ROLES, true)) fail('Role tidak valid');
  $adm = $me['role'] === 'Admin';
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ((int)val('SELECT COUNT(*) FROM users WHERE LOWER(username) = ? AND id <> ?', [$un, $id]) > 0) fail('Username sudah dipakai');
  $pw = (string)(isset($p['password']) ? $p['password'] : '');
  if ($id === '' && $pw === '') fail('Password wajib diisi untuk user baru');
  if ($pw !== '' && mb_strlen($pw) < 6) fail('Password minimal 6 karakter');
  $ex = $id !== '' ? row1('SELECT * FROM users WHERE id = ?', [$id]) : null;
  if ($id !== '' && !$ex) fail('Data tidak ditemukan');
  $self = $id !== '' && $id === $me['id'];
  $active = !empty($p['active']) ? 1 : 0;
  if ($self && (!$active || $role !== $me['role'])) fail('Anda tidak bisa menonaktifkan / mengubah role akun sendiri');

  $ids = [];
  if ($adm) {
    $valid = []; foreach (rows('SELECT id FROM projects') as $x) $valid[$x['id']] = 1;
    $ids = array_values(array_filter(to_ids(isset($p['projectIds']) ? $p['projectIds'] : []), function ($x) use ($valid) { return isset($valid[$x]); }));
    if ($role === 'Admin') $ids = [];
    elseif (!$ids) fail('Pilih minimal satu project untuk role ' . $role);
  } else {
    $mine = user_projects($me['id']);
    if (!$self && !in_array($role, ['Engineer', 'Viewer'], true)) fail('Manager hanya dapat mengelola role Engineer dan Viewer.');
    if ($ex && !$self) {
      if ($ex['role'] === 'Admin' || $ex['role'] === 'Manager') fail('Akses ditolak: akun ini hanya bisa diubah Admin.');
      if (!in_array(cur_id(), user_projects($ex['id']), true)) fail('User bukan bagian dari project ini');
    }
    if ($self) $ids = $mine;
    else {
      $pick = array_values(array_filter(to_ids(isset($p['projectIds']) ? $p['projectIds'] : []), function ($x) use ($mine) { return in_array($x, $mine, true); }));
      $keep = $ex ? array_values(array_filter(user_projects($ex['id']), function ($x) use ($mine) { return !in_array($x, $mine, true); })) : [];   // akses ke project lain tidak disentuh
      $ids = array_values(array_unique(array_merge($keep, $pick)));
      if (!$ids) fail('Pilih minimal satu project');
    }
  }
  if ($id !== '') {
    q('UPDATE users SET username = ?, name = ?, role = ?, active = ? WHERE id = ?', [$un, $name, $role, $active, $id]);
    if ($pw !== '') q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
  } else {
    $id = uuid();
    q('INSERT INTO users (id, username, name, pass_hash, role, active, created_at) VALUES (?,?,?,?,?,?,?)', [$id, $un, $name, password_hash($pw, PASSWORD_DEFAULT), $role, $active, now_iso()]);
  }
  set_user_projects($id, $ids);
  return $id;
}
function delete_user_row($id) {
  q('DELETE FROM user_projects WHERE user_id = ?', [$id]);
  q('DELETE FROM sessions WHERE user_id = ?', [$id]);
  q('DELETE FROM users WHERE id = ?', [$id]);
}
function r_deleteUser($p, $me) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ($id === $me['id']) fail('Anda tidak bisa menghapus akun sendiri');
  $ex = row1('SELECT * FROM users WHERE id = ?', [$id]);
  if (!$ex) fail('Data tidak ditemukan');
  if ($me['role'] === 'Admin') { delete_user_row($id); return null; }
  if ($ex['role'] === 'Admin' || $ex['role'] === 'Manager') fail('Akses ditolak: akun ini hanya bisa dihapus Admin.');
  $ids = user_projects($id);
  if (!in_array(cur_id(), $ids, true)) fail('User bukan bagian dari project ini');
  $left = array_values(array_filter($ids, function ($x) { return $x !== cur_id(); }));
  if (!$left) { delete_user_row($id); return null; }   // tidak punya project lain: akun dihapus
  set_user_projects($id, $left);                        // masih punya project lain: hanya dikeluarkan dari project ini
  return null;
}

/* ============ PANDUAN ============ */
function guide_sort($a, $b) { return ($a['sort'] - $b['sort']) ?: natcmp($a['title'], $b['title']); }
function r_listGuide($p, $u) {
  $adm = $u['role'] === 'Admin';
  $list = array_map('guide_out', rows('SELECT * FROM guide'));
  if (!$adm) $list = array_values(array_filter($list, function ($g) { return $g['active']; }));
  usort($list, 'guide_sort');
  return $list;
}
function public_guide_() {   // tanpa login: hanya artikel aktif yang ditandai "tampil di halaman login"
  $list = array_filter(array_map('guide_out', rows('SELECT * FROM guide')), function ($g) { return $g['active'] && $g['public']; });
  usort($list, 'guide_sort');
  return array_values(array_map(function ($g) { return ['id' => $g['id'], 'title' => $g['title'], 'body' => $g['body']]; }, $list));
}
function r_saveGuide($p, $u) {
  $title = str_(isset($p['title']) ? $p['title'] : ''); $body = str_(isset($p['body']) ? $p['body'] : '');
  if ($title === '') fail('Judul wajib diisi');
  if (mb_strlen($title) > 120) fail('Judul maksimal 120 karakter');
  if ($body === '') fail('Isi panduan wajib diisi');
  if (mb_strlen($body) > 40000) fail('Isi terlalu panjang (maksimal 40.000 karakter). Pecah menjadi beberapa artikel.');
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if ($id !== '' && !row1('SELECT id FROM guide WHERE id = ?', [$id])) fail('Artikel tidak ditemukan');
  $roles = implode(',', array_filter(to_ids(isset($p['roles']) ? $p['roles'] : []), function ($r) { return in_array($r, GUIDE_ROLES, true); }));
  $pub = !empty($p['public']) ? 1 : 0; $act = !empty($p['active']) ? 1 : 0;
  $sortRaw = isset($p['sort']) ? trim((string)$p['sort']) : '';
  $given = $sortRaw !== '' && is_numeric($sortRaw);
  if ($id !== '') {
    q('UPDATE guide SET title = ?, body = ?, roles = ?, is_public = ?, active = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$title, $body, $roles, $pub, $act, now_iso(), $u['name'], $id]);
    if ($given) q('UPDATE guide SET sort = ? WHERE id = ?', [(int)$sortRaw, $id]);
    return $id;
  }
  $sort = $given ? (int)$sortRaw : (int)val('SELECT COALESCE(MAX(sort), 0) FROM guide') + 1;
  $id = uuid();
  q('INSERT INTO guide (id, title, body, roles, is_public, active, sort, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $title, $body, $roles, $pub, $act, $sort, now_iso(), $u['name']]);
  return $id;
}
function r_deleteGuide($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  if (!row1('SELECT id FROM guide WHERE id = ?', [$id])) fail('Data tidak ditemukan');
  q('DELETE FROM guide WHERE id = ?', [$id]);
  return null;
}

/* ============ INSTALASI ============ */
function r_formData($p, $u) {
  $units = rows('SELECT * FROM units WHERE project_id = ? AND active = 1', [cur_id()]);
  usort($units, function ($a, $b) { return natcmp($a['unit_no'], $b['unit_no']); });
  $done = []; foreach (rows('SELECT unit_id FROM installations WHERE project_id = ?', [cur_id()]) as $r) $done[] = $r['unit_id'];
  return ['units' => array_map('unit_out', $units),
          'devices' => array_map('device_out', rows('SELECT * FROM devices WHERE project_id = ? AND active = 1 ORDER BY sort', [cur_id()])),
          'doneUnitIds' => $done];
}
function r_listInstalls($p, $u) {
  $list = array_map('inst_out', rows('SELECT * FROM installations WHERE project_id = ?', [cur_id()]));
  usort($list, function ($a, $b) { return strcmp((string)$b['updatedAt'], (string)$a['updatedAt']); });
  return $list;
}
function r_getInstall($p, $u) {
  $i = row1('SELECT * FROM installations WHERE project_id = ? AND unit_id = ?', [cur_id(), isset($p['unitId']) ? (string)$p['unitId'] : '']);
  return $i ? inst_out($i) : null;
}
function add_repl($unit, $entry, $o, $u, $now) {
  q('INSERT INTO replacements (id, project_id, unit_id, unit_no, device_id, device_name, date, old_sn, new_sn, reason, note, photo, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [uuid(), cur_id(), $unit['id'], $unit['unit_no'], $entry['deviceId'], $entry['name'], $o['date'], isset($o['oldSN']) ? $o['oldSN'] : '', isset($o['newSN']) ? $o['newSN'] : '',
     $o['reason'], isset($o['note']) ? (string)$o['note'] : '', isset($o['photo']) ? (string)$o['photo'] : '', $u['name'], $now]);
}
function r_saveInstall($p, $u) {
  $unit = row1('SELECT * FROM units WHERE id = ? AND project_id = ?', [isset($p['unitId']) ? (string)$p['unitId'] : '', cur_id()]);
  if (!$unit) fail('Pilih nomor unit terlebih dahulu');
  $date = isset($p['date']) ? $p['date'] : '';
  if (!is_date($date)) fail('Tanggal instalasi wajib diisi');
  $devs = []; foreach (rows('SELECT * FROM devices WHERE project_id = ?', [cur_id()]) as $d) $devs[$d['id']] = $d;
  $details = [];
  foreach ((isset($p['details']) && is_array($p['details']) ? $p['details'] : []) as $x) {
    $d = isset($x['deviceId'], $devs[$x['deviceId']]) ? $devs[$x['deviceId']] : null;
    if (!$d) fail('Device tidak ditemukan');
    $st = isset($x['status']) ? $x['status'] : '';
    if (!in_array($st, ['Installed', 'Pending'], true)) fail('Pilih status untuk ' . $d['name']);
    $sn = str_(isset($x['sn']) ? $x['sn'] : '');
    if ($st === 'Installed' && $d['need_sn'] && $sn === '') fail('Serial Number ' . $d['name'] . ' wajib diisi');
    if ($st === 'Installed' && $d['need_photo'] && REQUIRE_PHOTO && empty($x['photo'])) fail('Foto ' . $d['name'] . ' wajib diunggah');
    $details[] = ['deviceId' => $d['id'], 'name' => $d['name'], 'status' => $st,
                  'sn' => $d['need_sn'] ? $sn : '', 'photo' => $d['need_photo'] ? (string)(isset($x['photo']) ? $x['photo'] : '') : ''];
  }
  if (!$details) fail('Tidak ada device untuk disimpan');
  $now = now_iso();
  $ex = row1('SELECT * FROM installations WHERE project_id = ? AND unit_id = ?', [cur_id(), $unit['id']]);   // satu unit = satu data (upsert)
  $oldD = $ex ? jdec_arr($ex['details']) : [];
  $repl = [];
  foreach ((isset($p['changes']) && is_array($p['changes']) ? $p['changes'] : []) as $ch) {
    if (!isset($ch['type']) || $ch['type'] !== 'replace') continue;
    $nd = $od = null;
    foreach ($details as $x) if ($x['deviceId'] === (isset($ch['deviceId']) ? $ch['deviceId'] : null)) $nd = $x;
    foreach ($oldD as $x) if (isset($x['deviceId']) && $x['deviceId'] === (isset($ch['deviceId']) ? $ch['deviceId'] : null)) $od = $x;
    if (!$nd || !$od || $od['status'] !== 'Installed' || $nd['status'] !== 'Installed' || empty($od['sn']) || $nd['sn'] === $od['sn']) continue;
    if (!in_array(isset($ch['reason']) ? $ch['reason'] : '', REASONS, true)) fail('Pilih alasan penggantian untuk ' . $nd['name']);
    $repl[] = ['nd' => $nd, 'od' => $od, 'ch' => $ch];
  }
  $remarks = (string)(isset($p['remarks']) ? $p['remarks'] : '');
  if ($ex) {
    $id = $ex['id'];
    q('UPDATE installations SET unit_no = ?, date = ?, remarks = ?, details = ?, updated_at = ? WHERE id = ?', [$unit['unit_no'], $date, $remarks, jenc($details), $now, $id]);
  } else {
    $id = uuid();
    q('INSERT INTO installations (id, project_id, unit_id, unit_no, date, remarks, details, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$id, cur_id(), $unit['id'], $unit['unit_no'], $date, $remarks, jenc($details), $u['name'], $now, $now]);
  }
  foreach ($repl as $x) {
    $ch = $x['ch'];
    add_repl($unit, $x['nd'], ['date' => (isset($ch['date']) && is_date($ch['date'])) ? $ch['date'] : $date, 'oldSN' => $x['od']['sn'], 'newSN' => $x['nd']['sn'],
      'reason' => $ch['reason'], 'note' => isset($ch['note']) ? $ch['note'] : '', 'photo' => $x['nd']['photo']], $u, $now);
  }
  return ['id' => $id];
}
function r_deleteInstall($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  $r = row1('SELECT * FROM installations WHERE id = ?', [$id]);
  if (!$r) fail('Data tidak ditemukan');
  if ($r['project_id'] !== cur_id()) fail('Data bukan milik project ini');
  q('DELETE FROM installations WHERE id = ?', [$id]);
  return null;
}

/* ============ RIWAYAT PENGGANTIAN ============ */
function repl_out($r) {
  return ['id' => $r['id'], 'unitId' => $r['unit_id'], 'unitNo' => $r['unit_no'], 'deviceName' => $r['device_name'], 'date' => $r['date'], 'oldSN' => $r['old_sn'],
          'newSN' => $r['new_sn'], 'reason' => $r['reason'], 'note' => $r['note'], 'photo' => $r['photo'], 'createdBy' => $r['created_by'], 'createdAt' => $r['created_at']];
}
function r_listReplacements($p, $u) {
  $unit = isset($p['unitId']) ? (string)$p['unitId'] : '';
  $list = array_map('repl_out', $unit !== '' ? rows('SELECT * FROM replacements WHERE project_id = ? AND unit_id = ?', [cur_id(), $unit])
                                               : rows('SELECT * FROM replacements WHERE project_id = ?', [cur_id()]));
  usort($list, function ($a, $b) { return strcmp((string)$b['date'], (string)$a['date']) ?: strcmp((string)$b['createdAt'], (string)$a['createdAt']); });
  return $list;
}
function r_deleteReplacement($p, $u) {
  $id = str_(isset($p['id']) ? $p['id'] : '');
  $r = row1('SELECT * FROM replacements WHERE id = ?', [$id]);
  if (!$r) fail('Data tidak ditemukan');
  if ($r['project_id'] !== cur_id()) fail('Data bukan milik project ini');
  q('DELETE FROM replacements WHERE id = ?', [$id]);   // hanya catatan riwayat; serial number pada data instalasi tidak diubah
  return null;
}
function r_replaceDevice($p, $u) {
  $unit = row1('SELECT * FROM units WHERE id = ? AND project_id = ?', [isset($p['unitId']) ? (string)$p['unitId'] : '', cur_id()]);
  if (!$unit) fail('Unit tidak ditemukan');
  $ins = row1('SELECT * FROM installations WHERE project_id = ? AND unit_id = ?', [cur_id(), $unit['id']]);
  if (!$ins) fail('Unit belum punya data instalasi');
  if (!is_date(isset($p['date']) ? $p['date'] : '')) fail('Tanggal penggantian wajib diisi');
  $reason = isset($p['reason']) ? $p['reason'] : '';
  if (!in_array($reason, REASONS, true)) fail('Pilih alasan penggantian');
  $details = jdec_arr($ins['details']);
  $idx = -1; $devId = isset($p['deviceId']) ? $p['deviceId'] : '';
  foreach ($details as $k => $x) if (isset($x['deviceId']) && $x['deviceId'] === $devId) $idx = $k;
  if ($idx < 0) fail('Device tidak ada pada unit ini');
  $e = $details[$idx];
  if ($e['status'] !== 'Installed') fail('Hanya device berstatus Installed yang bisa diganti');
  $dev = row1('SELECT * FROM devices WHERE id = ? AND project_id = ?', [$devId, cur_id()]);
  $needSN = $dev ? (bool)$dev['need_sn'] : !empty($e['sn']);
  $newSN = str_(isset($p['newSN']) ? $p['newSN'] : '');
  if ($needSN) {
    if ($newSN === '') fail('Serial Number baru wajib diisi');
    if ($newSN === (isset($e['sn']) ? $e['sn'] : '')) fail('Serial Number baru sama dengan yang lama');
  }
  $now = now_iso();
  add_repl($unit, $e, ['date' => $p['date'], 'oldSN' => isset($e['sn']) ? $e['sn'] : '', 'newSN' => $needSN ? $newSN : '', 'reason' => $reason,
                       'note' => isset($p['note']) ? $p['note'] : '', 'photo' => isset($p['photo']) ? $p['photo'] : ''], $u, $now);
  $details[$idx]['sn'] = $needSN ? $newSN : '';
  if ($dev && $dev['need_photo']) $details[$idx]['photo'] = (string)(isset($p['photo']) ? $p['photo'] : '');
  q('UPDATE installations SET details = ?, updated_at = ? WHERE id = ?', [jenc(array_values($details)), $now, $ins['id']]);
  return ['done' => true];
}
// Kirim foto ke Google Drive lewat Apps Script kecil (photo-endpoint.gs). Mengembalikan ID file Drive, atau null bila gagal.
function upload_to_drive($bin, $name) {
  $url = (string)cfg('photo_drive_url', '');
  if ($url === '') return null;
  if (!function_exists('curl_init')) return null;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => jenc(['secret' => (string)cfg('photo_drive_secret', ''), 'name' => $name, 'data' => base64_encode($bin)]),
    CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,    // Apps Script menjawab lewat redirect 302
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
  ]);
  $res = curl_exec($ch);
  if ($res === false) { error_log('[IMS] upload Drive gagal: ' . curl_error($ch)); return null; }
  $j = json_decode((string)$res, true);
  if (!is_array($j) || empty($j['ok']) || empty($j['id']) || !preg_match('/^[\w-]{20,}$/', (string)$j['id'])) {
    error_log('[IMS] upload Drive ditolak: ' . substr((string)$res, 0, 200));
    return null;
  }
  return (string)$j['id'];
}
function r_uploadPhoto($p, $u) {
  $bin = base64_decode((string)(isset($p['data']) ? $p['data'] : ''), true);
  if ($bin === false || strlen($bin) < 100) fail('Data foto tidak valid');
  if (strlen($bin) > 6 * 1024 * 1024) fail('Foto terlalu besar (maksimal 6 MB)');
  $info = @getimagesizefromstring($bin);
  if (!$info || $info[2] !== IMAGETYPE_JPEG) fail('Foto harus berformat JPEG');

  // 1) Google Drive (bila photo_drive_url diisi di ims_config.php)
  if ((string)cfg('photo_drive_url', '') !== '') {
    $name = preg_replace('/[^\w.\-]/', '_', (string)(isset($p['name']) ? $p['name'] : 'foto'));
    $id = upload_to_drive($bin, mb_substr($name, 0, 80));
    if ($id !== null) return ['id' => $id];
    if (!cfg('photo_drive_fallback', true)) fail('Gagal mengunggah foto ke Google Drive. Coba lagi.');
  }
  // 2) Simpan lokal di folder uploads/ (default, atau cadangan bila Drive gagal)
  $hex = bin2hex(random_bytes(16));
  $dir = upload_dir();
  if (!is_dir($dir) || !is_writable($dir)) fail('Folder uploads tidak bisa ditulis. Atur izin folder 755 (atau 775).');
  if (file_put_contents($dir . '/' . $hex . '.jpg', $bin) === false) fail('Gagal menyimpan foto');
  return ['id' => 'loc_' . $hex];
}

/* ============ DASHBOARD ============ */
function r_dashboard($p, $u) {
  $units = rows('SELECT * FROM units WHERE project_id = ? AND active = 1', [cur_id()]);
  usort($units, function ($a, $b) { return natcmp($a['unit_no'], $b['unit_no']); });
  $ids = []; foreach ($units as $x) $ids[$x['id']] = 1;
  $byUnit = [];
  foreach (rows('SELECT * FROM installations WHERE project_id = ?', [cur_id()]) as $i) if (isset($ids[$i['unit_id']])) $byUnit[$i['unit_id']] = inst_out($i);
  $list = [];
  foreach ($units as $x) {
    $b = isset($byUnit[$x['id']]) ? $byUnit[$x['id']] : null;
    $list[] = ['unitNo' => $x['unit_no'], 'type' => $x['type'], 'date' => $b ? $b['date'] : '', 'installed' => $b ? $b['installed'] : 0,
               'total' => $b ? $b['total'] : 0, 'pct' => $b ? $b['pct'] : 0, 'started' => (bool)$b];
  }
  $dev = [];
  foreach (rows('SELECT name FROM devices WHERE project_id = ? AND active = 1 ORDER BY sort', [cur_id()]) as $d) $dev[$d['name']] = 0;
  foreach ($byUnit as $b) foreach ($b['details'] as $x) if (isset($x['status']) && $x['status'] === 'Installed' && array_key_exists($x['name'], $dev)) $dev[$x['name']]++;
  $rc = []; $replTotal = 0;
  foreach (rows('SELECT device_name FROM replacements WHERE project_id = ?', [cur_id()]) as $r) { $replTotal++; $rc[$r['device_name']] = (isset($rc[$r['device_name']]) ? $rc[$r['device_name']] : 0) + 1; }
  arsort($rc);
  $rb = []; foreach (array_slice($rc, 0, 5, true) as $n => $c) $rb[] = ['name' => (string)$n, 'count' => $c];
  $total = count($units); $started = 0; $complete = 0; $sum = 0;
  foreach ($list as $x) { if ($x['started']) { $started++; if ($x['pct'] === 100) $complete++; } $sum += $x['pct']; }
  $devOut = []; foreach ($dev as $n => $c) $devOut[] = ['name' => (string)$n, 'installed' => $c];
  return ['total' => $total, 'started' => $started, 'complete' => $complete, 'partial' => $started - $complete, 'notStarted' => $total - $started,
          'overall' => $total ? (int)round($sum / $total) : 0, 'devices' => $devOut, 'units' => $list, 'replTotal' => $replTotal, 'replByDevice' => $rb];
}

/* ============ ROUTER ============ */
// admin = hanya Admin | mgr = Admin atau Manager | w = operasi tulis (transaksi) | edit = Viewer tidak boleh
$ROUTES = [
  'me'               => ['h' => function ($p, $u) { $o = pub_user($u); $o['projectId'] = cur_id(); $o['projectName'] = cur_name(); return $o; }],
  'dashboard'        => ['h' => 'r_dashboard'],
  'formData'         => ['h' => 'r_formData'],
  'listInstalls'     => ['h' => 'r_listInstalls'],
  'getInstall'       => ['h' => 'r_getInstall'],
  'saveInstall'      => ['h' => 'r_saveInstall', 'w' => 1, 'edit' => 1],
  'deleteInstall'    => ['h' => 'r_deleteInstall', 'w' => 1, 'mgr' => 1],
  'uploadPhoto'      => ['h' => 'r_uploadPhoto', 'edit' => 1],
  'replaceDevice'    => ['h' => 'r_replaceDevice', 'w' => 1, 'edit' => 1],
  'listReplacements' => ['h' => 'r_listReplacements'],
  'deleteReplacement' => ['h' => 'r_deleteReplacement', 'w' => 1, 'mgr' => 1],
  'generateReport'   => ['h' => 'r_generateReport'],
  'generateExcel'    => ['h' => 'r_generateExcel'],
  'listGuide'        => ['h' => 'r_listGuide'],
  'saveGuide'        => ['h' => 'r_saveGuide', 'w' => 1, 'admin' => 1],
  'deleteGuide'      => ['h' => 'r_deleteGuide', 'w' => 1, 'admin' => 1],
  'importRun'        => ['h' => 'r_importRun', 'w' => 1, 'mgr' => 1],
  'importUndo'       => ['h' => 'r_importUndo', 'w' => 1, 'mgr' => 1],
  'listProjects'     => ['h' => 'r_listProjects', 'admin' => 1],
  'assignableProjects' => ['h' => 'r_assignableProjects', 'mgr' => 1],
  'saveProject'      => ['h' => 'r_saveProject', 'w' => 1, 'admin' => 1],
  'deleteProject'    => ['h' => 'r_deleteProject', 'w' => 1, 'admin' => 1],
  'listUnits'        => ['h' => 'r_listUnits', 'mgr' => 1],
  'saveUnit'         => ['h' => 'r_saveUnit', 'w' => 1, 'mgr' => 1],
  'deleteUnit'       => ['h' => 'r_deleteUnit', 'w' => 1, 'mgr' => 1],
  'listDevices'      => ['h' => 'r_listDevices', 'mgr' => 1],
  'saveDevice'       => ['h' => 'r_saveDevice', 'w' => 1, 'mgr' => 1],
  'deleteDevice'     => ['h' => 'r_deleteDevice', 'w' => 1, 'mgr' => 1],
  'listUsers'        => ['h' => 'r_listUsers', 'mgr' => 1],
  'saveUser'         => ['h' => 'r_saveUser', 'w' => 1, 'mgr' => 1],
  'deleteUser'       => ['h' => 'r_deleteUser', 'w' => 1, 'mgr' => 1],
];

/* ============ ENTRY POINT ============ */
$inTx = false;
try {
  if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('Metode tidak didukung');
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) throw new ApiError('Permintaan tidak valid');
  $action = isset($in['action']) ? (string)$in['action'] : '';
  $token  = isset($in['token']) ? (string)$in['token'] : '';
  $p      = isset($in['p']) && is_array($in['p']) ? $in['p'] : [];

  // aksi publik (tanpa token)
  if ($action === 'publicProjects') out(['ok' => true, 'data' => array_map('project_out', rows('SELECT * FROM projects WHERE active = 1 ORDER BY created_at, name'))]);
  if ($action === 'loginBg') {
    $f = glob(__DIR__ . '/login-bg.{jpg,jpeg,png,webp}', GLOB_BRACE);
    out(['ok' => true, 'data' => $f ? basename($f[0]) : null]);
  }
  if ($action === 'login') out(['ok' => true, 'data' => login_($p)]);
  if ($action === 'logout') { if ($token !== '') q('DELETE FROM sessions WHERE token = ?', [$token]); out(['ok' => true]); }
  if ($action === 'publicGuide') out(['ok' => true, 'data' => public_guide_()]);

  list($u, $sess) = auth_($token);
  $pj = row1('SELECT * FROM projects WHERE id = ?', [$sess['project_id']]);
  if (!$pj || !can_access_project($u, $pj['id'])) throw new ApiError('Sesi berakhir, silakan login kembali.');
  $GLOBALS['CUR'] = ['id' => $pj['id'], 'name' => $pj['name']];

  if (!isset($ROUTES[$action])) throw new ApiError('Aksi tidak dikenal');
  $r = $ROUTES[$action];
  if (!empty($r['admin']) && $u['role'] !== 'Admin') throw new ApiError('Akses ditolak: khusus Admin.');
  if (!empty($r['mgr']) && $u['role'] !== 'Admin' && $u['role'] !== 'Manager') throw new ApiError('Akses ditolak: khusus Admin / Manager.');
  if (!empty($r['edit']) && $u['role'] === 'Viewer') throw new ApiError('Akses ditolak: akun Viewer hanya bisa melihat data.');
  if (!empty($r['w'])) { db()->beginTransaction(); $inTx = true; }
  $d = call_user_func($r['h'], $p, $u);
  if ($inTx) { db()->commit(); $inTx = false; }
  out(['ok' => true, 'data' => $d]);
} catch (ApiError $e) {
  if ($inTx) { try { db()->rollBack(); } catch (Throwable $x) {} }
  out(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  if ($inTx) { try { db()->rollBack(); } catch (Throwable $x) {} }
  error_log('[IMS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  out(['ok' => false, 'error' => 'Terjadi kesalahan di server. Coba lagi, atau hubungi administrator.']);
}

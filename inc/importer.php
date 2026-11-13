<?php
// Import data massal (khusus Admin). Pengganti importRun_, impInstall_, impRepl_, importUndo_ di Code.gs.
// Format permintaan: { type:'install'|'repl'|'units', mode:'check'|'commit', rows:[{n,v}], opts:{createUnits,onExisting,allowNoSN}, label }

function imp_norm($v) {
  $m = [];
  if (is_array($v)) foreach ($v as $k => $x) $m[mb_strtolower(trim((string)$k))] = is_scalar($x) ? trim((string)$x) : '';
  return $m;
}
function imp_pick($m, $keys) { foreach ($keys as $k) if (isset($m[$k])) return $m[$k]; return ''; }
function imp_date($s) {   // yyyy-mm-dd, atau dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy (hari lebih dulu)
  $s = trim((string)$s);
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
  elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/', $s, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; }
  else return '';
  if (!checkdate($mo, $d, $y)) return '';
  return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}
function imp_status($s) {   // '' = kosong, null = tidak dikenali
  $x = mb_strtolower(trim((string)$s));
  if ($x === '') return '';
  if (in_array($x, ['installed', 'terpasang', 'terinstal', 'terinstall', 'sudah', 'ya', 'yes', 'y', '1', 'ok', 'true'], true)) return 'Installed';
  if (in_array($x, ['pending', 'belum', 'tidak', 'no', 'n', '0', 'false', '-'], true)) return 'Pending';
  return null;
}
function imp_photo_id($s) {   // ID file Drive / tautan Drive / ID foto lokal; null = tidak valid
  $s = trim((string)$s);
  if ($s === '') return '';
  if (preg_match('#/d/([\w-]{20,})#', $s, $m) || preg_match('/[?&]id=([\w-]{20,})/', $s, $m)) return $m[1];
  return preg_match('/^[\w-]{20,}$/', $s) ? $s : null;
}
function imp_err($ctx, $n, $msg) { $ctx->res['errors'][] = ['n' => $n, 'msg' => $msg]; }
function imp_warn($ctx, $n, $msg) { $ctx->res['warns'][] = ['n' => $n, 'msg' => $msg]; }

function r_importRun($p, $u) {
  $commit = isset($p['mode']) && $p['mode'] === 'commit';
  $rowsIn = isset($p['rows']) && is_array($p['rows']) ? array_values($p['rows']) : [];
  if (!$rowsIn) fail('Tidak ada baris untuk diproses');
  if (count($rowsIn) > 300) fail('Maksimal 300 baris per permintaan');
  $label = (string)(isset($p['label']) ? $p['label'] : '');
  if ($commit && !preg_match('/^Import \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $label)) fail('Label import tidak valid');

  $ctx = new stdClass();
  $ctx->rows = $rowsIn;
  $ctx->o = isset($p['opts']) && is_array($p['opts']) ? $p['opts'] : [];
  $ctx->commit = $commit;
  $ctx->label = $label;
  $ctx->now = now_iso();
  $ctx->res = ['total' => count($rowsIn), 'ok' => 0, 'skipped' => 0, 'errors' => [], 'warns' => [], 'units' => 0, 'installs' => 0,
               'updated' => 0, 'repl' => 0, 'newUnitIds' => [], 'unknownCols' => []];
  $ctx->devs = rows('SELECT * FROM devices WHERE project_id = ?', [cur_id()]);
  $ctx->unitBy = [];
  foreach (rows('SELECT * FROM units WHERE project_id = ?', [cur_id()]) as $x) $ctx->unitBy[mb_strtolower(trim($x['unit_no']))] = $x;
  $type = isset($p['type']) ? $p['type'] : 'install';
  if ($type === 'repl') imp_repl($ctx);
  elseif ($type === 'units') imp_units($ctx);
  else imp_install($ctx);
  return $ctx->res;
}

function imp_install($ctx) {
  $o = $ctx->o;
  $devs = array_values(array_filter($ctx->devs, function ($d) { return (bool)$d['active']; }));
  usort($devs, function ($a, $b) { return (int)$a['sort'] - (int)$b['sort']; });
  if (!$devs) fail('Master Device project ini kosong atau tidak ada yang aktif');
  $insBy = []; foreach (rows('SELECT * FROM installations WHERE project_id = ?', [cur_id()]) as $i) $insBy[$i['unit_id']] = $i;
  $known = ['nomor unit' => 1, 'unit' => 1, 'tipe' => 1, 'catatan unit' => 1, 'tanggal' => 1, 'remarks' => 1];
  foreach ($devs as $d) { $k = mb_strtolower(trim($d['name'])); $known[$k . ' - status'] = 1; $known[$k . ' - sn'] = 1; $known[$k . ' - foto'] = 1; }

  $seen = []; $newUnits = []; $newIns = []; $upd = [];
  foreach ($ctx->rows as $idx => $r) {
    $m = imp_norm(isset($r['v']) ? $r['v'] : []);
    $n = isset($r['n']) ? $r['n'] : $idx + 2;
    if ($idx === 0) foreach (array_keys($m) as $k) { $k = (string)$k; if ($k !== '' && !isset($known[$k])) $ctx->res['unknownCols'][] = $k; }
    $unitNo = imp_pick($m, ['nomor unit', 'unit']);
    if ($unitNo === '') { imp_err($ctx, $n, 'Nomor unit kosong'); continue; }
    $uk = mb_strtolower($unitNo);
    if (isset($seen[$uk])) { imp_err($ctx, $n, 'Unit ' . $unitNo . ' muncul lebih dari sekali pada data ini'); continue; }
    $seen[$uk] = 1;
    $date = imp_date(imp_pick($m, ['tanggal']));
    if ($date === '') { imp_err($ctx, $n, 'Tanggal tidak valid untuk unit ' . $unitNo . ' (gunakan yyyy-mm-dd atau dd/mm/yyyy)'); continue; }
    $unit = isset($ctx->unitBy[$uk]) ? $ctx->unitBy[$uk] : null;
    if (!$unit && empty($o['createUnits'])) { imp_err($ctx, $n, 'Unit ' . $unitNo . ' belum ada di Master Unit'); continue; }
    $ex = $unit && isset($insBy[$unit['id']]) ? $insBy[$unit['id']] : null;
    if ($ex && (!isset($o['onExisting']) || $o['onExisting'] !== 'update')) { $ctx->res['skipped']++; imp_warn($ctx, $n, 'Unit ' . $unitNo . ' sudah punya data instalasi, dilewati'); continue; }

    $before = count($ctx->res['errors']); $emptyDev = []; $oldD = $ex ? jdec_arr($ex['details']) : []; $details = [];
    foreach ($devs as $d) {
      $k = mb_strtolower(trim($d['name']));
      $raw = imp_pick($m, [$k . ' - status']);
      $st = imp_status($raw);
      if ($st === null) { imp_err($ctx, $n, 'Status ' . $d['name'] . ' tidak dikenali: "' . $raw . '" (unit ' . $unitNo . ')'); $st = 'Pending'; }
      elseif ($st === '') { $st = 'Pending'; $emptyDev[] = $d['name']; }
      $sn = $d['need_sn'] ? imp_pick($m, [$k . ' - sn']) : '';
      if ($st === 'Installed' && $d['need_sn'] && $sn === '' && empty($o['allowNoSN'])) imp_err($ctx, $n, 'Serial Number ' . $d['name'] . ' wajib diisi (unit ' . $unitNo . ')');
      if ($st === 'Pending' && $sn !== '') { imp_warn($ctx, $n, 'SN ' . $d['name'] . ' diabaikan karena status Pending'); $sn = ''; }
      $photo = '';
      if ($d['need_photo']) {
        $photo = imp_photo_id(imp_pick($m, [$k . ' - foto']));
        if ($photo === null) { $photo = ''; imp_warn($ctx, $n, 'Foto ' . $d['name'] . ' tidak valid, diabaikan'); }
        if ($photo === '' && $ex) foreach ($oldD as $od) if (isset($od['deviceId']) && $od['deviceId'] === $d['id'] && !empty($od['photo'])) $photo = $od['photo'];
      }
      $details[] = ['deviceId' => $d['id'], 'name' => $d['name'], 'status' => $st, 'sn' => $sn, 'photo' => $photo];
    }
    if (count($ctx->res['errors']) > $before) continue;
    if ($emptyDev) imp_warn($ctx, $n, 'Unit ' . $unitNo . ': status kosong dianggap Pending (' . implode(', ', $emptyDev) . ')');
    $ctx->res['ok']++;

    if (!$unit) {
      $unit = ['id' => uuid(), 'unit_no' => $unitNo, 'type' => imp_pick($m, ['tipe']), 'notes' => imp_pick($m, ['catatan unit']), 'active' => 1, 'project_id' => cur_id()];
      $ctx->unitBy[$uk] = $unit;
      $newUnits[] = $unit; $ctx->res['units']++; $ctx->res['newUnitIds'][] = $unit['id'];
    }
    $base = ['unit_id' => $unit['id'], 'unit_no' => $unit['unit_no'], 'date' => $date, 'remarks' => imp_pick($m, ['remarks']), 'details' => jenc($details), 'updated_at' => $ctx->now];
    if ($ex) { $upd[] = ['id' => $ex['id'], 'b' => $base]; $ctx->res['updated']++; }
    else { $newIns[] = array_merge(['id' => uuid(), 'created_by' => $ctx->label ?: 'Import', 'created_at' => $ctx->now, 'project_id' => cur_id()], $base); $ctx->res['installs']++; }
  }

  if ($ctx->commit) {
    foreach ($newUnits as $x) q('INSERT INTO units (id, project_id, unit_no, type, notes, active) VALUES (?,?,?,?,?,1)', [$x['id'], $x['project_id'], $x['unit_no'], $x['type'], $x['notes']]);
    foreach ($newIns as $x)
      q('INSERT INTO installations (id, project_id, unit_id, unit_no, date, remarks, details, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$x['id'], $x['project_id'], $x['unit_id'], $x['unit_no'], $x['date'], $x['remarks'], $x['details'], $x['created_by'], $x['created_at'], $x['updated_at']]);
    foreach ($upd as $x)
      q('UPDATE installations SET unit_id = ?, unit_no = ?, date = ?, remarks = ?, details = ?, updated_at = ? WHERE id = ?',
        [$x['b']['unit_id'], $x['b']['unit_no'], $x['b']['date'], $x['b']['remarks'], $x['b']['details'], $x['b']['updated_at'], $x['id']]);
  }
}

function imp_repl($ctx) {
  $devBy = []; foreach ($ctx->devs as $d) $devBy[mb_strtolower(trim($d['name']))] = $d;
  $reasonBy = []; foreach (REASONS as $x) $reasonBy[mb_strtolower($x)] = $x;
  $keyOf = function ($r) { return implode('|', [$r['unit_id'], $r['device_id'], $r['date'], trim((string)$r['old_sn']), trim((string)$r['new_sn'])]); };
  $have = []; foreach (rows('SELECT * FROM replacements WHERE project_id = ?', [cur_id()]) as $r) $have[$keyOf($r)] = 1;
  $known = ['nomor unit' => 1, 'unit' => 1, 'device' => 1, 'tanggal' => 1, 'sn lama' => 1, 'sn baru' => 1, 'alasan' => 1, 'catatan' => 1];
  $add = [];
  foreach ($ctx->rows as $idx => $r) {
    $m = imp_norm(isset($r['v']) ? $r['v'] : []);
    $n = isset($r['n']) ? $r['n'] : $idx + 2;
    if ($idx === 0) foreach (array_keys($m) as $k) { $k = (string)$k; if ($k !== '' && !isset($known[$k])) $ctx->res['unknownCols'][] = $k; }
    $unitNo = imp_pick($m, ['nomor unit', 'unit']);
    if ($unitNo === '') { imp_err($ctx, $n, 'Nomor unit kosong'); continue; }
    $uk = mb_strtolower($unitNo);
    $unit = isset($ctx->unitBy[$uk]) ? $ctx->unitBy[$uk] : null;
    if (!$unit) { imp_err($ctx, $n, 'Unit ' . $unitNo . ' belum ada di Master Unit (impor data instalasi atau unit dulu)'); continue; }
    $dname = imp_pick($m, ['device']);
    $dev = isset($devBy[mb_strtolower($dname)]) ? $devBy[mb_strtolower($dname)] : null;
    if (!$dev) { imp_err($ctx, $n, 'Device "' . $dname . '" tidak ada di Master Device'); continue; }
    $date = imp_date(imp_pick($m, ['tanggal']));
    if ($date === '') { imp_err($ctx, $n, 'Tanggal tidak valid (unit ' . $unitNo . ')'); continue; }
    $rk = mb_strtolower(imp_pick($m, ['alasan']));
    if (!isset($reasonBy[$rk])) { imp_err($ctx, $n, 'Alasan harus salah satu dari: ' . implode(', ', REASONS)); continue; }
    $oldSN = imp_pick($m, ['sn lama']); $newSN = imp_pick($m, ['sn baru']);
    if ($oldSN === '' && $newSN === '') { imp_err($ctx, $n, 'SN lama dan SN baru sama-sama kosong'); continue; }
    $rec = ['id' => uuid(), 'project_id' => cur_id(), 'unit_id' => $unit['id'], 'unit_no' => $unit['unit_no'], 'device_id' => $dev['id'], 'device_name' => $dev['name'], 'date' => $date,
            'old_sn' => $oldSN, 'new_sn' => $newSN, 'reason' => $reasonBy[$rk], 'note' => imp_pick($m, ['catatan']), 'photo' => '', 'created_by' => $ctx->label ?: 'Import', 'created_at' => $ctx->now];
    $k = $keyOf($rec);
    if (isset($have[$k])) { $ctx->res['skipped']++; imp_warn($ctx, $n, 'Sudah tercatat (duplikat), dilewati'); continue; }
    $have[$k] = 1;
    $add[] = $rec; $ctx->res['ok']++; $ctx->res['repl']++;
  }
  if ($ctx->commit) foreach ($add as $x)
    q('INSERT INTO replacements (id, project_id, unit_id, unit_no, device_id, device_name, date, old_sn, new_sn, reason, note, photo, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$x['id'], $x['project_id'], $x['unit_id'], $x['unit_no'], $x['device_id'], $x['device_name'], $x['date'], $x['old_sn'], $x['new_sn'], $x['reason'], $x['note'], $x['photo'], $x['created_by'], $x['created_at']]);
}

// Batalkan satu batch import: hapus instalasi, penggantian, dan unit baru (yang belum punya instalasi) dengan label tsb
function r_importUndo($p, $u) {
  $label = (string)(isset($p['label']) ? $p['label'] : '');
  if (!preg_match('/^Import \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $label)) fail('Label import tidak valid');
  $out = ['installs' => 0, 'repl' => 0, 'units' => 0];
  $out['installs'] = q('DELETE FROM installations WHERE project_id = ? AND created_by = ?', [cur_id(), $label])->rowCount();
  $out['repl'] = q('DELETE FROM replacements WHERE project_id = ? AND created_by = ?', [cur_id(), $label])->rowCount();
  $ids = to_ids(isset($p['unitIds']) ? $p['unitIds'] : []);
  if ($ids) {
    $has = []; foreach (rows('SELECT unit_id FROM installations WHERE project_id = ?', [cur_id()]) as $i) $has[$i['unit_id']] = 1;
    $del = array_values(array_filter($ids, function ($id) use ($has) { return !isset($has[$id]); }));
    if ($del) $out['units'] = q('DELETE FROM units WHERE project_id = ? AND id IN (' . placeholders($del) . ')', array_merge([cur_id()], $del))->rowCount();
  }
  return $out;
}

/* ---------- Import Master Unit ----------
   Kolom: Nomor Unit (wajib), Tipe, Catatan, Aktif (Ya/Tidak). Unit dicocokkan berdasarkan Nomor Unit (tidak peka huruf besar/kecil). */
function imp_bool($s) {   // '' = tidak ditentukan, null = tidak dikenali
  $x = mb_strtolower(trim((string)$s));
  if ($x === '') return '';
  if (in_array($x, ['ya', 'y', 'yes', 'aktif', 'active', '1', 'true'], true)) return 1;
  if (in_array($x, ['tidak', 't', 'no', 'n', 'nonaktif', 'non-aktif', 'inactive', '0', 'false'], true)) return 0;
  return null;
}
function imp_units($ctx) {
  $update = isset($ctx->o['onExisting']) && $ctx->o['onExisting'] === 'update';
  $known = ['nomor unit' => 1, 'unit' => 1, 'tipe' => 1, 'catatan' => 1, 'catatan unit' => 1, 'aktif' => 1];
  $seen = []; $new = []; $upd = [];
  foreach ($ctx->rows as $idx => $r) {
    $m = imp_norm(isset($r['v']) ? $r['v'] : []);
    $n = isset($r['n']) ? $r['n'] : $idx + 2;
    if ($idx === 0) foreach (array_keys($m) as $k) { $k = (string)$k; if ($k !== '' && !isset($known[$k])) $ctx->res['unknownCols'][] = $k; }
    $no = imp_pick($m, ['nomor unit', 'unit']);
    if ($no === '') { imp_err($ctx, $n, 'Nomor unit kosong'); continue; }
    if (mb_strlen($no) > 100) { imp_err($ctx, $n, 'Nomor unit terlalu panjang (maksimal 100 karakter)'); continue; }
    $uk = mb_strtolower($no);
    if (isset($seen[$uk])) { imp_err($ctx, $n, 'Unit ' . $no . ' muncul lebih dari sekali pada data ini'); continue; }
    $seen[$uk] = 1;
    $type = imp_pick($m, ['tipe']);
    $notes = imp_pick($m, ['catatan', 'catatan unit']);
    if (mb_strlen($type) > 100) { imp_err($ctx, $n, 'Tipe terlalu panjang (maksimal 100 karakter) pada unit ' . $no); continue; }
    $act = imp_bool(imp_pick($m, ['aktif']));
    if ($act === null) { imp_err($ctx, $n, 'Nilai Aktif tidak dikenali pada unit ' . $no . ' (gunakan Ya atau Tidak)'); continue; }
    $unit = isset($ctx->unitBy[$uk]) ? $ctx->unitBy[$uk] : null;
    if ($unit) {
      if (!$update) { $ctx->res['skipped']++; imp_warn($ctx, $n, 'Unit ' . $no . ' sudah ada, dilewati'); continue; }
      // kolom yang dikosongkan tidak mengubah data lama
      $upd[] = ['id' => $unit['id'], 'type' => $type !== '' ? $type : (string)$unit['type'], 'notes' => $notes !== '' ? $notes : (string)$unit['notes'],
                'active' => $act === '' ? (int)$unit['active'] : $act];
      $ctx->res['ok']++; $ctx->res['updated']++;
      continue;
    }
    $id = uuid();
    $row = ['id' => $id, 'project_id' => cur_id(), 'unit_no' => $no, 'type' => $type, 'notes' => $notes, 'active' => $act === '' ? 1 : $act];
    $ctx->unitBy[$uk] = $row;
    $new[] = $row; $ctx->res['ok']++; $ctx->res['units']++; $ctx->res['newUnitIds'][] = $id;
  }
  if ($ctx->commit) {
    foreach ($new as $x) q('INSERT INTO units (id, project_id, unit_no, type, notes, active) VALUES (?,?,?,?,?,?)', [$x['id'], $x['project_id'], $x['unit_no'], $x['type'], $x['notes'], $x['active']]);
    foreach ($upd as $x) q('UPDATE units SET type = ?, notes = ?, active = ? WHERE id = ? AND project_id = ?', [$x['type'], $x['notes'], $x['active'], $x['id'], cur_id()]);
  }
}

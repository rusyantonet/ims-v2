<?php
// Laporan: PDF (halaman HTML siap cetak -> Simpan sebagai PDF di browser) dan Excel (.xlsx dibuat langsung di server).
// Dipanggil dari api.php (pengganti generateReport_ dan generateExcel_ di Code.gs).

function fmt_d($s) {
  static $mon = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  if (is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return ((int)$m[3]) . ' ' . $mon[(int)$m[2] - 1] . ' ' . $m[1];
  return ($s === null || $s === '') ? '-' : (string)$s;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Ambil data instalasi project aktif sebagai array siap pakai (sama seperti prows_('Installations').map(instOut_))
function all_installs() {
  return array_map('inst_out', rows('SELECT * FROM installations WHERE project_id = ?', [cur_id()]));
}
function sort_by_unit(&$arr) { usort($arr, function ($a, $b) { return natcmp($a['unitNo'], $b['unitNo']); }); }
function repl_list($unitId = '') {
  $list = array_map('repl_out', $unitId !== '' ? rows('SELECT * FROM replacements WHERE project_id = ? AND unit_id = ?', [cur_id(), $unitId])
                                               : rows('SELECT * FROM replacements WHERE project_id = ?', [cur_id()]));
  usort($list, function ($a, $b) { return strcmp((string)$b['date'], (string)$a['date']) ?: strcmp((string)$b['createdAt'], (string)$a['createdAt']); });
  return $list;
}

/* ====================================================================
   LAPORAN "PDF"  -> mengembalikan HTML lengkap (dicetak dari browser)
==================================================================== */
function html_table($header, $rows, $cls = '') {
  if (!$rows) { $rows = [array_map(function ($_, $i) { return $i === 0 ? 'Tidak ada data' : '-'; }, $header, array_keys($header))]; }
  $o = '<table' . ($cls ? ' class="' . $cls . '"' : '') . '><thead><tr>';
  foreach ($header as $c) $o .= '<th>' . h($c) . '</th>';
  $o .= '</tr></thead><tbody>';
  foreach ($rows as $r) {
    $o .= '<tr>';
    foreach ($r as $c) {
      if (is_array($c)) $o .= '<td class="' . h($c[1]) . '">' . h($c[0]) . '</td>';   // [teks, kelas css]
      else $o .= '<td>' . h($c) . '</td>';
    }
    $o .= '</tr>';
  }
  return $o . '</tbody></table>';
}

function r_generateReport($p, $u) {
  $isUnit = isset($p['scope']) && $p['scope'] === 'unit';
  $from = is_date(isset($p['from']) ? $p['from'] : '') ? $p['from'] : '';
  $to   = is_date(isset($p['to']) ? $p['to'] : '') ? $p['to'] : '';
  if ($from && $to && $from > $to) fail('Tanggal awal tidak boleh setelah tanggal akhir');

  $allUnits = rows('SELECT * FROM units WHERE project_id = ?', [cur_id()]);
  $allIns = all_installs();
  if ($isUnit) {
    $uid = isset($p['unitId']) ? (string)$p['unitId'] : '';
    $ins = array_values(array_filter($allIns, function ($i) use ($uid) { return $i['unitId'] === $uid; }));
    if (!$ins) fail('Unit belum punya data instalasi');
  } else {
    $ins = array_values(array_filter($allIns, function ($i) use ($from, $to) { return (!$from || $i['date'] >= $from) && (!$to || $i['date'] <= $to); }));
    if (!$ins) fail('Tidak ada data instalasi pada rentang tanggal tersebut');
  }
  sort_by_unit($ins);
  $typeOf = []; foreach ($allUnits as $x) $typeOf[$x['id']] = $x['type'];

  $detail = $isUnit || !empty($p['detail']);
  $photos = $detail && !empty($p['photos']);
  $history = !empty($p['history']);
  $nPhotos = 0;
  if ($photos) foreach ($ins as $i) foreach ($i['details'] as $d) if (!empty($d['photo'])) $nPhotos++;
  if ($nPhotos > MAX_REPORT_PHOTOS)
    fail('Foto terlalu banyak (' . $nPhotos . '). Maksimal ' . MAX_REPORT_PHOTOS . ' foto per laporan. Persempit rentang tanggal, matikan opsi foto, atau buat laporan per unit.');
  $repl = [];
  if ($history) {
    $ids = []; foreach ($ins as $i) $ids[$i['unitId']] = 1;
    foreach (repl_list() as $r) if (isset($ids[$r['unitId']]) && ($isUnit || ((!$from || $r['date'] >= $from) && (!$to || $r['date'] <= $to)))) $repl[] = $r;
  }

  $stamp = date('Ymd_Hi');
  $label = $isUnit ? preg_replace('/[^\w\-]/', '_', (string)$ins[0]['unitNo']) : 'Rekap';
  $name = 'Laporan_Instalasi_' . $label . '_' . $stamp . '.pdf';
  $project = cur_name() ?: REPORT_SUBTITLE;

  $b = '<h1>LAPORAN INSTALASI PERANGKAT</h1><div class="sub">' . h($project) . '</div>';
  if (!$isUnit) {
    $b .= '<div class="mut">Periode tanggal instalasi: ' . (($from || $to) ? ($from ? fmt_d($from) : 'awal') . ' s/d ' . ($to ? fmt_d($to) : 'sekarang') : 'Semua periode') . '</div>';

    $active = array_values(array_filter($allUnits, function ($x) { return (bool)$x['active']; }));
    $activeIds = []; foreach ($active as $x) $activeIds[$x['id']] = 1;
    $insAct = array_values(array_filter($allIns, function ($i) use ($activeIds) { return isset($activeIds[$i['unitId']]); }));
    $complete = 0; $sum = 0; foreach ($insAct as $i) { if ($i['pct'] === 100) $complete++; $sum += $i['pct']; }
    $pending = count($insAct) - $complete; $notYet = count($active) - count($insAct);
    $overall = count($active) ? (int)round($sum / count($active)) : 0;

    $b .= '<h2>Ringkasan</h2>' . html_table(['Ringkasan', 'Jumlah'], [
      ['Progress keseluruhan', $overall . '%'], ['Jumlah unit aktif', (string)count($active)],
      ['Jumlah instalasi lengkap (100%)', (string)$complete], ['Jumlah instalasi pending (sebagian device pending)', (string)$pending],
      ['Jumlah belum diinstal', (string)$notYet]], 'sum');
    if ($from || $to) $b .= '<div class="mut sm">Catatan: ringkasan mencakup seluruh unit aktif tanpa dibatasi periode. Tabel rekap per unit mengikuti periode di atas.</div>';

    $rowsR = [];
    foreach ($ins as $k => $i) {
      $pend = []; foreach ($i['details'] as $d) if ($d['status'] === 'Pending') $pend[] = $d['name'];
      $rowsR[] = [(string)($k + 1), (string)$i['unitNo'], isset($typeOf[$i['unitId']]) && $typeOf[$i['unitId']] !== '' ? $typeOf[$i['unitId']] : '-', fmt_d($i['date']),
                  $i['installed'] . '/' . $i['total'] . ' (' . $i['pct'] . '%)', $pend ? implode(', ', $pend) : '-'];
    }
    $b .= '<h2>Rekap per Unit</h2>' . html_table(['No', 'Unit', 'Tipe', 'Tanggal', 'Progress', 'Device Pending'], $rowsR);
  }

  if ($detail) {
    foreach ($ins as $k => $i) {
      $brk = !$isUnit || $k > 0;
      $tp = isset($typeOf[$i['unitId']]) && $typeOf[$i['unitId']] !== '' ? ' &middot; ' . h($typeOf[$i['unitId']]) : '';
      $b .= '<div class="unit' . ($brk ? ' brk' : '') . '"><h3>' . h($i['unitNo']) . $tp . '</h3>'
          . '<div class="mut">Tanggal instalasi: ' . h(fmt_d($i['date'])) . ' &middot; Progress: ' . $i['installed'] . '/' . $i['total'] . ' (' . $i['pct'] . '%)</div>';
      $rowsD = [];
      foreach ($i['details'] as $d) $rowsD[] = [$d['name'], $d['status'] === 'Pending' ? [$d['status'], 'pend'] : $d['status'], !empty($d['sn']) ? $d['sn'] : '-'];
      $b .= html_table(['Device', 'Status', 'Serial Number'], $rowsD);
      if ($history) {
        $b .= '<h4>Riwayat Penggantian</h4>';
        $hs = array_values(array_filter($repl, function ($r) use ($i) { return $r['unitId'] === $i['unitId']; }));
        if (!$hs) $b .= '<div class="mut">Tidak ada penggantian perangkat.</div>';
        else {
          $rowsH = [];
          foreach ($hs as $r) $rowsH[] = [fmt_d($r['date']), $r['deviceName'], $r['oldSN'] ?: '-', $r['newSN'] ?: '-', $r['reason'] . ($r['note'] ? ' - ' . $r['note'] : '') . ' (oleh ' . $r['createdBy'] . ')'];
          $b .= html_table(['Tanggal', 'Device', 'SN Lama', 'SN Baru', 'Alasan'], $rowsH);
        }
      }
      $b .= '</div>';
      $list = []; if ($photos) foreach ($i['details'] as $d) if (!empty($d['photo'])) $list[] = $d;
      if ($list) {
        $b .= '<div class="brk"><h4>Foto Instalasi &mdash; ' . h($i['unitNo']) . '</h4><div class="grid">';
        foreach ($list as $d) {
          $src = photo_url($d['photo']);
          $b .= '<figure><figcaption>' . h($d['name'] . (!empty($d['sn']) ? ' - SN ' . $d['sn'] : '')) . '</figcaption>'
              . ($src ? '<img src="' . h($src) . '" alt="">' : '<div class="mut">(foto tidak tersedia)</div>') . '</figure>';
        }
        $b .= '</div></div>';
      }
    }
  } elseif ($history) {
    $b .= '<h2>Riwayat Penggantian Perangkat</h2>';
    if (!$repl) $b .= '<div class="mut">Tidak ada penggantian perangkat pada laporan ini.</div>';
    else {
      $rowsH = [];
      foreach ($repl as $r) $rowsH[] = [fmt_d($r['date']), (string)$r['unitNo'], $r['deviceName'], $r['oldSN'] ?: '-', $r['newSN'] ?: '-', $r['reason'] . ($r['note'] ? ' - ' . $r['note'] : '') . ' (oleh ' . $r['createdBy'] . ')'];
      $b .= html_table(['Tanggal', 'Unit', 'Device', 'SN Lama', 'SN Baru', 'Alasan'], $rowsH);
    }
  }

  $css = '@page{size:A4;margin:14mm 12mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1d2327;font-size:10pt;margin:0;padding:0 4px}'
    . 'h1{font-size:20pt;color:#22292e;margin:0 0 2px}.sub{font-size:12pt;color:#b87d00;margin-bottom:2px}.mut{color:#6b767c;font-size:9pt;margin:2px 0 8px}.mut.sm{font-size:8pt}'
    . 'h2{font-size:13pt;color:#22292e;margin:14px 0 4px}h3{font-size:14pt;color:#22292e;margin:0 0 2px}h4{font-size:11pt;color:#22292e;margin:12px 0 4px}'
    . 'table{width:100%;border-collapse:collapse;font-size:9pt;margin:4px 0}th{background:#22292e;color:#fff;text-align:left;font-weight:bold}'
    . 'th,td{border:.5pt solid #cfd4cc;padding:3px 5px;vertical-align:top;-webkit-print-color-adjust:exact;print-color-adjust:exact}td.pend{color:#b45309;font-weight:bold}'
    . 'tr{page-break-inside:avoid}.brk{page-break-before:always}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}figure{margin:0;page-break-inside:avoid}'
    . 'figcaption{font-size:9pt;font-weight:bold;margin-bottom:3px}figure img{width:100%;max-height:330px;object-fit:contain;border:.5pt solid #cfd4cc}'
    . '.bar{position:sticky;top:0;background:#fff7e0;border-bottom:1px solid #e8a317;padding:8px 12px;margin:0 -4px 10px;font-size:10pt}.bar button{font-size:10pt;padding:5px 12px;cursor:pointer}'
    . '@media print{.bar{display:none}}';
  $js = 'window.addEventListener("load",function(){var im=[].slice.call(document.images);Promise.all(im.map(function(i){return i.complete?1:new Promise(function(r){i.onload=i.onerror=r;});}))'
      . '.then(function(){setTimeout(function(){window.print();},300);});});';
  $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>' . h(preg_replace('/\.pdf$/', '', $name)) . '</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body>'
        . '<div class="bar"><button onclick="window.print()">Cetak / Simpan sebagai PDF</button> &nbsp; Pada jendela cetak, pilih tujuan <b>Simpan sebagai PDF</b>, ukuran <b>A4</b>.</div>'
        . $b . '<script>' . $js . '</script></body></html>';
  return ['name' => $name, 'html' => $html, 'size' => strlen($html), 'units' => count($ins), 'photos' => $nPhotos];
}

/* ====================================================================
   EXCEL (.xlsx) - penulis XLSX minimal memakai ZipArchive (tanpa library tambahan)
==================================================================== */
function xl_col($n) { $s = ''; $n++; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function xl_esc($s) {
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
  return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// $sheets = [ ['name'=>..., 'header'=>[...], 'rows'=>[[...]], 'widths'=>[px,...]], ... ]
function build_xlsx($sheets) {
  if (!class_exists('ZipArchive')) fail('Ekstensi PHP "zip" belum aktif di hosting. Aktifkan lewat cPanel > Select PHP Version > Extensions > zip.');
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
  $z = new ZipArchive();
  if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) fail('Gagal membuat file Excel');
  $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
  $wbSheets = ''; $wbRels = '';
  foreach ($sheets as $i => $s) {
    $n = $i + 1;
    $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $wbSheets .= '<sheet name="' . xl_esc(mb_substr($s['name'], 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    $nc = count($s['header']); $nr = count($s['rows']) + 1;
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($s['widths'] as $k => $w) $x .= '<col min="' . ($k + 1) . '" max="' . ($k + 1) . '" width="' . round($w / 7, 1) . '" customWidth="1"/>';
    $x .= '</cols><sheetData>';
    $all = array_merge([$s['header']], $s['rows']);
    foreach ($all as $ri => $row) {
      $x .= '<row r="' . ($ri + 1) . '">';
      foreach ($row as $ci => $v) $x .= '<c r="' . xl_col($ci) . ($ri + 1) . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . xl_esc($v) . '</t></is></c>';
      $x .= '</row>';
    }
    $x .= '</sheetData>' . ($s['rows'] ? '<autoFilter ref="A1:' . xl_col($nc - 1) . $nr . '"/>' : '') . '</worksheet>';
    $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $x);
  }
  $z->addFromString('[Content_Types].xml', $ct . '</Types>');
  $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
  $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
    . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1D2327"/><name val="Calibri"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8A317"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs>'
    . '</styleSheet>');
  $z->close();
  $bytes = file_get_contents($tmp);
  @unlink($tmp);
  if ($bytes === false) fail('Gagal membaca file Excel');
  return $bytes;
}

function r_generateExcel($p, $u) {
  $from = is_date(isset($p['from']) ? $p['from'] : '') ? $p['from'] : '';
  $to   = is_date(isset($p['to']) ? $p['to'] : '') ? $p['to'] : '';
  if ($from && $to && $from > $to) fail('Tanggal awal tidak boleh setelah tanggal akhir');

  $typeOf = []; foreach (rows('SELECT id, type FROM units WHERE project_id = ?', [cur_id()]) as $x) $typeOf[$x['id']] = $x['type'];
  $ins = array_values(array_filter(all_installs(), function ($i) use ($from, $to) { return (!$from || $i['date'] >= $from) && (!$to || $i['date'] <= $to); }));
  sort_by_unit($ins);
  if (!$ins) fail('Tidak ada data instalasi pada rentang tanggal tersebut');

  // Kolom SN mengikuti device yang butuh SN di project ini (urut sesuai Master Device)
  $snDevices = [];
  foreach (rows('SELECT name FROM devices WHERE project_id = ? AND need_sn = 1 AND active = 1 ORDER BY sort', [cur_id()]) as $d) $snDevices[] = $d['name'];
  foreach ($ins as $i) foreach ($i['details'] as $d)      // device nonaktif / sudah tidak butuh SN tapi masih punya SN tersimpan
    if (!empty($d['sn']) && !in_array($d['name'], $snDevices, true)) $snDevices[] = $d['name'];

  $head1 = array_merge(['No', 'Unit', 'Tipe', 'Tanggal'], array_map(function ($n) { return 'SN ' . $n; }, $snDevices), ['Status Instalasi', 'Remarks']);
  $rows1 = [];
  foreach ($ins as $k => $i) {
    $r = [(string)($k + 1), (string)$i['unitNo'], (isset($typeOf[$i['unitId']]) && $typeOf[$i['unitId']] !== '') ? $typeOf[$i['unitId']] : '-', fmt_d($i['date'])];
    foreach ($snDevices as $n) {
      $d = null; foreach ($i['details'] as $x) if ($x['name'] === $n) { $d = $x; break; }
      $r[] = !$d ? '-' : (!empty($d['sn']) ? $d['sn'] : ($d['status'] === 'Pending' ? 'Pending' : '-'));
    }
    $r[] = $i['pct'] === 100 ? 'Lengkap' : 'Pending';
    $r[] = (string)$i['remarks'];
    $rows1[] = $r;
  }

  $withHist = !(isset($p['history']) && $p['history'] === false);
  $rows2 = [];
  if ($withHist) {
    $ids = []; foreach ($ins as $i) $ids[$i['unitId']] = 1;
    $rep = array_values(array_filter(repl_list(), function ($r) use ($ids, $from, $to) { return isset($ids[$r['unitId']]) && (!$from || $r['date'] >= $from) && (!$to || $r['date'] <= $to); }));
    usort($rep, function ($a, $b) { return strcmp((string)$a['date'], (string)$b['date']) ?: natcmp($a['unitNo'], $b['unitNo']); });
    foreach ($rep as $k => $r) $rows2[] = [(string)($k + 1), fmt_d($r['date']), (string)$r['unitNo'], $r['deviceName'], $r['oldSN'] ?: '-', $r['newSN'] ?: '-', $r['reason'], $r['createdBy'], (string)$r['note']];
  }

  $sheets = [['name' => 'Detail Instalasi', 'header' => $head1, 'rows' => $rows1,
              'widths' => array_merge([40, 90, 110, 95], array_fill(0, count($snDevices), 140), [120, 240])]];
  if ($withHist) $sheets[] = ['name' => 'Riwayat Penggantian', 'header' => ['No', 'Tanggal', 'Unit', 'Device', 'SN Lama', 'SN Baru', 'Alasan', 'Oleh', 'Catatan'],
                              'rows' => $rows2, 'widths' => [40, 95, 90, 140, 140, 140, 90, 120, 240]];
  $bytes = build_xlsx($sheets);
  return ['name' => 'Data_Instalasi_' . date('Ymd_Hi') . '.xlsx', 'base64' => base64_encode($bytes), 'size' => strlen($bytes),
          'units' => count($rows1), 'repl' => count($rows2), 'withHist' => $withHist];
}

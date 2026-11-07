/**
 * DOKUMENTASI INSTALASI PERANGKAT KENDARAAN TAMBANG (Multi-Project)
 * Google Apps Script (container-bound ke Google Sheet)
 * Langkah awal: jalankan fungsi setup() satu kali.
 */

const APP_TITLE = 'Dokumentasi Instalasi Unit';
const REPORT_SUBTITLE = 'MineSafeDrive - PT. Freeport Indonesia';   // cadangan jika nama project kosong
const PHOTO_FOLDER_NAME = 'Foto Instalasi Unit';
const SESSION_SECONDS = 6 * 3600;      // login berlaku 6 jam (maks CacheService)
const REQUIRE_PHOTO = false;           // ubah true jika foto wajib saat status Installed
const LOGIN_BG_FILE_ID = '1h_2Hwr4Y_2geqMbzJes1UwxFL7URFyJm';           // ID file Google Drive untuk background halaman login (file tidak perlu dibuat publik)

const SHEETS = {
  Projects:      ['id', 'name', 'active', 'createdAt'],
  Guide:         ['id', 'title', 'body', 'roles', 'public', 'active', 'sort', 'updatedAt', 'updatedBy'],
  // Users:         ['id', 'username', 'name', 'passHash', 'role', 'active', 'createdAt'],
  Users:         ['id', 'username', 'name', 'passHash', 'role', 'active', 'createdAt', 'projectIds'],
  Units:         ['id', 'unitNo', 'type', 'notes', 'active', 'projectId'],
  Devices:       ['id', 'name', 'needSN', 'needPhoto', 'active', 'sort', 'projectId'],
  Installations: ['id', 'unitId', 'unitNo', 'date', 'remarks', 'details', 'createdBy', 'createdAt', 'updatedAt', 'projectId'],
  Replacements:  ['id', 'unitId', 'unitNo', 'deviceId', 'deviceName', 'date', 'oldSN', 'newSN', 'reason', 'note', 'photo', 'createdBy', 'createdAt', 'projectId']
};
const SCOPED_ = ['Units', 'Devices', 'Installations', 'Replacements'];   // sheet yang dipisah per project
const REASONS = ['Rusak', 'Hilang', 'Upgrade', 'Lainnya'];
const ROLES = ['Admin', 'Manager', 'Engineer', 'Viewer'];
function toIds_(v) { return (Array.isArray(v) ? v : String(v || '').split(',')).map(s => String(s).trim()).filter(Boolean); }
function userProjects_(u) { return toIds_(u.projectIds); }
function canAccessProject_(u, pid) { return u.role === 'Admin' || userProjects_(u).indexOf(pid) >= 0; }

const DEFAULT_DEVICES = [
  ['DMS Camera', 1, 1], ['ADAS Camera', 1, 1], ['INCAB Camera', 1, 1], ['GPIO Box', 1, 1],
  ['Seat Vibrator', 0, 0], ['Buzzer', 0, 0], ['Strobe Lamp', 0, 0], ['GPS Antenna', 0, 0],
  ['GSM Antenna', 0, 0], ['Wifi Antenna', 0, 0], ['Bluetooth Antenna', 0, 0],
  ['Source Power', 0, 0], ['SIM Card', 1, 0], ['SSD', 0, 0]
];

// Project aktif untuk request yang sedang berjalan (diisi di api())
let CUR_PROJECT = '', CUR_PROJECT_NAME = '';

/* ============ SETUP (jalankan sekali dari editor) ============ */
function setup() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  Object.keys(SHEETS).forEach(name => {
    let sh = ss.getSheetByName(name) || ss.insertSheet(name);
    const h = SHEETS[name];
    sh.getRange(1, 1, sh.getMaxRows(), h.length).setNumberFormat('@'); // semua kolom teks
    sh.getRange(1, 1, 1, h.length).setValues([h]).setFontWeight('bold').setBackground('#e8a317');
    sh.setFrozenRows(1);
  });
  const props = PropertiesService.getScriptProperties();
  if (!props.getProperty('SALT')) props.setProperty('SALT', Utilities.getUuid());

  if (!rows_('Users').length) {
    save_('Users', { username: 'admin', name: 'Administrator', passHash: hash_('admin123'),
                     role: 'Admin', active: true, createdAt: new Date().toISOString() });
  }

  // Project default + migrasi data lama (baris tanpa projectId masuk ke project pertama)
  if (!rows_('Projects').length) save_('Projects', { name: 'Project 1', active: true, createdAt: new Date().toISOString() });
  const defId = rows_('Projects')[0].id;
    // Migrasi: user non-Admin lama yang belum punya project diberi akses ke semua project yang ada (perilaku lama tetap jalan)
  const allPid = rows_('Projects').map(x => x.id).join(',');
  rows_('Users').forEach(u => {
    if (u.role !== 'Admin' && !String(u.projectIds || '').trim()) save_('Users', { id: u.id, projectIds: allPid });
  });
  // END

  SCOPED_.forEach(n => {
    const sh = sheet_(n), col = SHEETS[n].indexOf('projectId') + 1, last = sh.getLastRow();
    if (last < 2) return;
    const rg = sh.getRange(2, col, last - 1, 1);
    rg.setValues(rg.getValues().map(r => [r[0] === '' ? defId : r[0]]));
  });
  if (!rows_('Devices').length) seedDevices_(defId);   // project baru tanpa device: isi device standar

  if (!rows_('Guide').length) seedGuide_();
  const ss0 = ss.getSheetByName('Sheet1') || ss.getSheetByName('Sheet 1');
  if (ss0 && ss.getSheets().length > 1) ss.deleteSheet(ss0);
  Logger.log('Setup selesai. Login awal: admin / admin123 (segera ganti password).');
}

/* ============ WEB APP ============ */
function doGet() {
  try {
    const out = HtmlService.createHtmlOutputFromFile('Index');
    if (out.getContent().trim().toLowerCase().indexOf('<!doctype html') !== 0)
      throw new Error('Isi file Index salah. File Index harus berupa HTML dan diawali <!DOCTYPE html> (tempel isi Index.html).');
    return out.setTitle(APP_TITLE)
      .addMetaTag('viewport', 'width=device-width, initial-scale=1')
      .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
  } catch (e) {
    const msg = String(e.message).slice(0, 300).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return HtmlService.createHtmlOutput('<h3>Gagal memuat aplikasi</h3><p>' + msg + '</p>');
  }
}

/* ============ HELPER DATA ============ */
function bool_(v) { return v === true || String(v).toLowerCase() === 'true'; }
function hash_(pw) {
  const salt = PropertiesService.getScriptProperties().getProperty('SALT') || '';
  return Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, salt + pw, Utilities.Charset.UTF_8)
    .map(b => ('0' + (b & 0xff).toString(16)).slice(-2)).join('');
}
function sheet_(name) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sh = ss.getSheetByName(name);
  if (!sh) {
    sh = ss.insertSheet(name);
    const h = SHEETS[name];
    sh.getRange(1, 1, sh.getMaxRows(), h.length).setNumberFormat('@');
    sh.getRange(1, 1, 1, h.length).setValues([h]).setFontWeight('bold').setBackground('#e8a317');
    sh.setFrozenRows(1);
  }
  return sh;
}
function norm_(k, v, tz) {
  if (v instanceof Date) return k === 'date' ? Utilities.formatDate(v, tz, 'yyyy-MM-dd') : v.toISOString();
  return v;
}
function rows_(name) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const tz = ss.getSpreadsheetTimeZone();
  const v = sheet_(name).getDataRange().getValues();
  const h = v.shift();
  return v.filter(r => r[0] !== '').map((r, i) => {
    const o = { _row: i + 2 };
    h.forEach((k, j) => o[k] = norm_(k, r[j], tz));
    return o;
  });
}
// Baca data khusus project yang sedang aktif
function prows_(name) { return rows_(name).filter(r => r.projectId === CUR_PROJECT); }

function save_(name, obj) {
  const sh = sheet_(name);
  const h = SHEETS[name];
  const toRow = o => h.map(k => (o[k] === undefined || o[k] === null) ? '' : String(o[k]));
  if (!obj.id) {
    if (SCOPED_.includes(name)) {
      if (!obj.projectId) {
        if (!CUR_PROJECT) throw new Error('Project belum dipilih');
        obj.projectId = CUR_PROJECT;
      }
    }
    obj.id = Utilities.getUuid();
  //   sh.getRange(sh.getLastRow() + 1, 1, 1, h.length).setNumberFormat('@').setValues([toRow(obj)]);
  // } else {
    obj.id = Utilities.getUuid();
    const nr = sh.getLastRow() + 1;
    if (nr > sh.getMaxRows()) sh.insertRowsAfter(sh.getMaxRows(), 1);
    sh.getRange(nr, 1, 1, h.length).setNumberFormat('@').setValues([toRow(obj)]);
  } else {
    const ex = rows_(name).find(r => r.id === obj.id);
    if (!ex) throw new Error('Data tidak ditemukan');
    obj = Object.assign({}, ex, obj);
    sh.getRange(ex._row, 1, 1, h.length).setNumberFormat('@').setValues([toRow(obj)]);
  }
  return obj;
}
function del_(name, id) {
  const ex = rows_(name).find(r => r.id === id);
  if (!ex) throw new Error('Data tidak ditemukan');
  if (SCOPED_.includes(name) && ex.projectId !== CUR_PROJECT) throw new Error('Data bukan milik project ini');
  sheet_(name).deleteRow(ex._row);
}
function natural_(a, b) { return String(a).localeCompare(String(b), undefined, { numeric: true }); }

/* ============ AUTH ============ */
function login_(p) {
  const un = String(p.username || '').trim().toLowerCase();
  const u = rows_('Users').find(x => String(x.username).toLowerCase() === un);
  if (!u || !bool_(u.active) || u.passHash !== hash_(String(p.password || '')))
    throw new Error('Username atau password salah, atau akun nonaktif.');
  const pr = rows_('Projects').find(x => x.id === p.projectId);
  if (!pr || !bool_(pr.active)) throw new Error('Pilih project yang valid.');
  if (!canAccessProject_(u, pr.id)) throw new Error('Anda tidak memiliki akses ke project ini.');
  const token = Utilities.getUuid(), cache = CacheService.getScriptCache();
  cache.put('t_' + token, u.id, SESSION_SECONDS);
  cache.put('p_' + token, pr.id, SESSION_SECONDS);
  const user = pubUser_(u);
  user.projectId = pr.id; user.projectName = pr.name;
  return { token: token, user: user };
}
// function pubUser_(u) { return { id: u.id, username: u.username, name: u.name, role: u.role, active: bool_(u.active) }; }
function pubUser_(u) { return { id: u.id, username: u.username, name: u.name, role: u.role, active: bool_(u.active), projectIds: userProjects_(u) }; }
function auth_(token) {
  const id = token && CacheService.getScriptCache().get('t_' + token);
  const u = id && rows_('Users').find(x => x.id === id);
  if (!u || !bool_(u.active)) throw new Error('Sesi berakhir, silakan login kembali.');
  return u;
}

/* ============ ROUTER (satu pintu untuk semua aksi) ============ */
// admin:true = hanya Admin | w:true = operasi tulis (dikunci) | edit:true = Viewer tidak boleh
// const ROUTES = {
//   me:            { fn: (p, u) => { const o = pubUser_(u); o.projectId = CUR_PROJECT; o.projectName = CUR_PROJECT_NAME; return o; } },
//   dashboard:     { fn: () => dashboard_() },
//   formData:      { fn: () => formData_() },
//   listInstalls:  { fn: () => listInstalls_() },
//   getInstall:    { fn: p => getInstall_(p) },
//   saveInstall:   { fn: (p, u) => saveInstall_(p, u), w: true, edit: true },
//   deleteInstall: { fn: p => del_('Installations', p.id), w: true, admin: true },
//   uploadPhoto:   { fn: p => uploadPhoto_(p), edit: true },
//   replaceDevice: { fn: (p, u) => replaceDevice_(p, u), w: true, edit: true },
//   listReplacements: { fn: p => listReplacements_(p) },
//   generateReport: { fn: (p, u) => generateReport_(p, u) },
//   generateExcel: { fn: (p, u) => generateExcel_(p, u) },
//   listProjects:  { fn: () => rows_('Projects').map(projectOut_), admin: true },
//   saveProject:   { fn: p => saveProject_(p), w: true, admin: true },
//   deleteProject: { fn: p => deleteProject_(p), w: true, admin: true },
//   listUnits:     { fn: () => prows_('Units').sort((a, b) => natural_(a.unitNo, b.unitNo)).map(unitOut_), admin: true },
//   saveUnit:      { fn: p => saveUnit_(p), w: true, admin: true },
//   deleteUnit:    { fn: p => deleteUnit_(p), w: true, admin: true },
//   listDevices:   { fn: () => prows_('Devices').sort((a, b) => a.sort - b.sort).map(deviceOut_), admin: true },
//   saveDevice:    { fn: p => saveDevice_(p), w: true, admin: true },
//   deleteDevice:  { fn: p => del_('Devices', p.id), w: true, admin: true },
//   listUsers:     { fn: () => rows_('Users').map(pubUser_), admin: true },
//   saveUser:      { fn: (p, u) => saveUser_(p, u), w: true, admin: true },
//   deleteUser:    { fn: (p, u) => deleteUser_(p, u), w: true, admin: true }
// };

// admin:true = hanya Admin | mgr:true = Admin atau Manager | w:true = tulis (dikunci) | edit:true = Viewer tidak boleh
const ROUTES = {
  me:            { fn: (p, u) => { const o = pubUser_(u); o.projectId = CUR_PROJECT; o.projectName = CUR_PROJECT_NAME; return o; } },
  dashboard:     { fn: () => dashboard_() },
  formData:      { fn: () => formData_() },
  listInstalls:  { fn: () => listInstalls_() },
  getInstall:    { fn: p => getInstall_(p) },
  saveInstall:   { fn: (p, u) => saveInstall_(p, u), w: true, edit: true },
  deleteInstall: { fn: p => del_('Installations', p.id), w: true, mgr: true },
  uploadPhoto:   { fn: p => uploadPhoto_(p), edit: true },
  replaceDevice: { fn: (p, u) => replaceDevice_(p, u), w: true, edit: true },
  listReplacements: { fn: p => listReplacements_(p) },
  generateReport: { fn: (p, u) => generateReport_(p, u) },
  generateExcel: { fn: (p, u) => generateExcel_(p, u) },
  listGuide:     { fn: (p, u) => listGuide_(u) },
  saveGuide:     { fn: (p, u) => saveGuide_(p, u), w: true, admin: true },
  deleteGuide:   { fn: p => deleteGuide_(p), w: true, admin: true },
  importRun:     { fn: p => importRun_(p), w: true, admin: true },
  importUndo:    { fn: p => importUndo_(p), w: true, admin: true },
  // listProjects:  { fn: (p, u) => rows_('Projects').filter(x => canAccessProject_(u, x.id)).map(projectOut_), mgr: true },
  listProjects:  { fn: () => rows_('Projects').map(projectOut_), admin: true },
  assignableProjects: { fn: (p, u) => rows_('Projects').filter(x => canAccessProject_(u, x.id)).map(projectOut_), mgr: true },
  // saveProject:   { fn: (p, u) => saveProject_(p, u), w: true, mgr: true },
  saveProject:   { fn: p => saveProject_(p), w: true, admin: true },
  deleteProject: { fn: p => deleteProject_(p), w: true, admin: true },
  listUnits:     { fn: () => prows_('Units').sort((a, b) => natural_(a.unitNo, b.unitNo)).map(unitOut_), mgr: true },
  saveUnit:      { fn: p => saveUnit_(p), w: true, mgr: true },
  deleteUnit:    { fn: p => deleteUnit_(p), w: true, mgr: true },
  listDevices:   { fn: () => prows_('Devices').sort((a, b) => a.sort - b.sort).map(deviceOut_), mgr: true },
  saveDevice:    { fn: p => saveDevice_(p), w: true, mgr: true },
  deleteDevice:  { fn: p => del_('Devices', p.id), w: true, mgr: true },
  listUsers:     { fn: (p, u) => listUsers_(u), mgr: true },
  saveUser:      { fn: (p, u) => saveUser_(p, u), w: true, mgr: true },
  deleteUser:    { fn: (p, u) => deleteUser_(p, u), w: true, mgr: true }
};

function api(action, token, p) {
  let lock = null;
  try {
    p = p || {};
    // Daftar project untuk dropdown di halaman login (tanpa token)
    if (action === 'publicProjects')
      return { ok: true, data: rows_('Projects').filter(x => bool_(x.active)).map(projectOut_) };
    if (action === 'loginBg') return { ok: true, data: loginBg_() };   // gambar background login (hanya file yang ditentukan di LOGIN_BG_FILE_ID)
    if (action === 'login') return { ok: true, data: login_(p) };
    if (action === 'logout') {
      const c = CacheService.getScriptCache();
      c.remove('t_' + token); c.remove('p_' + token);
      return { ok: true };
    }
    if (action === 'publicGuide') return { ok: true, data: publicGuide_() };
    const u = auth_(token);
    const pid = CacheService.getScriptCache().get('p_' + token);
    const pj = pid && rows_('Projects').find(x => x.id === pid);
    // if (!pj) throw new Error('Sesi berakhir, silakan login kembali.');
    // CUR_PROJECT = pj.id; CUR_PROJECT_NAME = pj.name;
    if (!pj || !canAccessProject_(u, pj.id)) throw new Error('Sesi berakhir, silakan login kembali.');
    CUR_PROJECT = pj.id; CUR_PROJECT_NAME = pj.name;

    const r = ROUTES[action];
    if (!r) throw new Error('Aksi tidak dikenal');
    // if (r.admin && u.role !== 'Admin') throw new Error('Akses ditolak: khusus Admin.');
    if (r.admin && u.role !== 'Admin') throw new Error('Akses ditolak: khusus Admin.');
    if (r.mgr && u.role !== 'Admin' && u.role !== 'Manager') throw new Error('Akses ditolak: khusus Admin / Manager.');
    if (r.edit && u.role === 'Viewer') throw new Error('Akses ditolak: akun Viewer hanya bisa melihat data.');
    if (r.w) { lock = LockService.getScriptLock(); lock.waitLock(20000); }
    const d = r.fn(p, u);
    return { ok: true, data: d === undefined ? null : JSON.parse(JSON.stringify(d)) };
  } catch (e) {
    return { ok: false, error: e.message };
  } finally {
    if (lock) lock.releaseLock();
  }
}

/* ============ BACKGROUND LOGIN ============ */
function loginBg_() {
  if (!LOGIN_BG_FILE_ID) return null;
  const blob = DriveApp.getFileById(LOGIN_BG_FILE_ID).getBlob();
  const bytes = blob.getBytes();
  if (!/^image\//.test(blob.getContentType())) throw new Error('File background bukan gambar');
  if (bytes.length > 2.5 * 1024 * 1024) throw new Error('Gambar background terlalu besar (' + Math.round(bytes.length / 1024) + ' KB). Perkecil hingga di bawah 2 MB.');
  return 'data:' + blob.getContentType() + ';base64,' + Utilities.base64Encode(bytes);
}

/* ============ MASTER ============ */
function projectOut_(x) { return { id: x.id, name: x.name, active: bool_(x.active) }; }
function unitOut_(u) { return { id: u.id, unitNo: u.unitNo, type: u.type, notes: u.notes, active: bool_(u.active) }; }
function deviceOut_(d) { return { id: d.id, name: d.name, needSN: bool_(d.needSN), needPhoto: bool_(d.needPhoto), active: bool_(d.active), sort: Number(d.sort) || 0 }; }

function seedDevices_(pid) {
  DEFAULT_DEVICES.forEach((d, i) => save_('Devices', { name: d[0], needSN: !!d[1], needPhoto: !!d[2], active: true, sort: i + 1, projectId: pid }));
}
function copyDevices_(fromId, toId) {
  rows_('Devices').filter(d => d.projectId === fromId).sort((a, b) => Number(a.sort) - Number(b.sort)).forEach(d =>
    save_('Devices', { name: d.name, needSN: bool_(d.needSN), needPhoto: bool_(d.needPhoto), active: bool_(d.active), sort: d.sort, projectId: toId }));
}
function saveProject_(p) {
  const name = String(p.name || '').trim();
  if (!name) throw new Error('Nama project wajib diisi');
  const all = rows_('Projects');
  if (all.some(x => x.id !== p.id && String(x.name).toLowerCase() === name.toLowerCase()))
    throw new Error('Nama project sudah ada');
  if (p.id && p.id === CUR_PROJECT && !p.active) throw new Error('Tidak bisa menonaktifkan project yang sedang dipakai');
  const src = p.copyFrom || '__default';
  if (!p.id && src !== '__default' && src !== '__empty' && !all.some(x => x.id === src)) throw new Error('Project sumber tidak ditemukan');
  const o = { id: p.id, name: name, active: !!p.active };
  if (!p.id) o.createdAt = new Date().toISOString();
  const id = save_('Projects', o).id;
  if (!p.id) {   // project baru: siapkan Master Device-nya sendiri
    if (src === '__default') seedDevices_(id);
    else if (src !== '__empty') copyDevices_(src, id);
  }
  return id;
}
function deleteProject_(p) {
  if (p.id === CUR_PROJECT) throw new Error('Tidak bisa menghapus project yang sedang dipakai');
  if (['Units', 'Installations', 'Replacements'].some(n => rows_(n).some(r => r.projectId === p.id)))
    throw new Error('Project masih punya data unit/instalasi. Nonaktifkan saja, atau hapus datanya dulu.');
  rows_('Devices').filter(d => d.projectId === p.id).sort((a, b) => b._row - a._row)
    .forEach(d => sheet_('Devices').deleteRow(d._row));   // Master Device milik project ikut dihapus
  del_('Projects', p.id);
}
function saveUnit_(p) {
  const no = String(p.unitNo || '').trim();
  if (!no) throw new Error('Nomor unit wajib diisi');
  if (prows_('Units').some(x => x.id !== p.id && String(x.unitNo).toLowerCase() === no.toLowerCase()))
    throw new Error('Nomor unit sudah ada di project ini');
  if (p.id && !prows_('Units').some(x => x.id === p.id)) throw new Error('Unit bukan milik project ini');
  return save_('Units', { id: p.id, unitNo: no, type: p.type || '', notes: p.notes || '', active: !!p.active }).id;
}
function deleteUnit_(p) {
  if (prows_('Installations').some(i => i.unitId === p.id))
    throw new Error('Unit sudah punya data instalasi. Nonaktifkan saja, atau hapus data instalasinya dulu.');
  del_('Units', p.id);
}
function saveDevice_(p) {
  const name = String(p.name || '').trim();
  if (!name) throw new Error('Nama device wajib diisi');
  const all = prows_('Devices');
  if (p.id && !all.some(x => x.id === p.id)) throw new Error('Device bukan milik project ini');
  if (all.some(x => x.id !== p.id && String(x.name).toLowerCase() === name.toLowerCase()))
    throw new Error('Nama device sudah ada di project ini');
  const o = { id: p.id, name: name, needSN: !!p.needSN, needPhoto: !!p.needPhoto, active: !!p.active };
  if (!p.id) o.sort = all.reduce((m, x) => Math.max(m, Number(x.sort) || 0), 0) + 1;   // edit tidak menimpa urutan
  return save_('Devices', o).id;
}
// function saveUser_(p, me) {
//   const un = String(p.username || '').trim().toLowerCase();
//   if (!un || !String(p.name || '').trim()) throw new Error('Username dan nama wajib diisi');
//   if (!['Admin', 'Engineer', 'Viewer'].includes(p.role)) throw new Error('Role tidak valid');
//   const all = rows_('Users');
//   if (all.some(x => x.id !== p.id && String(x.username).toLowerCase() === un)) throw new Error('Username sudah dipakai');
//   if (!p.id && !p.password) throw new Error('Password wajib diisi untuk user baru');
//   if (p.password && String(p.password).length < 6) throw new Error('Password minimal 6 karakter');
//   if (p.id === me.id && (!p.active || p.role !== 'Admin')) throw new Error('Anda tidak bisa menonaktifkan / menurunkan role akun sendiri');
//   const o = { id: p.id, username: un, name: String(p.name).trim(), role: p.role, active: !!p.active };
//   if (p.password) o.passHash = hash_(String(p.password));
//   if (!p.id) o.createdAt = new Date().toISOString();
//   return save_('Users', o).id;
// }
// function deleteUser_(p, me) {
//   if (p.id === me.id) throw new Error('Anda tidak bisa menghapus akun sendiri');
//   del_('Users', p.id);
// }

  function listUsers_(me) {
  const names = {}; rows_('Projects').forEach(x => names[x.id] = x.name);
  const adm = me.role === 'Admin', mine = userProjects_(me);
  return rows_('Users')
    .filter(x => adm || (x.role !== 'Admin' && userProjects_(x).indexOf(CUR_PROJECT) >= 0))
    .map(x => {
      const o = pubUser_(x);
      if (!adm) o.projectIds = o.projectIds.filter(id => mine.indexOf(id) >= 0);
      o.projects = o.projectIds.map(id => names[id]).filter(Boolean).join(', ');
      return o;
    });
}

function saveUser_(p, me) {
  const un = String(p.username || '').trim().toLowerCase();
  if (!un || !String(p.name || '').trim()) throw new Error('Username dan nama wajib diisi');
  if (!ROLES.includes(p.role)) throw new Error('Role tidak valid');
  const adm = me.role === 'Admin', all = rows_('Users');
  if (all.some(x => x.id !== p.id && String(x.username).toLowerCase() === un)) throw new Error('Username sudah dipakai');
  if (!p.id && !p.password) throw new Error('Password wajib diisi untuk user baru');
  if (p.password && String(p.password).length < 6) throw new Error('Password minimal 6 karakter');
  const ex = p.id ? all.find(x => x.id === p.id) : null;
  if (p.id && !ex) throw new Error('Data tidak ditemukan');
  const self = p.id === me.id;
  if (self && (!p.active || p.role !== me.role)) throw new Error('Anda tidak bisa menonaktifkan / mengubah role akun sendiri');

  let ids = [];
  if (adm) {
    const valid = {}; rows_('Projects').forEach(x => valid[x.id] = 1);
    ids = toIds_(p.projectIds).filter(id => valid[id]);
    if (p.role === 'Admin') ids = [];
    else if (!ids.length) throw new Error('Pilih minimal satu project untuk role ' + p.role);
  } else {
    const mine = userProjects_(me);
    if (!self && !['Engineer', 'Viewer'].includes(p.role)) throw new Error('Manager hanya dapat mengelola role Engineer dan Viewer.');
    if (ex && !self) {
      if (ex.role === 'Admin' || ex.role === 'Manager') throw new Error('Akses ditolak: akun ini hanya bisa diubah Admin.');
      if (userProjects_(ex).indexOf(CUR_PROJECT) < 0) throw new Error('User bukan bagian dari project ini');
    }
    if (self) ids = userProjects_(me);
    else {
      const pick = toIds_(p.projectIds).filter(id => mine.indexOf(id) >= 0);
      const keep = ex ? userProjects_(ex).filter(id => mine.indexOf(id) < 0) : [];   // akses ke project lain tidak disentuh
      ids = keep.concat(pick.filter(id => keep.indexOf(id) < 0));
      if (!ids.length) throw new Error('Pilih minimal satu project');
    }
  }
  const o = { id: p.id, username: un, name: String(p.name).trim(), role: p.role, active: !!p.active, projectIds: ids.join(',') };
  if (p.password) o.passHash = hash_(String(p.password));
  if (!p.id) o.createdAt = new Date().toISOString();
  return save_('Users', o).id;
}

function deleteUser_(p, me) {
  if (p.id === me.id) throw new Error('Anda tidak bisa menghapus akun sendiri');
  const ex = rows_('Users').find(x => x.id === p.id);
  if (!ex) throw new Error('Data tidak ditemukan');
  if (me.role === 'Admin') return del_('Users', p.id);
  if (ex.role === 'Admin' || ex.role === 'Manager') throw new Error('Akses ditolak: akun ini hanya bisa dihapus Admin.');
  const ids = userProjects_(ex);
  if (ids.indexOf(CUR_PROJECT) < 0) throw new Error('User bukan bagian dari project ini');
  const left = ids.filter(x => x !== CUR_PROJECT);
  if (!left.length) return del_('Users', p.id);                       // tidak punya project lain: akun dihapus
  save_('Users', { id: ex.id, projectIds: left.join(',') });          // masih punya project lain: hanya dikeluarkan dari project ini
}
/* ============ PANDUAN (WIKI) ============ */
const GUIDE_ROLES = ['Manager', 'Engineer', 'Viewer'];   // Admin selalu bisa membaca semua

function guideOut_(g) {
  return { id: g.id, title: g.title, body: g.body, roles: toIds_(g.roles), public: bool_(g.public),
           active: bool_(g.active), sort: Number(g.sort) || 0, updatedAt: g.updatedAt, updatedBy: g.updatedBy };
}
function guideSort_(a, b) { return a.sort - b.sort || natural_(a.title, b.title); }

function listGuide_(u) {
  const adm = u.role === 'Admin';
  return rows_('Guide').map(guideOut_)
    .filter(g => adm || g.active)
    .sort(guideSort_);
}
function publicGuide_() {   // tanpa login: semua artikel aktif
  return rows_('Guide').map(guideOut_).filter(g => g.active).sort(guideSort_)
    .map(g => ({ id: g.id, title: g.title, body: g.body }));
}
function saveGuide_(p, u) {
  const title = String(p.title || '').trim(), body = String(p.body || '').trim();
  if (!title) throw new Error('Judul wajib diisi');
  if (title.length > 120) throw new Error('Judul maksimal 120 karakter');
  if (!body) throw new Error('Isi panduan wajib diisi');
  if (body.length > 40000) throw new Error('Isi terlalu panjang (maksimal 40.000 karakter). Pecah menjadi beberapa artikel.');
  if (p.id && !rows_('Guide').some(x => x.id === p.id)) throw new Error('Artikel tidak ditemukan');
  const o = { id: p.id, title: title, body: body,
              roles: toIds_(p.roles).filter(r => GUIDE_ROLES.includes(r)).join(','),
              public: !!p.public, active: !!p.active,
              updatedAt: new Date().toISOString(), updatedBy: u.name };
  const given = String(p.sort === undefined || p.sort === null ? '' : p.sort).trim() !== '' && !isNaN(Number(p.sort));
  if (given) o.sort = Number(p.sort);
  else if (!p.id) o.sort = rows_('Guide').reduce((m, x) => Math.max(m, Number(x.sort) || 0), 0) + 1;
  return save_('Guide', o).id;
}
function deleteGuide_(p) { del_('Guide', p.id); }

/* Isi awal panduan (dibuat otomatis oleh setup() jika sheet Guide masih kosong) */
const GUIDE_SEED = [
  ['Memulai: Login dan Memilih Project', 'Manager,Engineer,Viewer', true, [
    '## Login',
    '1. Buka tautan aplikasi, lalu isi **Username** dan **Password**.',
    '2. Pilih **Project**. Anda hanya bisa masuk ke project yang sudah ditentukan Admin.',
    '3. Klik **Masuk**.',
    '## Catatan',
    '- Sesi login berlaku sekitar 6 jam, setelah itu login ulang.',
    '- Untuk pindah project, klik **Keluar**, lalu login kembali dan pilih project lain.',
    '- Jika daftar project belum terisi, tunggu beberapa detik atau klik daftar untuk memuat ulang.',
    '- Di ponsel, buka sidebar dengan tombol garis tiga di kiri atas.'
  ]],
  ['Pesan yang Sering Muncul', 'Manager,Engineer,Viewer', true, [
    '- **Username atau password salah, atau akun nonaktif**: periksa ejaan, atau minta Admin memeriksa status akun.',
    '- **Anda tidak memiliki akses ke project ini**: pilih project lain, atau minta Admin menambahkan akses.',
    '- **Sesi berakhir**: login ulang. Sesi habis setelah sekitar 6 jam atau akses project dicabut.',
    '- **Akun Viewer hanya bisa melihat data**: Viewer tidak boleh mengubah data.',
    '- **Serial Number wajib diisi**: isi SN untuk device Installed yang membutuhkannya.',
    '- **Pilih status untuk ...**: ada device yang belum dipilih statusnya.',
    '- **Foto terlalu banyak**: pada laporan PDF, kurangi cakupan, matikan opsi foto, atau buat laporan per unit.'
  ]],
  ['Dashboard', 'Manager,Engineer,Viewer', false, [
    'Dashboard menampilkan progres instalasi project aktif. Hanya **unit aktif** yang dihitung.',
    '- **Progress keseluruhan**: rata-rata progres semua unit aktif. Unit yang belum diinstal dihitung 0%.',
    '- **Instalasi lengkap**: unit dengan semua device Installed.',
    '- **Sebagian pending**: unit sudah mulai diinstal tetapi masih ada device Pending.',
    '- **Belum diinstal**: unit yang belum punya data instalasi.',
    '- **Penggantian perangkat**: total kejadian penggantian di project.',
    'Di bawahnya ada progres per device, lima device yang paling sering diganti, dan tabel progres tiap unit.'
  ]],
  ['Input Data Instalasi', 'Manager,Engineer', false, [
    '## Mengisi data',
    '1. Buka **Input Data Instalasi** dan pilih **Nomor Unit**.',
    '2. Atur **Tanggal Instalasi**.',
    '3. Untuk setiap device, pilih status **Installed** atau **Pending**.',
    '4. Untuk device Installed, isi **Serial Number** dan ambil atau pilih **foto** jika diminta.',
    '5. Isi **Remarks** bila perlu, lalu klik **Simpan Data Instalasi**.',
    '## Aturan',
    '- Semua device harus sudah dipilih statusnya.',
    '- Tunggu tulisan Foto terunggah sebelum menyimpan.',
    '- Satu unit hanya punya satu data. Memilih unit yang sudah ada akan memperbarui datanya.',
    '## Saat SN diubah',
    'Jika SN pada device yang sudah Installed diubah, sistem menanyakan pilihan berikut.',
    '- **Koreksi salah ketik**: tidak masuk riwayat.',
    '- **Penggantian perangkat**: isi alasan, tanggal, dan catatan. Kejadian tercatat di Riwayat Penggantian.'
  ]],
  ['Data Instalasi, Edit, dan Ganti Perangkat', 'Manager,Engineer,Viewer', false, [
    'Menu **Data Instalasi** menampilkan kartu per unit. Gunakan kolom cari untuk mencari nomor unit, lalu klik **Detail**.',
    '## Tombol di detail unit',
    '- **Unduh PDF**: laporan unit ini. Tersedia untuk semua role.',
    '- **Ganti Perangkat**: catat penggantian satu perangkat. Admin, Manager, Engineer.',
    '- **Edit**: buka halaman Input dengan data unit terisi. Admin, Manager, Engineer.',
    '- **Hapus**: hapus seluruh data instalasi unit. Hanya Admin dan Manager, tidak bisa dibatalkan.',
    '## Ganti Perangkat',
    '1. Pilih device. Hanya yang berstatus Installed yang bisa diganti.',
    '2. Isi **Serial Number Baru** (tidak boleh sama dengan SN lama).',
    '3. Atur tanggal, pilih alasan, isi catatan, dan unggah foto bila diminta.',
    '4. Klik **Simpan Penggantian**.'
  ]],
  ['Riwayat Penggantian dan Export Report', 'Manager,Engineer,Viewer', false, [
    '## Riwayat Penggantian',
    'Daftar semua penggantian perangkat di project aktif. Bisa dicari dan difilter berdasarkan tanggal.',
    '## Export PDF',
    '- Pilih cakupan: **Semua unit (rekap)** atau **Satu unit**.',
    '- Opsi: rentang tanggal, detail per unit, foto (maksimal 30 foto), dan riwayat penggantian.',
    '- Pembuatan PDF memakan waktu sekitar 10 sampai 60 detik. Jangan tutup halaman.',
    '- Ringkasan pada laporan rekap mencakup seluruh unit aktif, tidak dibatasi periode.',
    '## Export Excel',
    '- Sheet **Detail Instalasi**: satu baris per unit dengan kolom SN tiap device.',
    '- Sheet **Riwayat Penggantian** (opsional).'
  ]],
  ['Master Unit, Master Device, dan User Management', 'Manager', false, [
    '## Master Unit dan Master Device',
    '- Nomor unit unik per project. Unit yang sudah punya data instalasi tidak bisa dihapus, nonaktifkan saja.',
    '- Nama device unik per project. Atur apakah device butuh Serial Number dan foto.',
    '- Perubahan pengaturan device berlaku untuk input berikutnya.',
    '## User Management',
    '- Anda hanya melihat akun yang punya akses ke project yang sedang dipilih.',
    '- Anda dapat menambah dan mengedit akun **Engineer** dan **Viewer**, serta menentukan project mereka dari project milik Anda.',
    '- Tombol **Keluarkan** mengeluarkan user dari project ini. Akun baru terhapus jika user tidak punya project lain.',
    '- Akun Admin dan Manager lain hanya bisa diubah oleh Admin.'
  ]],
  ['Panduan Administrator', '', false, [
    '## Pengelolaan',
    '- **Master Project**: tambah, edit, atau hapus project. Project yang masih punya data tidak bisa dihapus, nonaktifkan saja.',
    '- **User Management**: Anda melihat semua akun. Role selain Admin wajib punya minimal satu project.',
    '- **Panduan**: artikel ini dikelola dari menu Panduan. Atur peran pembaca dan penanda tampil di halaman login.',
    '## Perawatan sistem',
    '- Jalankan `setup()` satu kali setelah pembaruan kode yang menambah sheet atau kolom.',
    '- Jalankan `authorizeReport()` dan `authorizeExcel()` satu kali untuk izin laporan.',
    '- Setelah mengubah kode, gunakan **Deploy, lalu New version**.',
    '- Ganti password bawaan segera setelah instalasi pertama.'
  ]]
];
function seedGuide_() {
  GUIDE_SEED.forEach((a, i) => save_('Guide', { title: a[0], roles: a[1], public: a[2], active: true, sort: i + 1,
    body: a[3].join('\n'), updatedAt: new Date().toISOString(), updatedBy: 'System' }));
}

/* ============ INSTALASI ============ */
function formData_() {
  return {
    units: prows_('Units').filter(u => bool_(u.active)).sort((a, b) => natural_(a.unitNo, b.unitNo)).map(unitOut_),
    devices: prows_('Devices').filter(d => bool_(d.active)).sort((a, b) => a.sort - b.sort).map(deviceOut_),
    doneUnitIds: prows_('Installations').map(i => i.unitId)
  };
}
function instOut_(i) {
  const d = JSON.parse(i.details || '[]');
  const n = d.filter(x => x.status === 'Installed').length;
  return { id: i.id, unitId: i.unitId, unitNo: i.unitNo, date: i.date, remarks: i.remarks, details: d,
           installed: n, total: d.length, pct: d.length ? Math.round(n * 100 / d.length) : 0,
           createdBy: i.createdBy, updatedAt: i.updatedAt };
}
function listInstalls_() {
  return prows_('Installations').map(instOut_).sort((a, b) => String(b.updatedAt).localeCompare(String(a.updatedAt)));
}
function getInstall_(p) {
  const i = prows_('Installations').find(x => x.unitId === p.unitId);
  return i ? instOut_(i) : null;
}
function saveInstall_(p, u) {
  const unit = prows_('Units').find(x => x.id === p.unitId);
  if (!unit) throw new Error('Pilih nomor unit terlebih dahulu');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(p.date || '')) throw new Error('Tanggal instalasi wajib diisi');
  const devs = {};
  prows_('Devices').forEach(d => devs[d.id] = d);
  const details = (p.details || []).map(x => {
    const d = devs[x.deviceId];
    if (!d) throw new Error('Device tidak ditemukan');
    if (!['Installed', 'Pending'].includes(x.status)) throw new Error('Pilih status untuk ' + d.name);
    const sn = String(x.sn || '').trim();
    if (x.status === 'Installed' && bool_(d.needSN) && !sn) throw new Error('Serial Number ' + d.name + ' wajib diisi');
    if (x.status === 'Installed' && bool_(d.needPhoto) && REQUIRE_PHOTO && !x.photo) throw new Error('Foto ' + d.name + ' wajib diunggah');
    return { deviceId: d.id, name: d.name, status: x.status,
             sn: bool_(d.needSN) ? sn : '', photo: bool_(d.needPhoto) ? (x.photo || '') : '' };
  });
  if (!details.length) throw new Error('Tidak ada device untuk disimpan');
  const now = new Date().toISOString();
  const ex = prows_('Installations').find(i => i.unitId === p.unitId); // satu unit = satu data (upsert)
  const obj = Object.assign(ex ? { id: ex.id } : { createdBy: u.name, createdAt: now }, {
    unitId: unit.id, unitNo: unit.unitNo, date: p.date, remarks: p.remarks || '',
    details: JSON.stringify(details), updatedAt: now
  });
  const oldD = ex ? JSON.parse(ex.details || '[]') : [];
  const repl = [];
  (p.changes || []).forEach(ch => {
    if (ch.type !== 'replace') return;
    const nd = details.find(x => x.deviceId === ch.deviceId), od = oldD.find(x => x.deviceId === ch.deviceId);
    if (!nd || !od || od.status !== 'Installed' || nd.status !== 'Installed' || !od.sn || nd.sn === od.sn) return;
    if (!REASONS.includes(ch.reason)) throw new Error('Pilih alasan penggantian untuk ' + nd.name);
    repl.push({ nd: nd, od: od, ch: ch });
  });
  const saved = save_('Installations', obj);
  repl.forEach(x => addRepl_(unit, x.nd, {
    date: /^\d{4}-\d{2}-\d{2}$/.test(x.ch.date || '') ? x.ch.date : p.date,
    oldSN: x.od.sn, newSN: x.nd.sn, reason: x.ch.reason, note: x.ch.note, photo: x.nd.photo
  }, u, now));
  return { id: saved.id };
}

/* ============ RIWAYAT PENGGANTIAN ============ */
function addRepl_(unit, entry, o, u, now) {
  save_('Replacements', { unitId: unit.id, unitNo: unit.unitNo, deviceId: entry.deviceId, deviceName: entry.name,
    date: o.date, oldSN: o.oldSN || '', newSN: o.newSN || '', reason: o.reason, note: o.note || '',
    photo: o.photo || '', createdBy: u.name, createdAt: now });
}
function listReplacements_(p) {
  return prows_('Replacements').filter(r => !p.unitId || r.unitId === p.unitId).map(r => ({
      id: r.id, unitId: r.unitId, unitNo: r.unitNo, deviceName: r.deviceName, date: r.date, oldSN: r.oldSN,
      newSN: r.newSN, reason: r.reason, note: r.note, photo: r.photo, createdBy: r.createdBy, createdAt: r.createdAt }))
    .sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(b.createdAt).localeCompare(String(a.createdAt)));
}
function replaceDevice_(p, u) {
  const unit = prows_('Units').find(x => x.id === p.unitId);
  if (!unit) throw new Error('Unit tidak ditemukan');
  const ins = prows_('Installations').find(i => i.unitId === p.unitId);
  if (!ins) throw new Error('Unit belum punya data instalasi');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(p.date || '')) throw new Error('Tanggal penggantian wajib diisi');
  if (!REASONS.includes(p.reason)) throw new Error('Pilih alasan penggantian');
  const details = JSON.parse(ins.details || '[]');
  const e = details.find(x => x.deviceId === p.deviceId);
  if (!e) throw new Error('Device tidak ada pada unit ini');
  if (e.status !== 'Installed') throw new Error('Hanya device berstatus Installed yang bisa diganti');
  const dev = prows_('Devices').find(d => d.id === p.deviceId);
  const needSN = dev ? bool_(dev.needSN) : !!e.sn;
  const newSN = String(p.newSN || '').trim();
  if (needSN) {
    if (!newSN) throw new Error('Serial Number baru wajib diisi');
    if (newSN === e.sn) throw new Error('Serial Number baru sama dengan yang lama');
  }
  const now = new Date().toISOString();
  addRepl_(unit, e, { date: p.date, oldSN: e.sn, newSN: needSN ? newSN : '', reason: p.reason, note: p.note, photo: p.photo }, u, now);
  e.sn = needSN ? newSN : '';
  if (dev && bool_(dev.needPhoto)) e.photo = p.photo || '';
  save_('Installations', { id: ins.id, details: JSON.stringify(details), updatedAt: now });
  return { done: true };
}
function uploadPhoto_(p) {
  const it = DriveApp.getFoldersByName(PHOTO_FOLDER_NAME);
  const folder = it.hasNext() ? it.next() : DriveApp.createFolder(PHOTO_FOLDER_NAME);
  const name = String(p.name || 'foto').replace(/[^\w.\-]/g, '_') + '_' + Date.now() + '.jpg';
  const file = folder.createFile(Utilities.newBlob(Utilities.base64Decode(p.data), 'image/jpeg', name));
  try { file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW); } catch (e) { /* kebijakan domain */ }
  return { id: file.getId() };
}

/* ============ LAPORAN PDF ============ */
// Jalankan authorizeReport() SEKALI dari editor untuk memberi izin Google Docs/Drive.
function authorizeReport() {
  const d = DocumentApp.create('tmp-authorize');
  DriveApp.getFileById(d.getId()).setTrashed(true);
  Logger.log('Izin laporan PDF OK');
}
const MAX_REPORT_PHOTOS = 30;
const MON_ = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const RC_ = { ink: '#1d2327', mut: '#6b767c', amber: '#b87d00', head: '#22292e', pend: '#b45309', line: '#cfd4cc' };
function fmtD_(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
  return m ? (+m[3]) + ' ' + MON_[+m[2] - 1] + ' ' + m[1] : (s || '-');
}
function para_(body, text, size, bold, color, after, before) {
  const p = body.appendParagraph(String(text) || ' ');
  p.setHeading(DocumentApp.ParagraphHeading.NORMAL);
  const t = p.editAsText();
  t.setFontFamily('Arial'); t.setFontSize(size); t.setBold(!!bold); t.setForegroundColor(color || RC_.ink);
  p.setSpacingAfter(after == null ? 4 : after);
  p.setSpacingBefore(before || 0);
  return p;
}
function tbl_(body, header, rows, widths) {
  const data = [header].concat(rows.length ? rows : [header.map((_, i) => i === 0 ? 'Tidak ada data' : '-')]);
  const t = body.appendTable(data);
  const st = {};
  st[DocumentApp.Attribute.FONT_FAMILY] = 'Arial';
  st[DocumentApp.Attribute.FONT_SIZE] = 9;
  st[DocumentApp.Attribute.BOLD] = false;
  st[DocumentApp.Attribute.FOREGROUND_COLOR] = RC_.ink;
  st[DocumentApp.Attribute.BORDER_COLOR] = RC_.line;
  st[DocumentApp.Attribute.BORDER_WIDTH] = 0.5;
  t.setAttributes(st);
  widths.forEach((w, i) => t.setColumnWidth(i, w));
  if (data.length * header.length <= 120) {            // tabel kecil: set ukuran font per sel agar pasti
    for (let r = 1; r < data.length; r++) for (let c = 0; c < header.length; c++) t.getCell(r, c).editAsText().setFontSize(9).setBold(false).setForegroundColor(RC_.ink);
  }
  for (let c = 0; c < header.length; c++) {
    const cell = t.getRow(0).getCell(c);
    cell.setBackgroundColor(RC_.head);
    cell.editAsText().setFontSize(9).setForegroundColor('#ffffff').setBold(true);
  }
  return t;
}
function generateReport_(p, u) {
  const tz = SpreadsheetApp.getActiveSpreadsheet().getSpreadsheetTimeZone();
  const isUnit = p.scope === 'unit';
  const re = /^\d{4}-\d{2}-\d{2}$/;
  const from = re.test(p.from || '') ? p.from : '', to = re.test(p.to || '') ? p.to : '';
  if (from && to && from > to) throw new Error('Tanggal awal tidak boleh setelah tanggal akhir');

  const allUnits = prows_('Units');
  const allIns = prows_('Installations').map(instOut_);
  let ins;
  if (isUnit) {
    ins = allIns.filter(i => i.unitId === p.unitId);
    if (!ins.length) throw new Error('Unit belum punya data instalasi');
  } else {
    ins = allIns.filter(i => (!from || i.date >= from) && (!to || i.date <= to));
    if (!ins.length) throw new Error('Tidak ada data instalasi pada rentang tanggal tersebut');
  }
  ins.sort((a, b) => natural_(a.unitNo, b.unitNo));
  const typeOf = {};
  allUnits.forEach(x => typeOf[x.id] = x.type);

  const detail = isUnit || !!p.detail, photos = detail && !!p.photos, history = !!p.history;
  let nPhotos = 0;
  if (photos) ins.forEach(i => i.details.forEach(d => { if (d.photo) nPhotos++; }));
  if (nPhotos > MAX_REPORT_PHOTOS)
    throw new Error('Foto terlalu banyak (' + nPhotos + '). Maksimal ' + MAX_REPORT_PHOTOS + ' foto per laporan. Persempit rentang tanggal, matikan opsi foto, atau buat laporan per unit.');
  let repl = [];
  if (history) {
    const ids = {};
    ins.forEach(i => ids[i.unitId] = 1);
    repl = listReplacements_({}).filter(r => ids[r.unitId] && (isUnit || ((!from || r.date >= from) && (!to || r.date <= to))));
  }

  const stamp = Utilities.formatDate(new Date(), tz, 'yyyyMMdd_HHmm');
  const label = isUnit ? String(ins[0].unitNo).replace(/[^\w\-]/g, '_') : 'Rekap';
  const name = 'Laporan_Instalasi_' + label + '_' + stamp + '.pdf';
  const doc = DocumentApp.create(name.replace(/\.pdf$/, ''));
  const id = doc.getId();
  try {
    const body = doc.getBody();
    body.setPageWidth(595.28); body.setPageHeight(841.89);
    body.setMarginTop(40); body.setMarginBottom(40); body.setMarginLeft(36); body.setMarginRight(36);

    const p0 = body.getChild(0).asParagraph();
    p0.setText('LAPORAN INSTALASI PERANGKAT');
    const t0 = p0.editAsText();
    t0.setFontFamily('Arial'); t0.setFontSize(20); t0.setBold(true); t0.setForegroundColor(RC_.head);
    p0.setSpacingAfter(2);
    para_(body, CUR_PROJECT_NAME || REPORT_SUBTITLE, 12, false, RC_.amber, 2);   // subtitle = nama project
    if (!isUnit) para_(body, 'Periode tanggal instalasi: ' + (from || to ? (from ? fmtD_(from) : 'awal') + ' s/d ' + (to ? fmtD_(to) : 'sekarang') : 'Semua periode'), 9, false, RC_.mut, 8);

    if (!isUnit) {
      // Ringkasan dihitung dari seluruh unit aktif (sama dengan Dashboard)
      const active = allUnits.filter(x => bool_(x.active));
      const activeIds = {};
      active.forEach(x => activeIds[x.id] = 1);
      const insAct = allIns.filter(i => activeIds[i.unitId]);
      const complete = insAct.filter(i => i.pct === 100).length;
      const pending = insAct.length - complete;
      const notYet = active.length - insAct.length;
      const overall = active.length ? Math.round(insAct.reduce((s, i) => s + i.pct, 0) / active.length) : 0;

      para_(body, 'Ringkasan', 13, true, RC_.head, 4, 8);
      tbl_(body, ['Ringkasan', 'Jumlah'], [
        ['Progress keseluruhan', overall + '%'],
        ['Jumlah unit aktif', String(active.length)],
        ['Jumlah instalasi lengkap (100%)', String(complete)],
        ['Jumlah instalasi pending (sebagian device pending)', String(pending)],
        ['Jumlah belum diinstal', String(notYet)]
      ], [330, 193]);
      if (from || to) para_(body, 'Catatan: ringkasan mencakup seluruh unit aktif tanpa dibatasi periode. Tabel rekap per unit mengikuti periode di atas.', 8, false, RC_.mut, 4, 4);

      para_(body, 'Rekap per Unit', 13, true, RC_.head, 4, 10);
      tbl_(body, ['No', 'Unit', 'Tipe', 'Tanggal', 'Progress', 'Device Pending'],
        ins.map((i, k) => [String(k + 1), String(i.unitNo), typeOf[i.unitId] || '-', fmtD_(i.date),
          i.installed + '/' + i.total + ' (' + i.pct + '%)',
          i.details.filter(d => d.status === 'Pending').map(d => d.name).join(', ') || '-']),
        [28, 70, 80, 70, 75, 200]);
    }

    if (detail) {
      ins.forEach((i, k) => {
        // Rekap: setiap unit di lembaran baru. Laporan satu unit: tanpa page break di awal.
        const brk = !isUnit || k > 0;
        if (brk) body.appendPageBreak();

        para_(body, String(i.unitNo) + (typeOf[i.unitId] ? ' \u00B7 ' + typeOf[i.unitId] : ''), 14, true, RC_.head, 2, brk ? 0 : 14);
        para_(body, 'Tanggal instalasi: ' + fmtD_(i.date) + ' \u00B7 Progress: ' + i.installed + '/' + i.total + ' (' + i.pct + '%)', 9, false, RC_.mut, 8);
        const dt = tbl_(body, ['Device', 'Status', 'Serial Number'],
          i.details.map(d => [d.name, d.status, d.sn || '-']), [190, 90, 243]);
        i.details.forEach((d, r) => {
          if (d.status === 'Pending') dt.getCell(r + 1, 1).editAsText().setForegroundColor(RC_.pend).setBold(true);
        });

        // Riwayat penggantian: tepat di bawah detail instalasi
        if (history) {
          para_(body, 'Riwayat Penggantian', 11, true, RC_.head, 4, 10);
          const hs = repl.filter(r => r.unitId === i.unitId);
          if (!hs.length) para_(body, 'Tidak ada penggantian perangkat.', 9, false, RC_.mut, 4);
          else tbl_(body, ['Tanggal', 'Device', 'SN Lama', 'SN Baru', 'Alasan'],
            hs.map(r => [fmtD_(r.date), r.deviceName, r.oldSN || '-', r.newSN || '-',
              r.reason + (r.note ? ' - ' + r.note : '') + ' (oleh ' + r.createdBy + ')']), [66, 104, 80, 80, 193]);
        }

        // Foto instalasi: selalu di lembaran baru
        const list = photos ? i.details.filter(d => d.photo) : [];
        if (list.length) {
          body.appendPageBreak();
          para_(body, 'Foto Instalasi', 11, true, RC_.head, 4, 0);
          const grid = [];
          for (let r = 0; r < Math.ceil(list.length / 2); r++) grid.push(['', '']);
          const gt = body.appendTable(grid);
          gt.setBorderWidth(0);
          gt.setColumnWidth(0, 261); gt.setColumnWidth(1, 261);
          list.forEach((d, n) => {
            const cell = gt.getCell(Math.floor(n / 2), n % 2);
            cell.setText(d.name + (d.sn ? ' - SN ' + d.sn : ''));
            cell.editAsText().setFontSize(9).setBold(true);
            try {
              const img = cell.appendImage(DriveApp.getFileById(d.photo).getBlob());
              const w = img.getWidth(), h = img.getHeight();
              img.setWidth(240).setHeight(Math.round(h * 240 / w));
            } catch (e) { cell.appendParagraph('(foto tidak tersedia)'); }
          });
        }
      });
    } else if (history) {
      para_(body, 'Riwayat Penggantian Perangkat', 13, true, RC_.head, 4, 12);
      if (!repl.length) para_(body, 'Tidak ada penggantian perangkat pada laporan ini.', 9, false, RC_.mut, 4);
      else tbl_(body, ['Tanggal', 'Unit', 'Device', 'SN Lama', 'SN Baru', 'Alasan'],
        repl.map(r => [fmtD_(r.date), String(r.unitNo), r.deviceName, r.oldSN || '-', r.newSN || '-',
          r.reason + (r.note ? ' - ' + r.note : '') + ' (oleh ' + r.createdBy + ')']), [62, 60, 92, 74, 74, 161]);
    }

    doc.saveAndClose();
    const bytes = DriveApp.getFileById(id).getAs('application/pdf').getBytes();
    if (bytes.length > 24 * 1024 * 1024) throw new Error('Ukuran PDF terlalu besar. Kurangi jumlah unit atau matikan opsi foto.');
    return { name: name, base64: Utilities.base64Encode(bytes), size: bytes.length, units: ins.length, photos: nPhotos };
  } finally {
    try { DriveApp.getFileById(id).setTrashed(true); } catch (e) { /* abaikan */ }
  }
}

/* ============ DASHBOARD ============ */
function dashboard_() {
  const units = prows_('Units').filter(u => bool_(u.active)).sort((a, b) => natural_(a.unitNo, b.unitNo));
  const ids = {};
  units.forEach(u => ids[u.id] = 1);
  const byUnit = {};
  prows_('Installations').filter(i => ids[i.unitId]).forEach(i => byUnit[i.unitId] = instOut_(i));

  const list = units.map(u => {
    const b = byUnit[u.id];
    return { unitNo: u.unitNo, type: u.type, date: b ? b.date : '', installed: b ? b.installed : 0,
             total: b ? b.total : 0, pct: b ? b.pct : 0, started: !!b };
  });
  const dev = {};
  prows_('Devices').filter(d => bool_(d.active)).sort((a, b) => a.sort - b.sort).forEach(d => dev[d.name] = 0);
  Object.keys(byUnit).forEach(k => byUnit[k].details.forEach(x => {
    if (x.status === 'Installed' && x.name in dev) dev[x.name]++;
  }));
  const rp = prows_('Replacements'), rc = {};
  rp.forEach(r => rc[r.deviceName] = (rc[r.deviceName] || 0) + 1);
  const total = units.length;
  const complete = list.filter(x => x.started && x.pct === 100).length;
  const started = list.filter(x => x.started).length;
  return {
    total: total, started: started, complete: complete, partial: started - complete, notStarted: total - started,
    overall: total ? Math.round(list.reduce((s, x) => s + x.pct, 0) / total) : 0,
    devices: Object.keys(dev).map(n => ({ name: n, installed: dev[n] })),
    units: list,
    replTotal: rp.length,
    replByDevice: Object.keys(rc).map(n => ({ name: n, count: rc[n] })).sort((a, b) => b.count - a.count).slice(0, 5)
  };
}

/* ============ EXPORT EXCEL ============ */
// Jalankan authorizeExcel() SEKALI dari editor untuk memberi izin Spreadsheet/Drive/UrlFetch.
function authorizeExcel() {
  const ss = SpreadsheetApp.create('tmp-authorize-xlsx');
  const r = UrlFetchApp.fetch('https://docs.google.com/spreadsheets/d/' + ss.getId() + '/export?format=xlsx',
    { headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() }, muteHttpExceptions: true });
  DriveApp.getFileById(ss.getId()).setTrashed(true);
  Logger.log('Izin Excel OK (HTTP ' + r.getResponseCode() + ')');
}

function writeSheet_(sh, header, rows, widths) {
  const n = header.length;
  sh.getRange(1, 1, Math.max(rows.length + 1, 2), n).setNumberFormat('@');   // semua teks (SN tidak berubah format)
  sh.getRange(1, 1, 1, n).setValues([header])
    .setFontWeight('bold').setBackground('#e8a317').setFontColor('#1d2327').setVerticalAlignment('middle');
  if (rows.length) {
    sh.getRange(2, 1, rows.length, n).setValues(rows).setVerticalAlignment('top');
    sh.getRange(1, 1, rows.length + 1, n).createFilter();
  }
  sh.setFrozenRows(1);
  widths.forEach((w, i) => sh.setColumnWidth(i + 1, w));
}

function generateExcel_(p, u) {
  const tz = SpreadsheetApp.getActiveSpreadsheet().getSpreadsheetTimeZone();
  const re = /^\d{4}-\d{2}-\d{2}$/;
  const from = re.test(p.from || '') ? p.from : '', to = re.test(p.to || '') ? p.to : '';
  if (from && to && from > to) throw new Error('Tanggal awal tidak boleh setelah tanggal akhir');

  const typeOf = {};
  prows_('Units').forEach(x => typeOf[x.id] = x.type);
  const ins = prows_('Installations').map(instOut_)
    .filter(i => (!from || i.date >= from) && (!to || i.date <= to))
    .sort((a, b) => natural_(a.unitNo, b.unitNo));
  if (!ins.length) throw new Error('Tidak ada data instalasi pada rentang tanggal tersebut');

  // Kolom SN mengikuti device yang butuh SN di project ini (urut sesuai Master Device)
  const snDevices = prows_('Devices').filter(d => bool_(d.needSN) && bool_(d.active))
    .sort((a, b) => Number(a.sort) - Number(b.sort)).map(d => d.name);
  ins.forEach(i => i.details.forEach(d => {          // device nonaktif / sudah tidak butuh SN tapi masih punya SN tersimpan
    if (d.sn && snDevices.indexOf(d.name) < 0) snDevices.push(d.name);
  }));

  // Sheet 1: Detail Instalasi (satu baris per unit)
  const head1 = ['No', 'Unit', 'Tipe', 'Tanggal'].concat(snDevices.map(n => 'SN ' + n), ['Status Instalasi', 'Remarks']);
  const rows1 = ins.map((i, k) => [String(k + 1), String(i.unitNo), typeOf[i.unitId] || '-', fmtD_(i.date)]
    .concat(snDevices.map(n => {
      const d = i.details.find(x => x.name === n);
      return !d ? '-' : (d.sn || (d.status === 'Pending' ? 'Pending' : '-'));
    }))
    .concat([i.pct === 100 ? 'Lengkap' : 'Pending', i.remarks || '']));

  // Sheet 2: Riwayat Penggantian
  const withHist = p.history !== false;
  let rows2 = [];
  if (withHist) {
    const ids = {};
    ins.forEach(i => ids[i.unitId] = 1);
    rows2 = listReplacements_({})
      .filter(r => ids[r.unitId] && (!from || r.date >= from) && (!to || r.date <= to))
      .sort((a, b) => String(a.date).localeCompare(String(b.date)) || natural_(a.unitNo, b.unitNo))
      .map((r, k) => [String(k + 1), fmtD_(r.date), String(r.unitNo), r.deviceName, r.oldSN || '-', r.newSN || '-',
                      r.reason, r.createdBy, r.note || '']);
  }

  const stamp = Utilities.formatDate(new Date(), tz, 'yyyyMMdd_HHmm');
  const name = 'Data_Instalasi_' + stamp + '.xlsx';
  const ss = SpreadsheetApp.create(name.replace(/\.xlsx$/, ''));
  const id = ss.getId();
  try {
    const s1 = ss.getSheets()[0];
    s1.setName('Detail Instalasi');
    writeSheet_(s1, head1, rows1, [40, 90, 110, 95].concat(snDevices.map(() => 140), [120, 240]));
    if (withHist) {
      const s2 = ss.insertSheet('Riwayat Penggantian');
      writeSheet_(s2, ['No', 'Tanggal', 'Unit', 'Device', 'SN Lama', 'SN Baru', 'Alasan', 'Oleh', 'Catatan'],
        rows2, [40, 95, 90, 140, 140, 140, 90, 120, 240]);
    }
    SpreadsheetApp.flush();
    const res = UrlFetchApp.fetch('https://docs.google.com/spreadsheets/d/' + id + '/export?format=xlsx',
      { headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() }, muteHttpExceptions: true });
    if (res.getResponseCode() !== 200) throw new Error('Gagal membuat file Excel (HTTP ' + res.getResponseCode() + '). Pastikan authorizeExcel() sudah dijalankan.');
    const bytes = res.getBlob().getBytes();
    return { name: name, base64: Utilities.base64Encode(bytes), size: bytes.length, units: rows1.length, repl: rows2.length, withHist: withHist };
  } finally {
    try { DriveApp.getFileById(id).setTrashed(true); } catch (e) { /* abaikan */ }
  }
}
/* ============ IMPORT DATA (khusus Admin) ============ */
function rowArr_(name, o) { return SHEETS[name].map(k => (o[k] === undefined || o[k] === null) ? '' : String(o[k])); }

// Tulis banyak baris sekaligus; menambah baris sheet jika kurang
function appendRows_(name, objs) {
  if (!objs.length) return;
  const sh = sheet_(name), h = SHEETS[name];
  const start = sh.getLastRow() + 1, need = start + objs.length - 1;
  if (need > sh.getMaxRows()) sh.insertRowsAfter(sh.getMaxRows(), need - sh.getMaxRows());
  sh.getRange(start, 1, objs.length, h.length).setNumberFormat('@').setValues(objs.map(o => rowArr_(name, o)));
}
// Hapus baris (dari bawah ke atas, dikelompokkan per rentang berurutan)
function deleteRows_(name, list) {
  if (!list.length) return 0;
  const sh = sheet_(name), rows = list.map(r => r._row).sort((a, b) => b - a);
  let i = 0;
  while (i < rows.length) {
    let j = i;
    while (j + 1 < rows.length && rows[j + 1] === rows[j] - 1) j++;
    sh.deleteRows(rows[j], j - i + 1);
    i = j + 1;
  }
  return rows.length;
}

function impNorm_(v) {
  const m = {};
  Object.keys(v || {}).forEach(k => m[String(k).trim().toLowerCase()] = String(v[k] == null ? '' : v[k]).trim());
  return m;
}
function impPick_(m, keys) { for (let i = 0; i < keys.length; i++) if (m[keys[i]] !== undefined) return m[keys[i]]; return ''; }
function impDate_(s) {   // yyyy-mm-dd, atau dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy (hari lebih dulu)
  s = String(s || '').trim();
  let y, mo, d, m;
  if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})/.exec(s))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
  else if ((m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/.exec(s))) { d = +m[1]; mo = +m[2]; y = +m[3]; }
  else return '';
  const t = new Date(y, mo - 1, d);
  if (t.getFullYear() !== y || t.getMonth() !== mo - 1 || t.getDate() !== d) return '';
  return y + '-' + ('0' + mo).slice(-2) + '-' + ('0' + d).slice(-2);
}
function impStatus_(s) {   // '' = kosong, null = tidak dikenali
  const x = String(s || '').trim().toLowerCase();
  if (!x) return '';
  if (['installed', 'terpasang', 'terinstal', 'terinstall', 'sudah', 'ya', 'yes', 'y', '1', 'ok', 'true'].includes(x)) return 'Installed';
  if (['pending', 'belum', 'tidak', 'no', 'n', '0', 'false', '-'].includes(x)) return 'Pending';
  return null;
}
function impPhotoId_(s) {   // ID file Drive atau tautan Drive; null = tidak valid
  s = String(s || '').trim();
  if (!s) return '';
  const m = /\/d\/([\w-]{20,})/.exec(s) || /[?&]id=([\w-]{20,})/.exec(s);
  return m ? m[1] : (/^[\w-]{20,}$/.test(s) ? s : null);
}

// p: { type:'install'|'repl', mode:'check'|'commit', rows:[{n,v}], opts:{createUnits,onExisting,allowNoSN}, label }
function importRun_(p) {
  const commit = p.mode === 'commit';
  const rows = Array.isArray(p.rows) ? p.rows : [];
  if (!rows.length) throw new Error('Tidak ada baris untuk diproses');
  if (rows.length > 300) throw new Error('Maksimal 300 baris per permintaan');
  const label = String(p.label || '');
  if (commit && !/^Import \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(label)) throw new Error('Label import tidak valid');
  const res = { total: rows.length, ok: 0, skipped: 0, errors: [], warns: [], units: 0, installs: 0, updated: 0, repl: 0, newUnitIds: [], unknownCols: [] };
  const ctx = { rows: rows, o: p.opts || {}, res: res, commit: commit, label: label, now: new Date().toISOString(),
    devs: prows_('Devices'), unitBy: {},
    err: (n, msg) => res.errors.push({ n: n, msg: msg }),
    warn: (n, msg) => res.warns.push({ n: n, msg: msg }) };
  prows_('Units').forEach(x => ctx.unitBy[String(x.unitNo).trim().toLowerCase()] = x);
  return p.type === 'repl' ? impRepl_(ctx) : impInstall_(ctx);
}

function impInstall_(ctx) {
  const rows = ctx.rows, res = ctx.res, o = ctx.o, err = ctx.err, warn = ctx.warn, unitBy = ctx.unitBy;
  const devs = ctx.devs.filter(d => bool_(d.active)).sort((a, b) => Number(a.sort) - Number(b.sort));
  if (!devs.length) throw new Error('Master Device project ini kosong atau tidak ada yang aktif');
  const insBy = {};
  prows_('Installations').forEach(i => insBy[i.unitId] = i);
  const known = { 'nomor unit': 1, 'unit': 1, 'tipe': 1, 'catatan unit': 1, 'tanggal': 1, 'remarks': 1 };
  devs.forEach(d => { const k = String(d.name).trim().toLowerCase(); known[k + ' - status'] = 1; known[k + ' - sn'] = 1; known[k + ' - foto'] = 1; });

  const seen = {}, newUnits = [], newIns = [], upd = [];
  rows.forEach((r, idx) => {
    const m = impNorm_(r.v), n = r.n;
    if (idx === 0) Object.keys(m).forEach(k => { if (k && !known[k]) res.unknownCols.push(k); });
    const unitNo = impPick_(m, ['nomor unit', 'unit']);
    if (!unitNo) return err(n, 'Nomor unit kosong');
    const uk = unitNo.toLowerCase();
    if (seen[uk]) return err(n, 'Unit ' + unitNo + ' muncul lebih dari sekali pada data ini');
    seen[uk] = 1;
    const date = impDate_(impPick_(m, ['tanggal']));
    if (!date) return err(n, 'Tanggal tidak valid untuk unit ' + unitNo + ' (gunakan yyyy-mm-dd atau dd/mm/yyyy)');
    let unit = unitBy[uk];
    if (!unit && !o.createUnits) return err(n, 'Unit ' + unitNo + ' belum ada di Master Unit');
    const ex = unit ? insBy[unit.id] : null;
    if (ex && o.onExisting !== 'update') { res.skipped++; return warn(n, 'Unit ' + unitNo + ' sudah punya data instalasi, dilewati'); }

    const before = res.errors.length, emptyDev = [], oldD = ex ? JSON.parse(ex.details || '[]') : [];
    const details = devs.map(d => {
      const k = String(d.name).trim().toLowerCase();
      const raw = impPick_(m, [k + ' - status']);
      let st = impStatus_(raw);
      if (st === null) { err(n, 'Status ' + d.name + ' tidak dikenali: "' + raw + '" (unit ' + unitNo + ')'); st = 'Pending'; }
      else if (st === '') { st = 'Pending'; emptyDev.push(d.name); }
      let sn = bool_(d.needSN) ? impPick_(m, [k + ' - sn']) : '';
      if (st === 'Installed' && bool_(d.needSN) && !sn && !o.allowNoSN) err(n, 'Serial Number ' + d.name + ' wajib diisi (unit ' + unitNo + ')');
      if (st === 'Pending' && sn) { warn(n, 'SN ' + d.name + ' diabaikan karena status Pending'); sn = ''; }
      let photo = '';
      if (bool_(d.needPhoto)) {
        photo = impPhotoId_(impPick_(m, [k + ' - foto']));
        if (photo === null) { photo = ''; warn(n, 'Foto ' + d.name + ' tidak valid, diabaikan'); }
        if (!photo && ex) { const od = oldD.find(x => x.deviceId === d.id); if (od && od.photo) photo = od.photo; }
      }
      return { deviceId: d.id, name: d.name, status: st, sn: sn, photo: photo };
    });
    if (res.errors.length > before) return;
    if (emptyDev.length) warn(n, 'Unit ' + unitNo + ': status kosong dianggap Pending (' + emptyDev.join(', ') + ')');
    res.ok++;

    if (!unit) {
      unit = { id: Utilities.getUuid(), unitNo: unitNo, type: impPick_(m, ['tipe']), notes: impPick_(m, ['catatan unit']), active: true, projectId: CUR_PROJECT };
      unitBy[uk] = unit;
      newUnits.push(unit); res.units++; res.newUnitIds.push(unit.id);
    }
    const base = { unitId: unit.id, unitNo: unit.unitNo, date: date, remarks: impPick_(m, ['remarks']), details: JSON.stringify(details), updatedAt: ctx.now };
    if (ex) { upd.push({ ex: ex, obj: Object.assign({}, ex, base) }); res.updated++; }
    else { newIns.push(Object.assign({ id: Utilities.getUuid(), createdBy: ctx.label || 'Import', createdAt: ctx.now, projectId: CUR_PROJECT }, base)); res.installs++; }
  });

  if (ctx.commit) {
    appendRows_('Units', newUnits);
    appendRows_('Installations', newIns);
    const sh = sheet_('Installations'), h = SHEETS.Installations;
    upd.forEach(x => sh.getRange(x.ex._row, 1, 1, h.length).setNumberFormat('@').setValues([rowArr_('Installations', x.obj)]));
    SpreadsheetApp.flush();
  }
  return res;
}

function impRepl_(ctx) {
  const rows = ctx.rows, res = ctx.res, err = ctx.err, warn = ctx.warn;
  const devBy = {}; ctx.devs.forEach(d => devBy[String(d.name).trim().toLowerCase()] = d);
  const reasonBy = {}; REASONS.forEach(x => reasonBy[x.toLowerCase()] = x);
  const key = r => [r.unitId, r.deviceId, r.date, String(r.oldSN || '').trim(), String(r.newSN || '').trim()].join('|');
  const have = {}; prows_('Replacements').forEach(r => have[key(r)] = 1);
  const known = { 'nomor unit': 1, 'unit': 1, 'device': 1, 'tanggal': 1, 'sn lama': 1, 'sn baru': 1, 'alasan': 1, 'catatan': 1 };
  const add = [];
  rows.forEach((r, idx) => {
    const m = impNorm_(r.v), n = r.n;
    if (idx === 0) Object.keys(m).forEach(k => { if (k && !known[k]) res.unknownCols.push(k); });
    const unitNo = impPick_(m, ['nomor unit', 'unit']);
    if (!unitNo) return err(n, 'Nomor unit kosong');
    const unit = ctx.unitBy[unitNo.toLowerCase()];
    if (!unit) return err(n, 'Unit ' + unitNo + ' belum ada di Master Unit (impor data instalasi atau unit dulu)');
    const dname = impPick_(m, ['device']);
    const dev = devBy[dname.toLowerCase()];
    if (!dev) return err(n, 'Device "' + dname + '" tidak ada di Master Device');
    const date = impDate_(impPick_(m, ['tanggal']));
    if (!date) return err(n, 'Tanggal tidak valid (unit ' + unitNo + ')');
    const reason = reasonBy[impPick_(m, ['alasan']).toLowerCase()];
    if (!reason) return err(n, 'Alasan harus salah satu dari: ' + REASONS.join(', '));
    const oldSN = impPick_(m, ['sn lama']), newSN = impPick_(m, ['sn baru']);
    if (!oldSN && !newSN) return err(n, 'SN lama dan SN baru sama-sama kosong');
    const rec = { unitId: unit.id, unitNo: unit.unitNo, deviceId: dev.id, deviceName: dev.name, date: date, oldSN: oldSN, newSN: newSN,
                  reason: reason, note: impPick_(m, ['catatan']), photo: '', createdBy: ctx.label || 'Import', createdAt: ctx.now, projectId: CUR_PROJECT };
    const k = key(rec);
    if (have[k]) { res.skipped++; return warn(n, 'Sudah tercatat (duplikat), dilewati'); }
    have[k] = 1;
    add.push(Object.assign({ id: Utilities.getUuid() }, rec));
    res.ok++; res.repl++;
  });
  if (ctx.commit) { appendRows_('Replacements', add); SpreadsheetApp.flush(); }
  return res;
}

// Batalkan satu batch import: hapus instalasi, penggantian, dan unit baru (yang belum punya instalasi) dengan label tsb
function importUndo_(p) {
  const label = String(p.label || '');
  if (!/^Import \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(label)) throw new Error('Label import tidak valid');
  const mine = r => r.projectId === CUR_PROJECT && r.createdBy === label;
  const out = { installs: deleteRows_('Installations', prows_('Installations').filter(mine)),
                repl: deleteRows_('Replacements', prows_('Replacements').filter(mine)), units: 0 };
  const ids = toIds_(p.unitIds), has = {};
  prows_('Installations').forEach(i => has[i.unitId] = 1);
  out.units = deleteRows_('Units', prows_('Units').filter(x => ids.indexOf(x.id) >= 0 && !has[x.id]));
  return out;
}

// function fixAdminLogin() {
//   const NEW_PASSWORD = 'GANTI_DENGAN_PASSWORD_BARU';
//   const props = PropertiesService.getScriptProperties();
//   if (!props.getProperty('SALT')) props.setProperty('SALT', Utilities.getUuid());

//   const users = rows_('Users');
//   Logger.log('Jumlah user: ' + users.length + ' | ' +
//     users.map(x => x.username + '(' + x.role + ',' + x.active + ')').join(', '));

//   const u = users.find(x => String(x.username).trim().toLowerCase() === 'admin')
//          || users.find(x => x.role === 'Admin');
//   if (!u) throw new Error('Tidak ada user Admin di sheet Users');

//   save_('Users', { id: u.id, passHash: hash_(NEW_PASSWORD), role: 'Admin', active: true });

//   const c = rows_('Users').find(x => x.id === u.id);
//   Logger.log('User: ' + c.username + ' | role=' + c.role + ' | active=' + c.active +
//     ' | panjang hash=' + String(c.passHash).length + ' | cocok=' + (c.passHash === hash_(NEW_PASSWORD)));
// }
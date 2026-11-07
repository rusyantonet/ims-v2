/**
 * ENDPOINT FOTO -> GOOGLE DRIVE (dipanggil dari server cPanel, bukan dari browser pengguna)
 *
 * Cara pasang:
 * 1. script.google.com > Proyek baru. Tempel seluruh isi file ini, simpan.
 * 2. Pilih fungsi setup, klik Jalankan, izinkan akses. Salin SECRET dan FOLDER dari log.
 * 3. Deploy > Deployment baru > jenis "Aplikasi web":
 *      Jalankan sebagai: Saya | Yang memiliki akses: Siapa saja  > Deploy.
 * 4. Salin URL yang berakhiran /exec ke 'photo_drive_url' di ims_config.php,
 *    dan SECRET ke 'photo_drive_secret'.
 * Setiap mengubah kode ini: Deploy > Kelola deployment > ikon pensil > Versi baru > Deploy (URL tetap sama).
 */
const FOLDER_NAME = 'Foto Instalasi Unit';   // sama dengan folder lama, supaya foto lama dan baru satu tempat
const MAX_BYTES = 8 * 1024 * 1024;

function setup() {
  const props = PropertiesService.getScriptProperties();
  if (!props.getProperty('SECRET')) props.setProperty('SECRET', Utilities.getUuid() + Utilities.getUuid());
  const it = DriveApp.getFoldersByName(FOLDER_NAME);
  const folder = it.hasNext() ? it.next() : DriveApp.createFolder(FOLDER_NAME);
  props.setProperty('FOLDER_ID', folder.getId());
  Logger.log('SECRET (isi ke photo_drive_secret): ' + props.getProperty('SECRET'));
  Logger.log('FOLDER: ' + folder.getName() + ' (' + folder.getUrl() + ')');
}

function out_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  try {
    const props = PropertiesService.getScriptProperties();
    const p = JSON.parse(e.postData.contents);
    if (!p.secret || p.secret !== props.getProperty('SECRET')) return out_({ ok: false, error: 'unauthorized' });
    const bytes = Utilities.base64Decode(String(p.data || ''));
    if (bytes.length < 100 || bytes.length > MAX_BYTES) return out_({ ok: false, error: 'ukuran tidak valid' });
    const name = String(p.name || 'foto').replace(/[^\w.\-]/g, '_').slice(0, 80) + '_' + Date.now() + '.jpg';
    const folder = DriveApp.getFolderById(props.getProperty('FOLDER_ID'));
    const file = folder.createFile(Utilities.newBlob(bytes, 'image/jpeg', name));
    try { file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW); } catch (x) { /* kebijakan domain */ }
    return out_({ ok: true, id: file.getId() });
  } catch (err) {
    return out_({ ok: false, error: String(err.message).slice(0, 200) });
  }
}

function doGet() { return out_({ ok: true, info: 'endpoint foto aktif' }); }

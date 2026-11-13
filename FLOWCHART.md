# Flowchart Sistem IMS

Kumpulan diagram Mermaid yang mengikuti alur kode sebenarnya. GitHub merender blok ` ```mermaid ` secara otomatis.

| No | Diagram |
|---|---|
| 1 | [Arsitektur sistem](#1-arsitektur-sistem) |
| 2 | [Login dan sesi](#2-login-dan-sesi) |
| 3 | [Pipeline setiap permintaan API](#3-pipeline-setiap-permintaan-api) |
| 4 | [Hak akses per peran](#4-hak-akses-per-peran) |
| 5 | [Input data instalasi](#5-input-data-instalasi) |
| 6 | [Ganti perangkat](#6-ganti-perangkat) |
| 7 | [Unggah foto](#7-unggah-foto) |
| 8 | [Laporan PDF dan Excel](#8-laporan-pdf-dan-excel) |
| 9 | [Import data (periksa, simpan, batalkan)](#9-import-data-periksa-simpan-batalkan) |
| 10 | [Validasi per baris import](#10-validasi-per-baris-import) |
| 11 | [Aturan kelola user](#11-aturan-kelola-user) |
| 12 | [Instalasi baru dan migrasi dari Google Sheets](#12-instalasi-baru-dan-migrasi-dari-google-sheets) |
| 13 | [PWA dan service worker](#13-pwa-dan-service-worker) |
| 14 | [Relasi database (ERD)](#14-relasi-database-erd) |

---

## 1. Arsitektur sistem

```mermaid
flowchart LR
  subgraph CLIENT["Perangkat pengguna"]
    B["Browser / PWA Android<br/>index.html (JavaScript murni)"]
    SW["sw.js<br/>service worker"]
  end

  subgraph HOST["Hosting cPanel (PHP 7.4+)"]
    API["api.php<br/>router + hak akses"]
    CORE["inc/core.php<br/>konfigurasi, PDO, helper"]
    REP["inc/reports.php<br/>laporan + Excel"]
    IMP["inc/importer.php<br/>import massal"]
    UP[("uploads/<br/>foto .jpg")]
    CFG[/"ims_config.php<br/>di luar folder web"/]
    INS["install.php dan cek.php<br/>sekali pakai, hapus setelah dipakai"]
  end

  DB[("MySQL / MariaDB<br/>10 tabel")]

  subgraph GFOTO["Google: penyimpanan foto (opsional)"]
    AS["Apps Script<br/>photo-endpoint.gs"]
    GD[("Google Drive<br/>folder Foto Instalasi Unit")]
  end

  subgraph GMIG["Google: sumber data lama (sekali migrasi)"]
    GS[("Google Sheets lama")]
    EX["exportAll()<br/>apps-script-export.gs"]
  end

  B <-->|"POST JSON<br/>{action, token, p}"| API
  B --- SW
  API --> CORE
  API --> REP
  API --> IMP
  CORE -.->|"baca"| CFG
  CORE <-->|"PDO prepared statement"| DB
  API -->|"simpan foto lokal"| UP
  B -->|"tampil foto"| UP
  API -->|"cURL + SECRET<br/>bila photo_drive_url diisi"| AS
  AS --> GD
  B -->|"thumbnail foto lama"| GD
  GS --> EX
  EX -->|"7 file JSON"| INS
  INS -->|"migrasi / instalasi baru"| DB
```

---

## 2. Login dan sesi

```mermaid
flowchart TD
  A(["Buka aplikasi"]) --> B["Muat daftar project<br/>rpc publicProjects"]
  B --> C{"Server menjawab<br/>dalam 20 detik?"}
  C -- "Tidak" --> C1["Tampil pesan coba lagi"]
  C -- "Ya" --> D["Dropdown project terisi<br/>ingat project terakhir"]
  D --> E["Isi username, password, project<br/>klik Masuk"]
  E --> F["rpc login"]
  F --> G{"Gagal login<br/>15 per IP atau 10 per username<br/>dalam 15 menit?"}
  G -- "Ya" --> G1["Tolak: terlalu banyak percobaan"]
  G -- "Tidak" --> H{"User ada, aktif,<br/>dan password cocok?"}
  H -- "Tidak" --> H1["Catat ke login_fails<br/>pesan: username atau password salah"]
  H -- "Ya" --> I{"Project aktif?"}
  I -- "Tidak" --> I1["Tolak: pilih project yang valid"]
  I -- "Ya" --> J{"Admin atau<br/>ada di user_projects?"}
  J -- "Tidak" --> J1["Tolak: tidak punya akses project"]
  J -- "Ya" --> K{"Hash password<br/>format lama sha256?"}
  K -- "Ya" --> K1["Upgrade ke bcrypt"]
  K -- "Tidak" --> L
  K1 --> L["Hapus login_fails user ini<br/>dan sesi kedaluwarsa"]
  L --> M["Buat token acak 64 hex<br/>simpan di sessions, berlaku 6 jam"]
  M --> N["Browser simpan token<br/>di sessionStorage"]
  N --> O["Manager ditampilkan sebagai Admin di menu<br/>(server tetap memakai peran asli)"]
  O --> P(["Dashboard"])
  G1 --> E
  H1 --> E
  I1 --> E
  J1 --> E
```

---

## 3. Pipeline setiap permintaan API

```mermaid
flowchart TD
  A(["POST /api.php"]) --> B{"Metode POST?"}
  B -- "Tidak" --> X1["ok:false<br/>metode tidak didukung"]
  B -- "Ya" --> C{"Body JSON valid?"}
  C -- "Tidak" --> X2["ok:false<br/>permintaan tidak valid"]
  C -- "Ya" --> D{"Aksi publik?<br/>publicProjects, loginBg,<br/>login, logout, publicGuide"}
  D -- "Ya" --> D1["Jalankan langsung<br/>tanpa token"] --> Z(["Balas JSON"])
  D -- "Tidak" --> E{"Token ada di sessions<br/>dan belum kedaluwarsa?"}
  E -- "Tidak" --> X3["ok:false<br/>Sesi berakhir"]
  E -- "Ya" --> F{"User masih aktif?"}
  F -- "Tidak" --> X3
  F -- "Ya" --> G{"Project sesi masih ada<br/>dan user punya akses?"}
  G -- "Tidak" --> X3
  G -- "Ya" --> H["Set project aktif<br/>semua query dibatasi project ini"]
  H --> I{"Aksi terdaftar<br/>di tabel route?"}
  I -- "Tidak" --> X4["ok:false<br/>aksi tidak dikenal"]
  I -- "Ya" --> J{"Gerbang peran"}
  J --> J1{"admin: harus Admin?"}
  J1 -- "Gagal" --> X5["ok:false<br/>akses ditolak"]
  J1 -- "Lolos" --> J2{"mgr: Admin atau Manager?"}
  J2 -- "Gagal" --> X5
  J2 -- "Lolos" --> J3{"edit: bukan Viewer?"}
  J3 -- "Gagal" --> X5
  J3 -- "Lolos" --> K{"Operasi tulis?"}
  K -- "Ya" --> K1["BEGIN transaksi"]
  K -- "Tidak" --> L
  K1 --> L["Jalankan handler"]
  L --> M{"Berhasil?"}
  M -- "Ya" --> M1["COMMIT bila ada transaksi"] --> N["ok:true, data"] --> Z
  M -- "ApiError" --> M2["ROLLBACK"] --> M3["ok:false<br/>pesan kesalahan"] --> Z
  M -- "Error lain" --> M4["ROLLBACK<br/>catat ke error_log"] --> M5["ok:false<br/>pesan umum"] --> Z
  X1 --> Z
  X2 --> Z
  X3 --> Z
  X4 --> Z
  X5 --> Z
```

---

## 4. Hak akses per peran

Setiap peran memiliki semua kemampuan peran di sebelah kirinya, ditambah kemampuannya sendiri.

```mermaid
flowchart LR
  V["<b>Viewer</b><br/>hanya melihat<br/><br/>Dashboard<br/>Data Instalasi<br/>Riwayat Penggantian<br/>Export Report (PDF, Excel)<br/>Panduan"]
  E["<b>Engineer</b><br/>menambah data<br/><br/>Input Data Instalasi<br/>Ganti Perangkat<br/>Unggah foto"]
  M["<b>Manager</b><br/>kelola project miliknya<br/><br/>Hapus data instalasi<br/>Hapus riwayat penggantian<br/>Master Unit<br/>Master Device<br/>Import Data<br/>Kelola user Engineer dan Viewer"]
  A["<b>Admin</b><br/>semua project<br/><br/>Master Project<br/>Kelola Panduan<br/>Kelola user semua peran<br/>dan akses project<br/>Lihat dan pilih semua project"]
  V -->|"ditambah"| E -->|"ditambah"| M -->|"ditambah"| A
```

---

## 5. Input data instalasi

```mermaid
flowchart TD
  A(["Menu Input Data Instalasi"]) --> B["rpc formData<br/>unit aktif, device aktif,<br/>unit yang sudah punya data"]
  B --> C["Pilih nomor unit"]
  C --> D{"Unit sudah punya<br/>data instalasi?"}
  D -- "Ya" --> D1["rpc getInstall<br/>isi form dengan data lama"]
  D -- "Tidak" --> D2["Form kosong<br/>semua device Pending"]
  D1 --> E
  D2 --> E["Isi tanggal, status tiap device<br/>(Installed atau Pending), remarks"]
  E --> F["Device butuh SN: isi serial number<br/>Device butuh foto: ambil atau pilih foto"]
  F --> G["Foto diunggah satu per satu<br/>(lihat diagram 7)"]
  G --> H{"Edit data lama dan<br/>SN device Installed berubah?"}
  H -- "Ya" --> H1["Dialog: koreksi salah ketik<br/>atau penggantian perangkat?"]
  H1 -- "Penggantian" --> H2["Pilih alasan:<br/>Rusak, Hilang, Upgrade, Lainnya<br/>+ tanggal dan catatan"]
  H1 -- "Koreksi" --> I
  H1 -- "Batal" --> E
  H2 --> I
  H -- "Tidak" --> I["rpc saveInstall<br/>{unitId, date, details, remarks, changes}"]

  I --> S1{"Unit milik project ini?"}
  S1 -- "Tidak" --> ERR(["Error ditolak server"])
  S1 -- "Ya" --> S2{"Tanggal format yyyy-mm-dd?"}
  S2 -- "Tidak" --> ERR
  S2 -- "Ya" --> S3{"Setiap device ada<br/>di project ini?"}
  S3 -- "Tidak" --> ERR
  S3 -- "Ya" --> S4{"Status Installed<br/>atau Pending?"}
  S4 -- "Tidak" --> ERR
  S4 -- "Ya" --> S5{"Installed dan butuh SN<br/>tetapi SN kosong?"}
  S5 -- "Ya" --> ERR
  S5 -- "Tidak" --> S6{"Foto wajib aktif dan<br/>foto kosong?"}
  S6 -- "Ya" --> ERR
  S6 -- "Tidak" --> S7{"Ada minimal 1 device?"}
  S7 -- "Tidak" --> ERR
  S7 -- "Ya" --> T["Hitung penggantian dari changes:<br/>device lama Installed dan punya SN,<br/>SN baru berbeda,<br/>alasan termasuk daftar"]
  T --> U{"Alasan tidak valid?"}
  U -- "Ya" --> ERR
  U -- "Tidak" --> V{"Data instalasi unit<br/>sudah ada?"}
  V -- "Ya" --> V1["UPDATE installations<br/>tanggal, remarks, details, updated_at"]
  V -- "Tidak" --> V2["INSERT installations<br/>1 unit = 1 baris"]
  V1 --> W
  V2 --> W["INSERT replacements<br/>untuk setiap penggantian"]
  W --> X["COMMIT"]
  X --> Y(["Notifikasi tersimpan<br/>kembali ke daftar"])
```

---

## 6. Ganti perangkat

```mermaid
flowchart TD
  A(["Detail unit > Ganti Perangkat<br/>(bukan Viewer)"]) --> B["Pilih device berstatus Installed"]
  B --> C["Isi tanggal, alasan,<br/>SN baru bila device butuh SN,<br/>catatan, foto perangkat baru"]
  C --> D["rpc replaceDevice"]
  D --> E{"Unit milik project<br/>dan sudah punya data instalasi?"}
  E -- "Tidak" --> ERR(["Error ditolak server"])
  E -- "Ya" --> F{"Tanggal valid dan<br/>alasan termasuk daftar?"}
  F -- "Tidak" --> ERR
  F -- "Ya" --> G{"Device ada pada unit<br/>dan berstatus Installed?"}
  G -- "Tidak" --> ERR
  G -- "Ya" --> H{"Device butuh SN?"}
  H -- "Ya" --> H1{"SN baru terisi<br/>dan berbeda dari SN lama?"}
  H1 -- "Tidak" --> ERR
  H1 -- "Ya" --> I
  H -- "Tidak" --> I["INSERT replacements<br/>SN lama, SN baru, alasan, catatan, foto,<br/>dibuat oleh"]
  I --> J["UPDATE installations.details<br/>SN baru (dan foto bila device butuh foto)<br/>updated_at diperbarui"]
  J --> K["COMMIT"]
  K --> L(["Muat ulang detail unit<br/>riwayat bertambah"])
```

---

## 7. Unggah foto

```mermaid
flowchart TD
  A(["Pengguna memilih atau memotret foto"]) --> B["Browser: perkecil sisi terpanjang<br/>maksimal 1280 px, JPEG kualitas 0.8"]
  B --> C["Kirim base64 ke rpc uploadPhoto"]
  C --> D{"Bukan Viewer?"}
  D -- "Tidak" --> ERR1(["Akses ditolak"])
  D -- "Ya" --> E{"Base64 valid dan<br/>maksimal 6 MB?"}
  E -- "Tidak" --> ERR2(["Foto tidak valid atau terlalu besar"])
  E -- "Ya" --> F{"Isi benar-benar JPEG?<br/>getimagesizefromstring"}
  F -- "Tidak" --> ERR3(["Foto harus berformat JPEG"])
  F -- "Ya" --> G{"photo_drive_url<br/>diisi di konfigurasi?"}

  G -- "Ya" --> H["cURL POST ke Apps Script<br/>{secret, name, data}<br/>timeout 25 detik"]
  H --> I["Apps Script: SECRET cocok?"]
  I -- "Tidak" --> J
  I -- "Ya" --> I1["Buat file di folder Drive<br/>bagikan: siapa saja dengan link"]
  I1 --> I2{"Respons ok dan ID file<br/>sesuai pola?"}
  I2 -- "Ya" --> OKD(["Kembalikan ID file Drive"])
  I2 -- "Tidak" --> J{"photo_drive_fallback<br/>aktif?"}
  J -- "Tidak" --> ERR4(["Error: gagal unggah ke Google Drive"])
  J -- "Ya" --> K

  G -- "Tidak" --> K["Simpan lokal:<br/>uploads/ + 32 hex acak + .jpg"]
  K --> L{"Folder uploads<br/>dapat ditulis?"}
  L -- "Tidak" --> ERR5(["Error: atur izin folder 755 atau 775"])
  L -- "Ya" --> OKL(["Kembalikan ID loc_ + 32 hex"])

  OKD --> M["ID disimpan di installations.details<br/>atau replacements.photo"]
  OKL --> M
  M --> N{"Saat ditampilkan:<br/>ID berawalan loc_?"}
  N -- "Ya" --> N1["Muat dari uploads/xxx.jpg"]
  N -- "Tidak" --> N2["Muat thumbnail<br/>Google Drive"]
```

---

## 8. Laporan PDF dan Excel

```mermaid
flowchart TD
  A(["Menu Export Report"]) --> B{"Jenis laporan"}

  B -- "PDF" --> P1["Pilih cakupan: satu unit atau rekap,<br/>rentang tanggal, detail, foto, riwayat"]
  P1 --> P2["rpc generateReport"]
  P2 --> P3{"Tanggal awal<br/>tidak setelah akhir?"}
  P3 -- "Tidak" --> PE(["Error ditolak"])
  P3 -- "Ya" --> P4{"Ada data instalasi<br/>pada cakupan itu?"}
  P4 -- "Tidak" --> PE
  P4 -- "Ya" --> P5{"Jumlah foto di laporan<br/>maksimal 30?"}
  P5 -- "Tidak" --> PE
  P5 -- "Ya" --> P6["Susun HTML A4:<br/>ringkasan, rekap per unit,<br/>detail device, riwayat, kisi foto"]
  P6 --> P7["Server kirim {name, html}"]
  P7 --> P8["Browser buat Blob URL<br/>tombol: Buka laporan dan cetak PDF"]
  P8 --> P9["Tab baru terbuka,<br/>tunggu semua gambar dimuat"]
  P9 --> P10["window.print otomatis"]
  P10 --> P11(["Pengguna pilih<br/>Simpan sebagai PDF"])

  B -- "Excel" --> X1["Pilih rentang tanggal<br/>dan sertakan riwayat atau tidak"]
  X1 --> X2["rpc generateExcel"]
  X2 --> X3{"Tanggal valid dan<br/>ada data?"}
  X3 -- "Tidak" --> XE(["Error ditolak"])
  X3 -- "Ya" --> X4["Tentukan kolom SN:<br/>device butuh SN sesuai urutan Master Device<br/>+ device lain yang punya SN tersimpan"]
  X4 --> X5["Sheet 1 Detail Instalasi:<br/>No, Unit, Tipe, Tanggal, SN per device,<br/>Status Instalasi, Remarks"]
  X5 --> X6{"Sertakan riwayat?"}
  X6 -- "Ya" --> X7["Sheet 2 Riwayat Penggantian"]
  X6 -- "Tidak" --> X8
  X7 --> X8["Tulis .xlsx dengan ZipArchive<br/>header bold amber, panel beku, filter otomatis"]
  X8 --> X9{"Ekstensi zip aktif?"}
  X9 -- "Tidak" --> XE
  X9 -- "Ya" --> X10["Kirim base64"]
  X10 --> X11(["Browser mengunduh file .xlsx"])
```

---

## 9. Import data (periksa, simpan, batalkan)

```mermaid
flowchart TD
  A(["Menu Import Data<br/>Admin atau Manager"]) --> B{"Jenis data"}
  B -- "Master Unit" --> C
  B -- "Data Instalasi" --> C
  B -- "Riwayat Penggantian" --> C
  C["Unduh template CSV<br/>sesuai Master Device project"] --> D["Isi di Excel, simpan sebagai CSV"]
  D --> E["Pilih file"]
  E --> F{"Ukuran maksimal 5 MB<br/>dan maksimal 2000 baris?"}
  F -- "Tidak" --> FE(["Tolak: pecah file"])
  F -- "Ya" --> G["Baca CSV: deteksi pemisah<br/>titik koma, koma, atau tab"]
  G --> H{"Kolom wajib ada?<br/>unit: Nomor Unit<br/>instalasi: Nomor Unit, Tanggal<br/>riwayat: Nomor Unit, Device, Tanggal, Alasan"}
  H -- "Tidak" --> HE(["Tolak: gunakan template terbaru"])
  H -- "Ya" --> I["Buang baris duplikat nomor unit di file<br/>(kecuali jenis riwayat)"]
  I --> J["Klik Periksa data (simulasi)"]
  J --> K["Kirim per 150 baris<br/>importRun mode check"]
  K --> L["Server memvalidasi tiap baris<br/>(lihat diagram 10)<br/>TIDAK menulis ke database"]
  L --> M["Tampil hasil:<br/>baris diproses, valid, dilewati, error,<br/>kolom tidak dikenali, catatan"]
  M --> N{"Ada baris valid<br/>dan hasil sesuai?"}
  N -- "Tidak" --> D
  N -- "Ya" --> O["Klik Impor sekarang<br/>dialog konfirmasi: Ya, impor"]
  O --> P["Buat label batch<br/>Import yyyy-mm-dd hh:mm:ss"]
  P --> Q["Kirim per 150 baris<br/>importRun mode commit<br/>dalam transaksi per permintaan"]
  Q --> R["Baris valid ditulis<br/>created_by = label batch"]
  R --> S["Tampil hasil impor<br/>+ tombol Batalkan import ini"]
  S --> T{"Klik Batalkan?<br/>hanya selama halaman terbuka"}
  T -- "Tidak" --> U(["Selesai"])
  T -- "Ya" --> V["importUndo(label, unitIds baru)"]
  V --> W["Hapus instalasi dan penggantian<br/>dengan created_by = label"]
  W --> X["Hapus unit baru dari batch ini<br/>yang belum punya data instalasi"]
  X --> Y(["Import dibatalkan<br/>perubahan mode Perbarui tidak ikut dibatalkan"])
```

---

## 10. Validasi per baris import

```mermaid
flowchart TD
  A(["Satu baris CSV"]) --> B{"Jenis data"}

  B -- "Master Unit" --> U1{"Nomor unit terisi,<br/>maksimal 100 karakter?"}
  U1 -- "Tidak" --> ERR(["Baris ditolak<br/>dicatat sebagai error"])
  U1 -- "Ya" --> U2{"Nomor unit sudah muncul<br/>di batch ini?"}
  U2 -- "Ya" --> ERR
  U2 -- "Tidak" --> U3{"Tipe maksimal 100 karakter<br/>dan nilai Aktif Ya atau Tidak?"}
  U3 -- "Tidak" --> ERR
  U3 -- "Ya" --> U4{"Nomor unit sudah ada<br/>di Master Unit?<br/>(tanpa beda huruf besar kecil)"}
  U4 -- "Ya" --> U5{"Opsi Perbarui?"}
  U5 -- "Tidak" --> SKIP(["Dilewati + catatan"])
  U5 -- "Ya" --> UPD(["Perbarui Tipe, Catatan, Aktif<br/>kolom kosong tidak mengubah"])
  U4 -- "Tidak" --> NEW(["Unit baru dibuat"])

  B -- "Data Instalasi" --> I1{"Ada device aktif<br/>di Master Device?"}
  I1 -- "Tidak" --> FATAL(["Hentikan: Master Device kosong"])
  I1 -- "Ya" --> I2{"Nomor unit terisi<br/>dan belum muncul di batch?"}
  I2 -- "Tidak" --> ERR
  I2 -- "Ya" --> I3{"Tanggal valid?<br/>yyyy-mm-dd atau dd/mm/yyyy"}
  I3 -- "Tidak" --> ERR
  I3 -- "Ya" --> I4{"Unit ada di Master Unit?"}
  I4 -- "Tidak" --> I5{"Opsi buat unit baru otomatis?"}
  I5 -- "Tidak" --> ERR
  I5 -- "Ya" --> I6
  I4 -- "Ya" --> I6{"Unit sudah punya data instalasi?"}
  I6 -- "Ya" --> I7{"Opsi Perbarui?"}
  I7 -- "Tidak" --> SKIP
  I7 -- "Ya" --> I8
  I6 -- "Tidak" --> I8["Periksa tiap device aktif"]
  I8 --> I9{"Status dikenali?<br/>Installed, Terpasang, Ya, 1 ...<br/>Pending, Belum, Tidak, 0 ...<br/>kosong dianggap Pending"}
  I9 -- "Tidak" --> ERR
  I9 -- "Ya" --> I10{"Installed, device butuh SN,<br/>SN kosong, dan izin SN kosong mati?"}
  I10 -- "Ya" --> ERR
  I10 -- "Tidak" --> I11["Pending dengan SN: SN diabaikan + catatan<br/>Foto: ID atau tautan Drive,<br/>foto lama dipertahankan bila kolom kosong"]
  I11 --> I12(["Valid: instalasi baru atau diperbarui"])

  B -- "Riwayat Penggantian" --> R1{"Nomor unit ada di Master Unit?"}
  R1 -- "Tidak" --> ERR
  R1 -- "Ya" --> R2{"Nama device ada<br/>di Master Device?"}
  R2 -- "Tidak" --> ERR
  R2 -- "Ya" --> R3{"Tanggal valid dan<br/>alasan salah satu dari daftar?"}
  R3 -- "Tidak" --> ERR
  R3 -- "Ya" --> R4{"SN lama atau SN baru terisi?"}
  R4 -- "Tidak" --> ERR
  R4 -- "Ya" --> R5{"Catatan identik sudah ada?<br/>unit, device, tanggal, SN lama, SN baru"}
  R5 -- "Ya" --> SKIP
  R5 -- "Tidak" --> R6(["Valid: penggantian baru"])
```

---

## 11. Aturan kelola user

```mermaid
flowchart TD
  A(["Simpan user"]) --> B{"Username dan nama terisi,<br/>role valid, username unik?"}
  B -- "Tidak" --> ERR(["Ditolak"])
  B -- "Ya" --> C{"User baru tanpa password,<br/>atau password kurang dari 6 karakter?"}
  C -- "Ya" --> ERR
  C -- "Tidak" --> D{"Mengubah akun sendiri:<br/>nonaktif atau ganti role?"}
  D -- "Ya" --> ERR
  D -- "Tidak" --> E{"Peran pelaku"}

  E -- "Admin" --> F{"Role yang disimpan"}
  F -- "Admin" --> F1["Tanpa pembatasan project"]
  F -- "Selain Admin" --> F2{"Minimal 1 project dipilih?"}
  F2 -- "Tidak" --> ERR
  F2 -- "Ya" --> SAVE
  F1 --> SAVE

  E -- "Manager" --> G{"Mengubah akun sendiri?"}
  G -- "Ya" --> G1["Project tetap seperti sebelumnya"] --> SAVE
  G -- "Tidak" --> H{"Role yang dibuat atau diubah<br/>Engineer atau Viewer?"}
  H -- "Tidak" --> ERR
  H -- "Ya" --> I{"Mengubah user yang ada?"}
  I -- "Ya" --> I1{"Target Admin atau Manager,<br/>atau bukan bagian project ini?"}
  I1 -- "Ya" --> ERR
  I1 -- "Tidak" --> J
  I -- "Tidak" --> J["Project = pilihan yang termasuk<br/>project milik Manager<br/>+ akses project lain milik target (tidak disentuh)"]
  J --> K{"Minimal 1 project?"}
  K -- "Tidak" --> ERR
  K -- "Ya" --> SAVE

  SAVE["INSERT atau UPDATE users<br/>hash bcrypt bila password diisi<br/>ganti isi user_projects"] --> OK(["Tersimpan"])

  DEL(["Hapus user"]) --> D1{"Akun sendiri?"}
  D1 -- "Ya" --> ERR
  D1 -- "Tidak" --> D2{"Pelaku Admin?"}
  D2 -- "Ya" --> D5["Hapus user, akses project, dan sesinya"]
  D2 -- "Tidak" --> D3{"Target Admin atau Manager,<br/>atau bukan bagian project ini?"}
  D3 -- "Ya" --> ERR
  D3 -- "Tidak" --> D4{"Target punya project lain?"}
  D4 -- "Ya" --> D6["Hanya dikeluarkan dari project ini"]
  D4 -- "Tidak" --> D5
```

---

## 12. Instalasi baru dan migrasi dari Google Sheets

```mermaid
flowchart TD
  subgraph PERSIAPAN["Persiapan"]
    P1["Buat database dan user<br/>di MySQL Database Wizard"] --> P2["Impor schema.sql<br/>di phpMyAdmin"]
    P2 --> P3["Isi ims_config.php<br/>taruh di atas folder web"]
    P3 --> P4["Unggah isi public_html<br/>izin uploads 755"]
  end

  subgraph EKSPOR["Ekspor (khusus migrasi)"]
    E1["Tempel exportAll() di Code.gs lama<br/>dan jalankan"] --> E2["7 file ims_export_*.json di Drive<br/>+ SALT di log"]
    E2 --> E3["Isi legacy_salt di ims_config.php<br/>agar password lama tetap berlaku"]
  end

  P4 --> A(["Buka install.php"])
  E3 --> A
  A --> B{"install_key benar<br/>dan bukan nilai bawaan?"}
  B -- "Tidak" --> BE(["Ditolak"])
  B -- "Ya" --> C{"Tabel ada dan<br/>users serta projects kosong?"}
  C -- "Tidak" --> CE(["Berhenti: skema belum diimpor<br/>atau database sudah berisi"])
  C -- "Ya" --> D{"Mode"}

  D -- "Instalasi baru" --> N1{"Password Admin<br/>minimal 8 karakter?"}
  N1 -- "Tidak" --> DE(["Batal"])
  N1 -- "Ya" --> N2["Buat Project 1, 14 device standar,<br/>dan akun admin"]

  D -- "Migrasi" --> M1{"File JSON valid dan<br/>ada Projects dan Users?"}
  M1 -- "Tidak" --> DE
  M1 -- "Ya" --> M2["Masukkan berurutan:<br/>Projects, Users + user_projects,<br/>Units, Devices, Installations,<br/>Replacements, Guide"]
  M2 --> M3["Tiap baris dalam try-catch:<br/>duplikat atau tidak valid dilewati<br/>dan dihitung"]

  N2 --> F["COMMIT dalam satu transaksi"]
  M3 --> F
  F --> G["Layar ringkasan jumlah baris"]
  G --> H["Hapus install.php dan cek.php"]
  H --> I["Hapus file JSON dari Google Drive<br/>(berisi hash password)"]
  I --> J(["Uji login dan tampilan"])
```

---

## 13. PWA dan service worker

```mermaid
flowchart TD
  A(["Halaman dimuat"]) --> B["Daftarkan sw.js"]
  B --> C["Install: simpan shell di cache<br/>index.html, logo, ikon, manifest"]
  C --> D["Activate: hapus cache versi lama,<br/>ambil alih halaman"]

  E(["Setiap permintaan"]) --> F{"GET dan satu origin?"}
  F -- "Tidak" --> PASS1(["Lewat langsung ke jaringan<br/>api.php POST, Google Fonts, Drive"])
  F -- "Ya" --> G{"api.php, install.php, cek.php,<br/>uploads/, inc/ ?"}
  G -- "Ya" --> PASS2(["Lewat langsung<br/>tidak pernah di-cache"])
  G -- "Tidak" --> H{"Navigasi halaman?"}
  H -- "Ya" --> H1{"Halaman aplikasi<br/>(root atau index.html)?"}
  H1 -- "Tidak" --> PASS2
  H1 -- "Ya" --> H2["Jaringan dulu"]
  H2 --> H3{"Berhasil?"}
  H3 -- "Ya" --> H4["Perbarui cache index.html<br/>dan tampilkan"]
  H3 -- "Tidak, offline" --> H5["Tampilkan index.html dari cache<br/>(login dan data tetap butuh internet)"]
  H -- "Tidak" --> I["Aset statis:<br/>pakai cache, perbarui di belakang"]

  J(["Chrome Android memenuhi syarat<br/>HTTPS + manifest + service worker"]) --> K["Event beforeinstallprompt"]
  K --> L["Tampilkan tombol<br/>Pasang aplikasi di perangkat ini"]
  L --> M{"Pengguna menekan<br/>dan memilih Instal?"}
  M -- "Ya" --> N(["Ikon IMS di layar utama<br/>mode tanpa bilah alamat"])
  M -- "Tidak" --> O(["Tombol disembunyikan"])
```

---

## 14. Relasi database (ERD)

```mermaid
erDiagram
  projects ||--o{ user_projects : "diakses"
  users ||--o{ user_projects : "punya akses"
  users ||--o{ sessions : "login"
  projects ||--o{ units : "memiliki"
  projects ||--o{ devices : "memiliki"
  projects ||--o{ installations : "memiliki"
  projects ||--o{ replacements : "memiliki"
  units ||--o| installations : "satu data per unit"
  units ||--o{ replacements : "riwayat"
  devices ||--o{ replacements : "diganti"

  projects {
    char36 id PK
    varchar name UK
    tinyint active
    varchar created_at
  }
  users {
    char36 id PK
    varchar username UK
    varchar name
    varchar pass_hash
    varchar role
    tinyint active
  }
  user_projects {
    char36 user_id PK
    char36 project_id PK
  }
  units {
    char36 id PK
    char36 project_id FK
    varchar unit_no "unik per project"
    varchar type
    text notes
    tinyint active
  }
  devices {
    char36 id PK
    char36 project_id FK
    varchar name "unik per project"
    tinyint need_sn
    tinyint need_photo
    tinyint active
    int sort
  }
  installations {
    char36 id PK
    char36 project_id FK
    char36 unit_id "unik per project"
    char10 date
    text remarks
    longtext details "JSON: deviceId, name, status, sn, photo"
    varchar created_by
  }
  replacements {
    char36 id PK
    char36 project_id FK
    char36 unit_id FK
    char36 device_id FK
    char10 date
    varchar old_sn
    varchar new_sn
    varchar reason
    varchar photo "loc_xxx atau ID Google Drive"
    varchar created_by
  }
  guide {
    char36 id PK
    varchar title
    mediumtext body
    varchar roles
    tinyint is_public
    tinyint active
    int sort
  }
  sessions {
    char64 token PK
    char36 user_id FK
    char36 project_id
    int expires_at
  }
  login_fails {
    int id PK
    varchar ip
    varchar username
    int ts
  }
```

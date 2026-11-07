-- IMS (Dokumentasi Instalasi Unit) - skema MySQL / MariaDB
-- Jalankan di phpMyAdmin: pilih database > tab SQL > tempel > Kirim.
-- ID memakai UUID (CHAR 36) agar sama dengan ID lama di Google Sheets
-- (detail instalasi menyimpan deviceId di dalam JSON, jadi ID harus dipertahankan).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  VARCHAR(32)  NOT NULL DEFAULT '',
  UNIQUE KEY uq_project_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  username    VARCHAR(100) NOT NULL,
  name        VARCHAR(150) NOT NULL,
  pass_hash   VARCHAR(255) NOT NULL,
  role        VARCHAR(20)  NOT NULL,           -- Admin | Manager | Engineer | Viewer
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  VARCHAR(32)  NOT NULL DEFAULT '',
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akses user ke project (menggantikan kolom projectIds yang berisi teks dipisah koma)
CREATE TABLE IF NOT EXISTS user_projects (
  user_id     CHAR(36) NOT NULL,
  project_id  CHAR(36) NOT NULL,
  PRIMARY KEY (user_id, project_id),
  KEY idx_up_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  project_id  CHAR(36)     NOT NULL,
  unit_no     VARCHAR(100) NOT NULL,
  type        VARCHAR(100) NOT NULL DEFAULT '',
  notes       TEXT,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_unit (project_id, unit_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  project_id  CHAR(36)     NOT NULL,
  name        VARCHAR(100) NOT NULL,
  need_sn     TINYINT(1)   NOT NULL DEFAULT 0,
  need_photo  TINYINT(1)   NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort        INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_device (project_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installations (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  project_id  CHAR(36)    NOT NULL,
  unit_id     CHAR(36)    NOT NULL,
  unit_no     VARCHAR(100) NOT NULL,
  date        CHAR(10)    NOT NULL,              -- yyyy-mm-dd
  remarks     TEXT,
  details     LONGTEXT,                          -- JSON: [{deviceId,name,status,sn,photo}]
  created_by  VARCHAR(150) NOT NULL DEFAULT '',
  created_at  VARCHAR(32)  NOT NULL DEFAULT '',
  updated_at  VARCHAR(32)  NOT NULL DEFAULT '',
  UNIQUE KEY uq_install_unit (project_id, unit_id),
  KEY idx_install_date (project_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS replacements (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  project_id  CHAR(36)    NOT NULL,
  unit_id     CHAR(36)    NOT NULL,
  unit_no     VARCHAR(100) NOT NULL,
  device_id   CHAR(36)    NOT NULL,
  device_name VARCHAR(100) NOT NULL,
  date        CHAR(10)    NOT NULL,
  old_sn      VARCHAR(200) NOT NULL DEFAULT '',
  new_sn      VARCHAR(200) NOT NULL DEFAULT '',
  reason      VARCHAR(30)  NOT NULL DEFAULT '',
  note        TEXT,
  photo       VARCHAR(100) NOT NULL DEFAULT '',  -- 'loc_<hex>' = file lokal, selain itu = ID file Google Drive lama
  created_by  VARCHAR(150) NOT NULL DEFAULT '',
  created_at  VARCHAR(32)  NOT NULL DEFAULT '',
  KEY idx_repl_project (project_id, date),
  KEY idx_repl_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guide (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,
  roles       VARCHAR(100) NOT NULL DEFAULT '',
  is_public   TINYINT(1)   NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort        INT          NOT NULL DEFAULT 0,
  updated_at  VARCHAR(32)  NOT NULL DEFAULT '',
  updated_by  VARCHAR(150) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesi login (menggantikan CacheService)
CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64)  NOT NULL PRIMARY KEY,
  user_id     CHAR(36)  NOT NULL,
  project_id  CHAR(36)  NOT NULL,
  expires_at  INT       NOT NULL,
  KEY idx_sess_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembatasan percobaan login gagal (anti tebak password)
CREATE TABLE IF NOT EXISTS login_fails (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  ip        VARCHAR(45)  NOT NULL,
  username  VARCHAR(100) NOT NULL,
  ts        INT          NOT NULL,
  KEY idx_lf (ip, ts),
  KEY idx_lf_user (username, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

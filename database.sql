-- RazpisMonitor — Kovinocrom d.o.o.
-- MySQL shema

CREATE DATABASE IF NOT EXISTS razpismonitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE razpismonitor;

-- ── Razpisi ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS razpisi (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  external_id     VARCHAR(255) UNIQUE,          -- ID iz portala
  vir             ENUM('e-JN','TED') NOT NULL,  -- portal
  naslov          TEXT NOT NULL,
  narocnik        VARCHAR(500),
  vrednost        VARCHAR(100),
  vrednost_eur    DECIMAL(15,2),                -- parsed vrednost za sortiranje
  rok_za_oddajo   DATE,
  datum_objave    DATE,
  cpv_kode        VARCHAR(500),
  status          ENUM('odprt','zaprt','potekel') DEFAULT 'odprt',
  link            TEXT,
  opis            TEXT,

  -- AI analiza
  ai_score        TINYINT UNSIGNED,             -- 0–100
  ai_prednosti    JSON,                         -- array of strings
  ai_slabosti     JSON,
  ai_priporocilo  TEXT,
  ai_analizirano  DATETIME,

  datum_zaznave   DATE DEFAULT (CURDATE()),
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status    (status),
  INDEX idx_vir       (vir),
  INDEX idx_rok       (rok_za_oddajo),
  INDEX idx_ai_score  (ai_score),
  INDEX idx_zaznave   (datum_zaznave)
) ENGINE=InnoDB;

-- ── Scraper log ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS scraper_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME,
  status      ENUM('running','done','error') DEFAULT 'running',
  ejn_found   INT DEFAULT 0,
  ted_found   INT DEFAULT 0,
  new_razpisi INT DEFAULT 0,
  error_msg   TEXT
) ENGINE=InnoDB;

-- ── Chat history ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_history (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  session_id  VARCHAR(100),
  razpis_id   INT,
  role        ENUM('user','assistant'),
  content     TEXT,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (razpis_id) REFERENCES razpisi(id) ON DELETE SET NULL
) ENGINE=InnoDB;

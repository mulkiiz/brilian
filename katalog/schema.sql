-- =====================================================
-- KATALOG DESA BRILIAN 2026 - Schema
-- DB: jurz2196_brilian_bot
-- =====================================================

CREATE TABLE IF NOT EXISTS `katalog_desa` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_desa` VARCHAR(20) NOT NULL,
  `section_key` VARCHAR(40) NOT NULL,
  `narasi_html` MEDIUMTEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_desa_section` (`kode_desa`, `section_key`),
  KEY `idx_kode` (`kode_desa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `katalog_foto` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_desa` VARCHAR(20) NOT NULL,
  `section_key` VARCHAR(40) NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `caption` VARCHAR(500) NULL,
  `urutan` TINYINT(2) NOT NULL DEFAULT 1,
  `filesize` INT(11) NOT NULL DEFAULT 0,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_desa_section` (`kode_desa`, `section_key`),
  KEY `idx_kode` (`kode_desa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `katalog_status` (
  `kode_desa` VARCHAR(20) NOT NULL,
  `sections_filled` TINYINT(2) NOT NULL DEFAULT 0,
  `total_photos` SMALLINT(4) NOT NULL DEFAULT 0,
  `last_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted` TINYINT(1) NOT NULL DEFAULT 0,
  `submitted_at` DATETIME NULL,
  PRIMARY KEY (`kode_desa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

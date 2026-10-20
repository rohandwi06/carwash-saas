-- ===================================================================
--  UPDATE DATABASE KUMULATIF (s/d 16 Oktober 2026)
--  Untuk situs mana pun yang databasenya belum tentu lengkap.
--
--  cPanel -> phpMyAdmin -> klik database situsnya di kiri
--  (mis. byrapiin_kembar99) -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Isinya gabungan tiga berkas yang sudah teruji:
--    A. update-migrasi-2026-10-09.sql  menu tanpa stok
--    B. update-migrasi-2026-10-11.sql  QRIS, detail pelanggan, kompensasi,
--                                      aturan harian, upah per bulan
--    C. update-migrasi-2026-10-16.sql  promo pelanggan setia, ejaan kendaraan
--                                      yang dipelajari AI, foto kendaraan
--
--  Tidak perlu tahu mana yang sudah pernah dijalankan: semua langkah
--  memakai IF NOT EXISTS, jadi bagian yang sudah ada dilewati. AMAN
--  DIJALANKAN ULANG. Tidak ada data yang dihapus. Sintaks khusus MariaDB.
--
--  Fitur sesudah 16 Oktober (login email, kunci langganan, AI mobil
--  sejenis) TIDAK butuh perubahan database.
--
--  HASIL: tabel terakhir yang tampil di phpMyAdmin berisi 24 baris
--  pemeriksaan. Semua kolom kedua harus "OK".
-- ===================================================================

-- ================= A. Menu tanpa stok (09-10) =================

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `track_stock` TINYINT(1) NOT NULL DEFAULT '1' AFTER `stock`;


-- ================= B. 10-11 Oktober =================

-- ---------- B1. Tambah pilihan QRIS ---------------------------------

ALTER TABLE `transactions`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;

ALTER TABLE `fnb_sales`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;


-- ---------- B2. Kolom detail pelanggan ------------------------------

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(60) NULL AFTER `plate`,
  ADD COLUMN IF NOT EXISTS `customer_phone` VARCHAR(20) NULL AFTER `customer_name`;

ALTER TABLE `transaction_drafts`
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(60) NULL AFTER `plate`,
  ADD COLUMN IF NOT EXISTS `customer_phone` VARCHAR(20) NULL AFTER `customer_name`;


-- ---------- B3. Tabel kompensasi karyawan ---------------------------
-- "Sangu" dari owner (uang makan, uang bensin). worker_id boleh kosong:
-- kalau pekerjanya dihapus, catatannya tetap ada dan tetap memotong laba.

CREATE TABLE IF NOT EXISTS `worker_compensations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `worker_id` BIGINT UNSIGNED NULL,
  `worker_name` VARCHAR(255) NOT NULL,
  `amount` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(160) NULL,
  `date` DATE NOT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  INDEX `worker_compensations_date_index` (`date`),
  CONSTRAINT `worker_compensations_worker_id_foreign`
    FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ---------- B4. Aturan otomatis harian ------------------------------
-- Potongan upah / kompensasi yang dicatat sendiri tiap hari untuk pekerja
-- yang mengerjakan cucian hari itu. all_workers = 1 berarti semua pekerja;
-- 0 berarti hanya pekerja pilihan di `daily_rule_worker` (boleh beberapa).

CREATE TABLE IF NOT EXISTS `daily_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(16) NOT NULL,
  `all_workers` TINYINT(1) NOT NULL DEFAULT '1',
  `amount` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(160) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT '1',
  `start_date` DATE NOT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_rule_worker` (
  `daily_rule_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`daily_rule_id`, `worker_id`),
  CONSTRAINT `daily_rule_worker_daily_rule_id_foreign`
    FOREIGN KEY (`daily_rule_id`) REFERENCES `daily_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_rule_worker_worker_id_foreign`
    FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Peralihan dari bentuk pertama tabel ini (11/10 siang: satu pekerja per
-- aturan, di kolom `worker_id`). Hanya berpengaruh di database yang sempat
-- menjalankan versi itu: aturannya dipindah ke bentuk baru, lalu kolom
-- lamanya dibuang. Di database lain, semua langkah di bawah tidak mengubah
-- apa pun.
ALTER TABLE `daily_rules`
  ADD COLUMN IF NOT EXISTS `all_workers` TINYINT(1) NOT NULL DEFAULT '1' AFTER `type`;

SET @lama = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rules' AND COLUMN_NAME = 'worker_id');

SET @langkah = IF(@lama > 0,
  'INSERT IGNORE INTO `daily_rule_worker` (`daily_rule_id`, `worker_id`) SELECT `id`, `worker_id` FROM `daily_rules` WHERE `worker_id` IS NOT NULL',
  'DO 0');
PREPARE jalankan FROM @langkah;
EXECUTE jalankan;
DEALLOCATE PREPARE jalankan;

SET @langkah = IF(@lama > 0,
  'UPDATE `daily_rules` SET `all_workers` = 0 WHERE `worker_id` IS NOT NULL',
  'DO 0');
PREPARE jalankan FROM @langkah;
EXECUTE jalankan;
DEALLOCATE PREPARE jalankan;

ALTER TABLE `daily_rules` DROP FOREIGN KEY IF EXISTS `daily_rules_worker_id_foreign`;
ALTER TABLE `daily_rules` DROP COLUMN IF EXISTS `worker_id`;

-- Catatan otomatis yang dihapus owner untuk satu hari: jangan dibuat lagi.
CREATE TABLE IF NOT EXISTS `daily_rule_skips` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rule_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  INDEX `daily_rule_skips_rule_id_date_index` (`rule_id`, `date`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Penanda "baris ini dibuat aturan nomor sekian". Indeks uniknya menjaga
-- satu aturan hanya mencatat sekali per pekerja per hari; baris yang dicatat
-- tangan (rule_id kosong) tidak terikat indeks ini.
ALTER TABLE `wage_adjustments`
  ADD COLUMN IF NOT EXISTS `rule_id` BIGINT UNSIGNED NULL AFTER `created_by`;

CREATE UNIQUE INDEX IF NOT EXISTS `wage_adjustments_rule_id_worker_id_date_unique`
  ON `wage_adjustments` (`rule_id`, `worker_id`, `date`);

ALTER TABLE `worker_compensations`
  ADD COLUMN IF NOT EXISTS `rule_id` BIGINT UNSIGNED NULL AFTER `created_by`;

CREATE UNIQUE INDEX IF NOT EXISTS `worker_compensations_rule_id_worker_id_date_unique`
  ON `worker_compensations` (`rule_id`, `worker_id`, `date`);


-- ---------- B5. Upah per bulan -------------------------------------
-- Cara upah ketiga: gaji tetap tiap pekerja, dibebankan rata per hari.
-- `monthly_wage_periods` = kapan saja cara itu berlaku (end_date kosong =
-- masih berlaku); `worker_salaries` = gaji seorang pekerja mulai sebuah
-- tanggal. Dua-duanya kosong sampai owner memilih "Upah per bulan".

CREATE TABLE IF NOT EXISTS `monthly_wage_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `worker_salaries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `amount` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `worker_salaries_worker_id_start_date_unique` (`worker_id`, `start_date`),
  CONSTRAINT `worker_salaries_worker_id_foreign`
    FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ================= C. 16 Oktober =================

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `promo_discount` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `total`,
  ADD COLUMN IF NOT EXISTS `promo_rule` VARCHAR(20) NULL AFTER `promo_discount`,
  ADD COLUMN IF NOT EXISTS `promo_wage` VARCHAR(8) NULL AFTER `promo_rule`;

CREATE TABLE IF NOT EXISTS `vehicle_aliases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `vehicle_id` BIGINT UNSIGNED NOT NULL,
  `alias` VARCHAR(60) NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT '0',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `vehicle_aliases_alias_unique` (`alias`),
  CONSTRAINT `vehicle_aliases_vehicle_id_foreign`
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `vehicles`
  ADD COLUMN IF NOT EXISTS `corrected_at` TIMESTAMP NULL AFTER `needs_review`;

CREATE TABLE IF NOT EXISTS `vehicle_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` BIGINT UNSIGNED NOT NULL,
  `path` VARCHAR(120) NOT NULL,
  `size` INT UNSIGNED NOT NULL DEFAULT '0',
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  INDEX `vehicle_photos_created_at_index` (`created_at`),
  CONSTRAINT `vehicle_photos_transaction_id_foreign`
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ================= D. Tandai migrasi sudah jalan =================

SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.nama, @batch
FROM (
    SELECT '2026_10_09_000001_add_track_stock_to_products_table' AS nama
    UNION ALL SELECT '2026_10_10_000001_add_qris_to_payment_methods'
    UNION ALL SELECT '2026_10_11_000001_add_customer_to_transactions'
    UNION ALL SELECT '2026_10_11_000002_create_worker_compensations_table'
    UNION ALL SELECT '2026_10_11_000003_create_daily_rules_tables'
    UNION ALL SELECT '2026_10_11_000004_add_workers_to_daily_rules'
    UNION ALL SELECT '2026_10_11_000005_create_monthly_wage_tables'
    UNION ALL SELECT '2026_10_16_000001_add_promo_to_transactions'
    UNION ALL SELECT '2026_10_16_000002_create_vehicle_aliases_table'
    UNION ALL SELECT '2026_10_16_000003_create_vehicle_photos_table'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);


-- ================= E. Pemeriksaan akhir =================
-- Satu tabel, 24 baris. Semua kolom kedua harus OK.

SELECT 'products.track_stock', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'track_stock'
UNION ALL
SELECT 'transactions.payment_method qris', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'payment_method'
    AND COLUMN_TYPE LIKE '%qris%'
UNION ALL
SELECT 'fnb_sales.payment_method qris', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fnb_sales' AND COLUMN_NAME = 'payment_method'
    AND COLUMN_TYPE LIKE '%qris%'
UNION ALL
SELECT 'transactions.customer_name', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'customer_name'
UNION ALL
SELECT 'transactions.customer_phone', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'customer_phone'
UNION ALL
SELECT 'transaction_drafts.customer_name', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_drafts' AND COLUMN_NAME = 'customer_name'
UNION ALL
SELECT 'transaction_drafts.customer_phone', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_drafts' AND COLUMN_NAME = 'customer_phone'
UNION ALL
SELECT 'tabel worker_compensations', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_compensations'
UNION ALL
SELECT 'worker_compensations.rule_id', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_compensations' AND COLUMN_NAME = 'rule_id'
UNION ALL
SELECT 'tabel daily_rules', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rules'
UNION ALL
SELECT 'daily_rules.all_workers', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rules' AND COLUMN_NAME = 'all_workers'
UNION ALL
SELECT 'daily_rules.worker_id (harus hilang)', IF(COUNT(*) = 0, 'OK', 'MASIH ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rules' AND COLUMN_NAME = 'worker_id'
UNION ALL
SELECT 'tabel daily_rule_worker', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rule_worker'
UNION ALL
SELECT 'tabel daily_rule_skips', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_rule_skips'
UNION ALL
SELECT 'wage_adjustments.rule_id', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wage_adjustments' AND COLUMN_NAME = 'rule_id'
UNION ALL
SELECT 'tabel monthly_wage_periods', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_wage_periods'
UNION ALL
SELECT 'tabel worker_salaries', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'worker_salaries'
UNION ALL
SELECT 'transactions.promo_discount', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'promo_discount'
UNION ALL
SELECT 'transactions.promo_rule', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'promo_rule'
UNION ALL
SELECT 'transactions.promo_wage', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'promo_wage'
UNION ALL
SELECT 'tabel vehicle_aliases', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_aliases'
UNION ALL
SELECT 'vehicles.corrected_at', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'corrected_at'
UNION ALL
SELECT 'tabel vehicle_photos', IF(COUNT(*) = 1, 'OK', 'BELUM ADA') FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_photos'
UNION ALL
SELECT 'catatan migrasi (10 baris)', IF(COUNT(*) = 10, 'OK', CONCAT('baru ', COUNT(*))) FROM `migrations`
  WHERE `migration` IN ('2026_10_09_000001_add_track_stock_to_products_table', '2026_10_10_000001_add_qris_to_payment_methods', '2026_10_11_000001_add_customer_to_transactions', '2026_10_11_000002_create_worker_compensations_table', '2026_10_11_000003_create_daily_rules_tables', '2026_10_11_000004_add_workers_to_daily_rules', '2026_10_11_000005_create_monthly_wage_tables', '2026_10_16_000001_add_promo_to_transactions', '2026_10_16_000002_create_vehicle_aliases_table', '2026_10_16_000003_create_vehicle_photos_table');

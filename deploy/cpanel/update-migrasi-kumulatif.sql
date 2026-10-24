-- ===================================================================
--  UPDATE DATABASE KUMULATIF (s/d 21 Oktober 2026)
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
--    D. update-migrasi-2026-10-21.sql  barang (stok sabun, semir, lap)
--
--  Tidak perlu tahu mana yang sudah pernah dijalankan: semua langkah
--  memakai IF NOT EXISTS, jadi bagian yang sudah ada dilewati. AMAN
--  DIJALANKAN ULANG. Tidak ada data yang dihapus. Sintaks khusus MariaDB.
--
--  Fitur 17-20 Oktober (login email, kunci langganan, AI mobil sejenis)
--  TIDAK butuh perubahan database.
--
--  HASIL: tabel terakhir yang tampil di phpMyAdmin berisi 24 baris
--  pemeriksaan. Kolom "hasil" semuanya "OK". Kalau ada langkah yang
--  gagal, phpMyAdmin berhenti di langkah itu dan menampilkan galatnya.
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
-- aturan, di kolom `worker_id`). Kolom itu ditambahkan dulu bila belum ada
-- (isinya kosong semua), supaya pemindahan di bawah tidak perlu memeriksa
-- bentuk tabel. Di database yang sempat menjalankan versi itu, aturannya
-- dipindah ke bentuk baru; di database lain tidak ada baris yang ikut.
-- Sesudahnya kolom itu dibuang.
ALTER TABLE `daily_rules`
  ADD COLUMN IF NOT EXISTS `all_workers` TINYINT(1) NOT NULL DEFAULT '1' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `worker_id` BIGINT UNSIGNED NULL;

INSERT IGNORE INTO `daily_rule_worker` (`daily_rule_id`, `worker_id`)
  SELECT `id`, `worker_id` FROM `daily_rules` WHERE `worker_id` IS NOT NULL;

UPDATE `daily_rules` SET `all_workers` = 0 WHERE `worker_id` IS NOT NULL;

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


-- ================= D. Barang (21-10) =================
-- Perlengkapan cuci (sabun, semir, lap), gerak stoknya, dan takaran
-- otomatis per cucian.

CREATE TABLE IF NOT EXISTS `supplies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `unit` VARCHAR(20) NOT NULL,
  `stock` DECIMAL(12,3) NOT NULL DEFAULT '0.000',
  `min_stock` DECIMAL(12,3) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT '0',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `supply_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(10) NOT NULL,
  `qty` DECIMAL(12,3) NOT NULL,
  `stock_after` DECIMAL(12,3) NOT NULL,
  `price` INT UNSIGNED NULL,
  `expense_id` BIGINT UNSIGNED NULL,
  `transaction_id` BIGINT UNSIGNED NULL,
  `note` VARCHAR(160) NULL,
  `date` DATE NOT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  INDEX `supply_movements_supply_id_date_index` (`supply_id`, `date`),
  CONSTRAINT `supply_movements_supply_id_foreign`
    FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supply_movements_expense_id_foreign`
    FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `supply_movements_transaction_id_foreign`
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_usage_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `supply_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(12,3) NOT NULL,
  `category_slug` VARCHAR(255) NULL,
  `service_slug` VARCHAR(255) NULL,
  `addon_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `supply_usage_rules_supply_id_foreign`
    FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supply_usage_rules_addon_id_foreign`
    FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ================= E. Tandai migrasi sudah jalan =================

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
    UNION ALL SELECT '2026_10_21_000001_create_supplies_tables'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);


-- ================= F. Pemeriksaan akhir =================
-- Satu tabel, 24 baris. Kolom "hasil" semuanya harus OK.
-- Tabel & kolom dibaca LANGSUNG, tidak lewat daftar tabel bawaan MySQL:
-- phpMyAdmin yang melihat nama database daftar itu di script menjalankan
-- SELURUH script di database tersebut, sehingga langkah pertama pun ditolak
-- (#1044 di demo, 21-10). Tabel atau kolom yang tidak ada langsung membuat
-- phpMyAdmin berhenti dengan galat "doesn't exist" / "Unknown column".
-- Pilihan 'qris' dan hilangnya daily_rules.worker_id tidak diperiksa lagi
-- di sini: langkahnya di atas selalu dijalankan, dan kalau gagal,
-- phpMyAdmin sudah berhenti di sana.

SELECT 'products.track_stock' AS bagian, COUNT(`track_stock`) AS isi, 'OK' AS hasil FROM `products`
UNION ALL SELECT 'transactions.customer_name', COUNT(`customer_name`), 'OK' FROM `transactions`
UNION ALL SELECT 'transactions.customer_phone', COUNT(`customer_phone`), 'OK' FROM `transactions`
UNION ALL SELECT 'transaction_drafts.customer_name', COUNT(`customer_name`), 'OK' FROM `transaction_drafts`
UNION ALL SELECT 'transaction_drafts.customer_phone', COUNT(`customer_phone`), 'OK' FROM `transaction_drafts`
UNION ALL SELECT 'tabel worker_compensations', COUNT(*), 'OK' FROM `worker_compensations`
UNION ALL SELECT 'worker_compensations.rule_id', COUNT(`rule_id`), 'OK' FROM `worker_compensations`
UNION ALL SELECT 'tabel daily_rules', COUNT(*), 'OK' FROM `daily_rules`
UNION ALL SELECT 'daily_rules.all_workers', COUNT(`all_workers`), 'OK' FROM `daily_rules`
UNION ALL SELECT 'tabel daily_rule_worker', COUNT(*), 'OK' FROM `daily_rule_worker`
UNION ALL SELECT 'tabel daily_rule_skips', COUNT(*), 'OK' FROM `daily_rule_skips`
UNION ALL SELECT 'wage_adjustments.rule_id', COUNT(`rule_id`), 'OK' FROM `wage_adjustments`
UNION ALL SELECT 'tabel monthly_wage_periods', COUNT(*), 'OK' FROM `monthly_wage_periods`
UNION ALL SELECT 'tabel worker_salaries', COUNT(*), 'OK' FROM `worker_salaries`
UNION ALL SELECT 'transactions.promo_discount', COUNT(`promo_discount`), 'OK' FROM `transactions`
UNION ALL SELECT 'transactions.promo_rule', COUNT(`promo_rule`), 'OK' FROM `transactions`
UNION ALL SELECT 'transactions.promo_wage', COUNT(`promo_wage`), 'OK' FROM `transactions`
UNION ALL SELECT 'tabel vehicle_aliases', COUNT(*), 'OK' FROM `vehicle_aliases`
UNION ALL SELECT 'vehicles.corrected_at', COUNT(`corrected_at`), 'OK' FROM `vehicles`
UNION ALL SELECT 'tabel vehicle_photos', COUNT(*), 'OK' FROM `vehicle_photos`
UNION ALL SELECT 'tabel supplies', COUNT(*), 'OK' FROM `supplies`
UNION ALL SELECT 'tabel supply_movements', COUNT(*), 'OK' FROM `supply_movements`
UNION ALL SELECT 'tabel supply_usage_rules', COUNT(*), 'OK' FROM `supply_usage_rules`
UNION ALL SELECT 'catatan migrasi (11 baris)', COUNT(*), IF(COUNT(*) = 11, 'OK', 'BELUM') FROM `migrations`
  WHERE `migration` IN ('2026_10_09_000001_add_track_stock_to_products_table', '2026_10_10_000001_add_qris_to_payment_methods', '2026_10_11_000001_add_customer_to_transactions', '2026_10_11_000002_create_worker_compensations_table', '2026_10_11_000003_create_daily_rules_tables', '2026_10_11_000004_add_workers_to_daily_rules', '2026_10_11_000005_create_monthly_wage_tables', '2026_10_16_000001_add_promo_to_transactions', '2026_10_16_000002_create_vehicle_aliases_table', '2026_10_16_000003_create_vehicle_photos_table', '2026_10_21_000001_create_supplies_tables');

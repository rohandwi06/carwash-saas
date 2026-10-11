-- ===================================================================
--  Migrasi 10-11 Oktober 2026: QRIS, detail pelanggan, kompensasi
--  karyawan, dan aturan otomatis harian
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Satu berkas untuk semua migrasi 10-11 Oktober. Yang sudah menjalankan
--  versi sebelumnya (QRIS saja, QRIS + pelanggan, atau sampai kompensasi)
--  tetap menjalankan berkas ini: bagian yang sudah ada tidak mengubah
--  apa-apa lagi.
--
--  1. Menambah pilihan 'qris' pada kolom cara bayar di `transactions`
--     dan `fnb_sales`.
--  2. Menambah kolom nama pelanggan dan nomor telepon (boleh kosong) di
--     `transactions` dan `transaction_drafts`.
--  3. Membuat tabel `worker_compensations` (kompensasi karyawan).
--  4. Membuat tabel `daily_rules` dan `daily_rule_skips` (aturan otomatis
--     harian), dan menambah kolom penanda `rule_id` di `wage_adjustments`
--     dan `worker_compensations`.
--
--  Tidak ada baris yang diubah atau dihapus. AMAN DIJALANKAN ULANG
--  (IF NOT EXISTS). Sintaks khusus MariaDB.
--
--  Jalankan SEBELUM mengunggah paket kodenya. Kode lama dengan SQL ini
--  tetap jalan seperti biasa. Sebaliknya, kode baru TANPA SQL ini akan
--  gagal menyimpan transaksi cuci dan gagal membuka Rekap & Pembukuan.
--
--  SQL diambil dari `php artisan migrate --pretend`.
-- ===================================================================


-- ---------- 1. Tambah pilihan QRIS ---------------------------------

ALTER TABLE `transactions`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;

ALTER TABLE `fnb_sales`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;


-- ---------- 2. Kolom detail pelanggan ------------------------------

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(60) NULL AFTER `plate`,
  ADD COLUMN IF NOT EXISTS `customer_phone` VARCHAR(20) NULL AFTER `customer_name`;

ALTER TABLE `transaction_drafts`
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(60) NULL AFTER `plate`,
  ADD COLUMN IF NOT EXISTS `customer_phone` VARCHAR(20) NULL AFTER `customer_name`;


-- ---------- 3. Tabel kompensasi karyawan ---------------------------
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


-- ---------- 4. Aturan otomatis harian ------------------------------
-- Potongan upah / kompensasi yang dicatat sendiri tiap hari untuk pekerja
-- yang mengerjakan cucian hari itu. worker_id kosong = semua pekerja.

CREATE TABLE IF NOT EXISTS `daily_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(16) NOT NULL,
  `worker_id` BIGINT UNSIGNED NULL,
  `amount` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(160) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT '1',
  `start_date` DATE NOT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `daily_rules_worker_id_foreign`
    FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

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


-- ---------- 5. Tandai migrasi sudah jalan --------------------------

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.nama, @batch
FROM (
    SELECT '2026_10_10_000001_add_qris_to_payment_methods' AS nama
    UNION ALL
    SELECT '2026_10_11_000001_add_customer_to_transactions'
    UNION ALL
    SELECT '2026_10_11_000002_create_worker_compensations_table'
    UNION ALL
    SELECT '2026_10_11_000003_create_daily_rules_tables'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 6. Pemeriksaan akhir -----------------------------------
-- Hasil yang benar, berurutan:
--   1-2. cara bayar menyebut enum('cash','tf','qris')
--   3-4. masing-masing 2 baris kolom customer_
--   5.   10 baris kolom tabel kompensasi (termasuk rule_id)
--   6.   10 baris kolom tabel aturan harian
--   7.   1 baris kolom rule_id di wage_adjustments
--   8.   4 baris migrasi

SHOW COLUMNS FROM `transactions` LIKE 'payment_method';
SHOW COLUMNS FROM `fnb_sales` LIKE 'payment_method';
SHOW COLUMNS FROM `transactions` LIKE 'customer_%';
SHOW COLUMNS FROM `transaction_drafts` LIKE 'customer_%';
SHOW COLUMNS FROM `worker_compensations`;
SHOW COLUMNS FROM `daily_rules`;
SHOW COLUMNS FROM `wage_adjustments` LIKE 'rule_id';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_1%';

-- ===================================================================
--  Migrasi "QRIS" + "detail pelanggan" + "kompensasi karyawan" (11/10/2026)
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Satu berkas untuk semua migrasi 10-11 Oktober. Yang sudah menjalankan
--  versi sebelumnya (QRIS saja, atau QRIS + pelanggan) tetap menjalankan
--  berkas ini: bagian yang sudah ada tidak mengubah apa-apa lagi.
--
--  1. Menambah pilihan 'qris' pada kolom cara bayar di `transactions`
--     dan `fnb_sales`.
--  2. Menambah kolom nama pelanggan dan nomor telepon (boleh kosong) di
--     `transactions` dan `transaction_drafts`.
--  3. Membuat tabel baru `worker_compensations` (kompensasi karyawan).
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


-- ---------- 4. Tandai migrasi sudah jalan --------------------------

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.nama, @batch
FROM (
    SELECT '2026_10_10_000001_add_qris_to_payment_methods' AS nama
    UNION ALL
    SELECT '2026_10_11_000001_add_customer_to_transactions'
    UNION ALL
    SELECT '2026_10_11_000002_create_worker_compensations_table'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 5. Pemeriksaan akhir -----------------------------------
-- Dua hasil pertama harus menyebut enum('cash','tf','qris'); dua hasil
-- berikutnya masing-masing 2 baris kolom customer_; lalu 9 baris kolom
-- tabel kompensasi; yang terakhir 3 baris migrasi.

SHOW COLUMNS FROM `transactions` LIKE 'payment_method';
SHOW COLUMNS FROM `fnb_sales` LIKE 'payment_method';
SHOW COLUMNS FROM `transactions` LIKE 'customer_%';
SHOW COLUMNS FROM `transaction_drafts` LIKE 'customer_%';
SHOW COLUMNS FROM `worker_compensations`;
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_1%';

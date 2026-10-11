-- ===================================================================
--  Migrasi "cara bayar QRIS" + "detail pelanggan" (11/10/2026)
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Berkas ini MENGGANTIKAN update-migrasi-2026-10-10.sql: isinya sama
--  ditambah kolom pelanggan. Yang sudah menjalankan berkas 10/10 tetap
--  boleh menjalankan ini; bagian QRIS-nya tidak mengubah apa-apa lagi.
--
--  1. Menambah pilihan 'qris' pada kolom cara bayar di `transactions`
--     dan `fnb_sales`.
--  2. Menambah kolom nama pelanggan dan nomor telepon (boleh kosong) di
--     `transactions` dan `transaction_drafts`.
--
--  Tidak ada baris yang diubah atau dihapus. AMAN DIJALANKAN ULANG
--  (IF NOT EXISTS). Sintaks khusus MariaDB.
--
--  Jalankan SEBELUM mengunggah paket kodenya. Kode lama dengan SQL ini
--  tetap jalan seperti biasa. Sebaliknya, kode baru TANPA SQL ini akan
--  gagal menyimpan SEMUA transaksi cuci.
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


-- ---------- 3. Tandai migrasi sudah jalan --------------------------

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.nama, @batch
FROM (
    SELECT '2026_10_10_000001_add_qris_to_payment_methods' AS nama
    UNION ALL
    SELECT '2026_10_11_000001_add_customer_to_transactions'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 4. Pemeriksaan akhir -----------------------------------
-- Dua hasil pertama harus menyebut enum('cash','tf','qris'); dua hasil
-- berikutnya masing-masing 2 baris kolom customer_; yang terakhir 2 baris
-- migrasi.

SHOW COLUMNS FROM `transactions` LIKE 'payment_method';
SHOW COLUMNS FROM `fnb_sales` LIKE 'payment_method';
SHOW COLUMNS FROM `transactions` LIKE 'customer_%';
SHOW COLUMNS FROM `transaction_drafts` LIKE 'customer_%';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_1%';

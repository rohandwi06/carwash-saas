-- ===================================================================
--  Migrasi "cara bayar QRIS" (10/10/2026)
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Hanya MENAMBAH pilihan 'qris' pada kolom cara bayar di `transactions`
--  dan `fnb_sales`. Tidak ada baris yang diubah atau dihapus; transaksi
--  cash dan tf yang sudah ada tetap apa adanya.
--  AMAN DIJALANKAN ULANG.
--
--  Jalankan SEBELUM mengunggah paket kodenya. Kode lama dengan SQL ini
--  tetap jalan seperti biasa. Sebaliknya, kode baru TANPA SQL ini akan
--  gagal menyimpan setiap transaksi yang dibayar QRIS.
--
--  SQL diambil dari `php artisan migrate --pretend`.
-- ===================================================================


-- ---------- 1. Tambah pilihan QRIS ---------------------------------

ALTER TABLE `transactions`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;

ALTER TABLE `fnb_sales`
  MODIFY `payment_method` ENUM('cash', 'tf', 'qris') NOT NULL;


-- ---------- 2. Tandai migrasi sudah jalan --------------------------

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_10_000001_add_qris_to_payment_methods', @batch
FROM DUAL
WHERE '2026_10_10_000001_add_qris_to_payment_methods'
  NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 3. Pemeriksaan akhir -----------------------------------
-- Dua baris pertama harus menyebut enum('cash','tf','qris'), dan harus
-- keluar 1 baris migrasi.

SHOW COLUMNS FROM `transactions` LIKE 'payment_method';
SHOW COLUMNS FROM `fnb_sales` LIKE 'payment_method';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_10_%';

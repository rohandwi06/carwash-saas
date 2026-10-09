-- ===================================================================
--  Migrasi "menu tanpa stok" (09/10/2026)
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Hanya MENAMBAH satu kolom di `products`. Tidak ada DROP, UPDATE, atau
--  DELETE; semua menu yang sudah ada tetap berstok seperti biasa.
--  AMAN DIJALANKAN ULANG (IF NOT EXISTS). Sintaks khusus MariaDB.
--
--  Jalankan SEBELUM atau BERSAMA paket kodenya. Kode baru tanpa kolom ini
--  tetap jalan seperti biasa (semua menu dianggap berstok), tetapi menyimpan
--  menu "Tanpa stok" akan gagal sampai SQL ini dijalankan.
--
--  SQL diambil dari `php artisan migrate --pretend`.
-- ===================================================================


-- ---------- 1. Penanda menu tanpa stok -----------------------------
-- 1 = berstok (bawaan, perilaku lama). 0 = tanpa stok, dibuat saat dipesan.

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `track_stock` TINYINT(1) NOT NULL DEFAULT '1' AFTER `stock`;


-- ---------- 2. Tandai migrasi sudah jalan --------------------------

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_09_000001_add_track_stock_to_products_table', @batch
FROM DUAL
WHERE '2026_10_09_000001_add_track_stock_to_products_table'
  NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 3. Pemeriksaan akhir -----------------------------------
-- Harus keluar 1 baris kolom track_stock, dan 1 baris migrasi.

SHOW COLUMNS FROM `products` LIKE 'track_stock';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_09_%';

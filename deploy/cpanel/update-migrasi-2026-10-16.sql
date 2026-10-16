-- ===================================================================
--  Migrasi 16 Oktober 2026: promo pelanggan setia
--  cPanel -> phpMyAdmin -> pilih database -> tab SQL -> tempel SEMUA -> Go
--
--  WAJIB BACKUP DULU: phpMyAdmin -> database yang sama -> tab Export -> Go.
--
--  Menambah tiga kolom di `transactions` untuk mencatat promo yang dipakai:
--    promo_discount  rupiah yang dipotong dari harga layanan (bawaan 0)
--    promo_rule      aturannya: 'gratis', 'persen:20', 'rupiah:5000'
--    promo_wage      'penuh' atau 'ikut' (upah untuk cucian promo)
--  Pengaturan promo sendiri disimpan di tabel `settings` oleh aplikasi.
--
--  Tidak ada baris yang diubah atau dihapus. AMAN DIJALANKAN ULANG
--  (IF NOT EXISTS). Sintaks khusus MariaDB.
--
--  Situs yang belum menjalankan update-migrasi-2026-10-11.sql harus
--  menjalankan berkas itu DULU, baru berkas ini.
--
--  Kode baru tanpa SQL ini: cucian biasa tetap tercatat, tapi promo tidak
--  bisa dinyalakan dan koreksi transaksi promo gagal. Jalankan SEBELUM
--  atau bersamaan dengan mengunggah paket kodenya.
--
--  SQL diambil dari `php artisan migrate --pretend`.
-- ===================================================================

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `promo_discount` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `total`,
  ADD COLUMN IF NOT EXISTS `promo_rule` VARCHAR(20) NULL AFTER `promo_discount`,
  ADD COLUMN IF NOT EXISTS `promo_wage` VARCHAR(8) NULL AFTER `promo_rule`;

-- Tandai migrasi sudah jalan
SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_16_000001_add_promo_to_transactions', @batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_16_000001_add_promo_to_transactions'
);

-- Pemeriksaan akhir. Hasil yang benar:
--   1. tiga baris kolom promo_
--   2. satu baris migrasi 2026_10_16_000001_add_promo_to_transactions
SHOW COLUMNS FROM `transactions` LIKE 'promo_%';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_16%';

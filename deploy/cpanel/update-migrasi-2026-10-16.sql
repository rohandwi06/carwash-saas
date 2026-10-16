-- ===================================================================
--  Migrasi 16 Oktober 2026: promo pelanggan setia & AI yang belajar
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
--  Lalu, untuk AI yang belajar dari koreksi:
--    tabel `vehicle_aliases`   ejaan kasir yang diingat ("raise" -> Toyota Raize)
--    kolom `vehicles.corrected_at`  kapan owner terakhir mengganti jenis mobil
--
--  Tidak ada baris yang diubah atau dihapus. AMAN DIJALANKAN ULANG
--  (IF NOT EXISTS). Sintaks khusus MariaDB.
--
--  Situs yang belum menjalankan update-migrasi-2026-10-11.sql harus
--  menjalankan berkas itu DULU, baru berkas ini.
--
--  Kode baru tanpa SQL ini GAGAL mencari kendaraan di layar kasir dan
--  menambah mobil di Daftar mobil.
--  Jalankan SEBELUM mengunggah paket kodenya.
--
--  SQL diambil dari `php artisan migrate --pretend`.
-- ===================================================================

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

-- Tandai migrasi sudah jalan
SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.nama, @batch
FROM (
    SELECT '2026_10_16_000001_add_promo_to_transactions' AS nama
    UNION ALL
    SELECT '2026_10_16_000002_create_vehicle_aliases_table'
) m
WHERE m.nama NOT IN (SELECT `migration` FROM `migrations`);

-- Pemeriksaan akhir. Hasil yang benar:
--   1. tiga baris kolom promo_
--   2. enam baris kolom tabel ejaan (id, vehicle_id, alias, hits, created_at, updated_at)
--   3. satu baris kolom corrected_at
--   4. dua baris migrasi 2026_10_16
SHOW COLUMNS FROM `transactions` LIKE 'promo_%';
SHOW COLUMNS FROM `vehicle_aliases`;
SHOW COLUMNS FROM `vehicles` LIKE 'corrected_at';
SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_10_16%';

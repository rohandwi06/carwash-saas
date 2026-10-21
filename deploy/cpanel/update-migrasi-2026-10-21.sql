-- ===================================================================
--  UPDATE DATABASE 21 Oktober 2026 — fitur Barang (stok sabun, semir, lap)
--
--  cPanel -> phpMyAdmin -> klik database situsnya di kiri -> tab SQL ->
--  tempel SEMUA -> Go.  WAJIB BACKUP DULU (tab Export -> Go).
--
--  Syarat: update-migrasi-kumulatif.sql s/d 16-10 sudah pernah dijalankan.
--  Kalau ragu, jalankan update-migrasi-kumulatif.sql saja — isinya sudah
--  memuat berkas ini. AMAN DIJALANKAN ULANG (IF NOT EXISTS).
--
--  Kode baru yang naik sebelum SQL ini dijalankan tetap bisa mencatat
--  cucian; hanya menu Barang yang menampilkan galat sampai tabelnya ada.
--
--  HASIL: tabel terakhir berisi 4 baris, kolom hasil semuanya "OK".
-- ===================================================================

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

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_21_000001_create_supplies_tables', (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations` m)
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_21_000001_create_supplies_tables');

-- Pemeriksaan TANPA information_schema: phpMyAdmin yang melihat kata itu
-- menjalankan SELURUH script di database information_schema (#1044 di demo,
-- 21-10). Tabel yang gagal dibuat langsung memunculkan galat "doesn't exist".
SELECT 'supplies' AS tabel, COUNT(*) AS isi, 'OK' AS hasil FROM `supplies`
UNION ALL SELECT 'supply_movements', COUNT(*), 'OK' FROM `supply_movements`
UNION ALL SELECT 'supply_usage_rules', COUNT(*), 'OK' FROM `supply_usage_rules`
UNION ALL SELECT 'catatan migrasi', COUNT(*), IF(COUNT(*) = 1, 'OK', 'BELUM') FROM `migrations`
  WHERE `migration` = '2026_10_21_000001_create_supplies_tables';

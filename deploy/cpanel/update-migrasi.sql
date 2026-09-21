-- ===================================================================
--  OTIN CARWASH — migrasi "katalog kendaraan" + "titip jual"
--  cPanel → phpMyAdmin → pilih database → tab SQL → tempel SEMUA → Go
--
--  WAJIB BACKUP DULU: phpMyAdmin → database yang sama → tab Export → Go.
--  Simpan file .sql-nya sebelum menjalankan apa pun di bawah.
--
--  Hanya MENAMBAH: satu kolom di `vehicles`, tiga tabel baru, dan kolom
--  baru di `products`, `fnb_sale_items`, `expenses`. Tidak ada DROP,
--  UPDATE, atau DELETE — satu baris pembukuan pun tidak tersentuh.
--
--  AMAN DIJALANKAN ULANG. Setiap perintah memakai IF NOT EXISTS, jadi
--  kalau terlanjur dijalankan dua kali (atau berhenti di tengah lalu
--  diulang dari awal), yang sudah ada dilewati, bukan dibuat dobel.
--  Sintaks ini khusus MariaDB — server hosting ini MariaDB.
--
--  Kalau hosting punya Terminal, ini semua tidak perlu. Cukup:
--      cd ~/otin-carwash && php artisan migrate --force
--
--  SQL di bawah diambil dari `php artisan migrate --pretend`, lalu
--  diuji di salinan skema produksi: hasil akhirnya identik dengan yang
--  dibuat `php artisan migrate`, termasuk nama indeks & foreign key.
-- ===================================================================


-- ---------- PERIKSA: versi yang sedang jalan -----------------------
-- Baris teratas hasilnya seharusnya:
--     2026_09_09_000001_add_edit_trail_to_transactions_table
-- Kalau yang teratas sudah 2026_09_18_..., migrasi ini PERNAH jalan.
-- Menjalankan ulang tetap aman, tapi tidak ada gunanya.

SELECT `migration`, `batch` FROM `migrations` ORDER BY `id` DESC LIMIT 3;


-- ---------- 1. Katalog kendaraan -----------------------------------
-- Tanda "kategori ini masih tebakan AI, belum dibenarkan owner".

ALTER TABLE `vehicles`
  ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT '0' AFTER `category`;


-- ---------- 2. Titip jual: tabel baru ------------------------------

CREATE TABLE IF NOT EXISTS `consignors` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(80)  NOT NULL,
  `phone`         VARCHAR(30)  NULL,
  `note`          VARCHAR(200) NULL,
  `share_mode`    VARCHAR(10)  NOT NULL DEFAULT 'setor',
  `share_percent` TINYINT UNSIGNED NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT '1',
  `created_at`    TIMESTAMP NULL,
  `updated_at`    TIMESTAMP NULL,
  UNIQUE KEY `consignors_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consignment_movements` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `consignor_id` BIGINT UNSIGNED NOT NULL,
  `product_id`   BIGINT UNSIGNED NOT NULL,
  `type`         VARCHAR(10)  NOT NULL,
  `qty`          INT UNSIGNED NOT NULL,
  `date`         DATE NOT NULL,
  `note`         VARCHAR(200) NULL,
  `created_by`   VARCHAR(60)  NULL,
  `created_at`   TIMESTAMP NULL,
  `updated_at`   TIMESTAMP NULL,
  KEY `consignment_movements_consignor_id_date_index` (`consignor_id`, `date`),
  KEY `consignment_movements_product_id_foreign` (`product_id`),
  CONSTRAINT `consignment_movements_consignor_id_foreign`
    FOREIGN KEY (`consignor_id`) REFERENCES `consignors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consignment_movements_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consignment_payouts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `consignor_id` BIGINT UNSIGNED NOT NULL,
  `amount`       INT UNSIGNED NOT NULL,
  `date`         DATE NOT NULL,
  `note`         VARCHAR(200) NULL,
  `created_by`   VARCHAR(60)  NULL,
  `expense_id`   BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NULL,
  `updated_at`   TIMESTAMP NULL,
  KEY `consignment_payouts_consignor_id_date_index` (`consignor_id`, `date`),
  KEY `consignment_payouts_expense_id_foreign` (`expense_id`),
  CONSTRAINT `consignment_payouts_consignor_id_foreign`
    FOREIGN KEY (`consignor_id`) REFERENCES `consignors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consignment_payouts_expense_id_foreign`
    FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ---------- 3. Titip jual: kolom di tabel lama ---------------------

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `consignor_id` BIGINT UNSIGNED NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `payout_price` INT UNSIGNED NULL AFTER `price`;

ALTER TABLE `products`
  ADD CONSTRAINT `products_consignor_id_foreign`
    FOREIGN KEY IF NOT EXISTS (`consignor_id`) REFERENCES `consignors` (`id`) ON DELETE SET NULL;

ALTER TABLE `fnb_sale_items`
  ADD COLUMN IF NOT EXISTS `consignor_id` BIGINT UNSIGNED NULL AFTER `product_name`,
  ADD COLUMN IF NOT EXISTS `consignor_share` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `subtotal`,
  ADD INDEX IF NOT EXISTS `fnb_sale_items_consignor_id_index` (`consignor_id`);

ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `is_consignment` TINYINT(1) NOT NULL DEFAULT '0' AFTER `amount`;


-- ---------- 4. Tandai migrasi sudah jalan --------------------------
-- Supaya `php artisan migrate` di kemudian hari tidak mencoba
-- menjalankannya lagi. Ketiganya satu batch, persis seperti Laravel.
-- Baris yang sudah tercatat dilewati.

SET @batch = (SELECT MAX(`batch`) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT `baru`.`m`, @batch FROM (
  SELECT '2026_09_18_000001_add_needs_review_to_vehicles_table' AS `m`
  UNION ALL SELECT '2026_09_18_000002_create_consignment_tables'
  UNION ALL SELECT '2026_09_18_000003_add_consignment_to_products_sales_expenses'
) AS `baru`
WHERE `baru`.`m` NOT IN (SELECT `migration` FROM `migrations`);


-- ---------- 5. Pemeriksaan akhir -----------------------------------
-- Harus keluar TEPAT 3 baris 2026_09_18_..., dengan batch yang sama.

SELECT `migration`, `batch` FROM `migrations` WHERE `migration` LIKE '2026_09_18_%';

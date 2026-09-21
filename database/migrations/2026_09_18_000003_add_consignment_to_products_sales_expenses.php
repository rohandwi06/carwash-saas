<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan titip jual ke tiga jalur yang sudah ada.
 *
 * products.consignor_id  — barang titipan tetap produk biasa, jadi layar jual
 *                          kasir tidak perlu tahu apa-apa. Null = milik cucian.
 *
 * fnb_sale_items.consignor_share — SNAPSHOT hak penitip untuk baris itu.
 *   Ini yang membuat laba tidak pernah bohong: harga setor boleh berubah besok,
 *   persentase boleh dinegosiasi ulang, penitipnya boleh dihapus — angka yang
 *   terlanjur terjadi tidak ikut bergerak. Persis alasan product_name &
 *   price sudah disalin ke baris penjualan sejak dulu.
 *
 * expenses.is_consignment — penanda "ini setoran ke penitip, bukan biaya
 *   operasional". Kas tetap berkurang (uangnya memang keluar), tapi laba
 *   tidak, karena uang itu tidak pernah diakui sebagai laba sejak awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('consignor_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();
            // Mode 'setor': hak penitip per satu barang terjual.
            $table->unsignedInteger('payout_price')->nullable()->after('price');
        });

        Schema::table('fnb_sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('consignor_id')->nullable()->after('product_name');
            $table->unsignedInteger('consignor_share')->default(0)->after('subtotal');

            $table->index('consignor_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_consignment')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('is_consignment');
        });

        Schema::table('fnb_sale_items', function (Blueprint $table) {
            $table->dropIndex(['consignor_id']);
            $table->dropColumn(['consignor_id', 'consignor_share']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['consignor_id']);
            $table->dropColumn(['consignor_id', 'payout_price']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F&B kini bisa dipesan langsung dari kasir cuci (satu resi dengan cucian).
 * Penjualannya tetap disimpan sebagai FnbSale supaya laporan F&B & stok
 * tidak berubah aturannya — hanya ditautkan ke transaksi cucinya.
 * NULL = penjualan F&B berdiri sendiri (lewat menu Jual Makanan/Minuman).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tip pada penjualan makanan & minuman.
 *
 * Transaksi cuci sudah punya kolom tip sejak awal; penjualan F&B belum, jadi
 * tip yang diberikan pelanggan saat jajan tidak punya tempat dicatat dan
 * terpaksa dicampur ke harga menu — membuat omzet F&B terlihat lebih besar
 * dari penjualan yang sebenarnya.
 *
 * Diperlakukan persis seperti tip cucian: ikut baris "Tip" di rekap dan ikut
 * laba bersih, tapi TIDAK ikut omzet F&B dan tidak ikut uang yang wajib
 * disetor kasir (CashBookService::cashAmount) — sama seperti tip cuci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->unsignedInteger('tip')->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->dropColumn('tip');
        });
    }
};

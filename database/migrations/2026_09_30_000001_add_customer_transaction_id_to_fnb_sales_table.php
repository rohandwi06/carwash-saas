<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jajanan yang dibeli pelanggan cuci lewat menu Jual Makanan/Minuman
 * (resi TERPISAH dari cuciannya) kini menunjuk mobil pembelinya.
 *
 * Permintaan owner (30/09): setiap baris laporan — tip, F&B cash, F&B TF —
 * harus bisa ditelusuri ke plat nomornya, supaya transaksinya jelas.
 *
 * Sengaja kolom BARU, bukan transaction_id yang sudah ada. transaction_id
 * berarti "satu resi dengan cucian": pesanannya ikut gugur dan stoknya ikut
 * kembali saat cucian itu dibatalkan (FnbSale::valid, TransactionService),
 * dan totalnya dijumlahkan ke baris cucian di Rekap. Jajanan yang dibayar
 * sendiri tidak boleh ikut semua itu — ia sudah lunas walau cuciannya batal.
 *
 * NULL = pembelinya bukan pelanggan cuci (atau penjualan lama sebelum kolom
 * ini ada, atau dikirim aplikasi Android yang belum mengenalnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->foreignId('customer_transaction_id')->nullable()->after('transaction_id')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->dropForeign(['customer_transaction_id']);
            $table->dropColumn('customer_transaction_id');
        });
    }
};

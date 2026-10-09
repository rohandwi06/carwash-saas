<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu TANPA STOK — dibuat saat dipesan (kopi tubruk, teh, mie rebus).
 *
 * Sebelum ini setiap menu wajib punya angka stok, dan menu berstok 0 tampil
 * "Habis" serta tidak bisa dijual. Untuk minuman seduh itu berarti owner harus
 * mengisi stok karangan (999) dan mengisinya ulang tiap kali "habis".
 *
 * track_stock = true  (bawaan) perilaku lama: stok dicek & dipotong tiap laku.
 * track_stock = false stok tidak dicek, tidak dipotong, tidak dikembalikan
 *                     saat penjualan dibatalkan; kolom stock dibiarkan 0.
 *
 * Bawaannya true, jadi semua menu yang sudah ada tidak berubah. Barang
 * titipan selalu berstok (ProductController): hitungan masuk - laku - retur
 * = sisa ke penitipnya bergantung pada angka itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('track_stock');
        });
    }
};

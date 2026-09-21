<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft cucian kehilangan dua hal yang sudah diketik kasir: makanan/minuman
 * yang dipesan bareng cucian, dan tip. Keduanya ikut dikirim saat transaksi
 * disimpan, tapi tidak punya kolom di draft — jadi diam-diam hilang begitu
 * kasir menekan "Simpan Draft", dan harus diketik ulang saat pelanggan bayar.
 *
 * Sama seperti kolom draft yang lain, yang disimpan hanya PESANANNYA
 * (product_id + qty), bukan harganya: harga & stok tetap dikunci server saat
 * draft benar-benar jadi transaksi, jadi draft lama tidak pernah memakai
 * harga kedaluwarsa dan stok belum terpotong selama masih draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_drafts', function (Blueprint $table) {
            $table->json('fnb_items')->nullable()->after('addon_ids');
            $table->unsignedInteger('tip')->default(0)->after('fnb_items');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_drafts', function (Blueprint $table) {
            $table->dropColumn(['fnb_items', 'tip']);
        });
    }
};

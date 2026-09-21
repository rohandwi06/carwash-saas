<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai kendaraan yang masuk katalog dari TEBAKAN AI, bukan dari keputusan
 * owner.
 *
 * Sebelum ini, sekali kasir menekan "Simpan ke Database & Pakai", tebakan
 * Gemini langsung jadi aturan harga permanen — dan tidak ada satu pun layar
 * untuk membetulkannya lagi. Corvette yang ditebak "mobil kecil" akan ditagih
 * 35rb selamanya tanpa owner pernah tahu.
 *
 * Kolom ini yang membuat tebakan itu punya masa percobaan: tetap dipakai saat
 * itu juga supaya kasir tidak tertahan, tapi muncul di Pengaturan sebagai
 * "perlu dicek" sampai owner membenarkan kategorinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};

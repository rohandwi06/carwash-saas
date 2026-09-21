<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori pengeluaran dilepas: owner cukup menulis keterangannya.
 *
 * Lima kategori tetap (bahan/operasional/gaji/alat/lain) menuntut kasir
 * memilih sebelum bisa menyimpan, padahal keterangannya sudah menjelaskan
 * hal yang sama dengan lebih tepat. Rincian per kategori di ringkasan pun
 * hampir selalu jatuh ke satu kelompok saja.
 *
 * Kolomnya benar-benar dibuang, bukan sekadar tidak dipakai: saat migrasi ini
 * ditulis tabel expenses masih kosong (0 baris), jadi tidak ada riwayat
 * pengelompokan yang hilang. Kolom mati yang ditinggalkan hanya akan
 * menyesatkan orang yang membaca skema ini nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category')->default('lain')->after('description');
        });
    }
};

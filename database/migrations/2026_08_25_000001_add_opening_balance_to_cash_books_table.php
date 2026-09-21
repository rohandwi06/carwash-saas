<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo awal (kas kecil) untuk sebuah buku kas — diketik manual tiap kali
 * buku baru dibuka (biasanya pagi hari), mewakili uang tunai yang sudah ada
 * di tangan kasir SEBELUM transaksi hari itu, dipakai membeli keperluan
 * kecil (sabun, dll).
 *
 * TIDAK menyentuh rumus Omzet/Laba Bersih (BookkeepingService::dailyRecap):
 * "sisa saldo" = opening_balance - total pengeluaran itu murni angka
 * tambahan untuk kasir mengecek uang di tangannya, bukan pengganti
 * perhitungan pengeluaran yang sudah ada. Total Pengeluaran tetap dihitung
 * dari jumlah baris expenses seperti sebelumnya — itulah yang sudah
 * memotong omzet, dan angka itu TIDAK berubah oleh kolom ini.
 *
 * Nullable & default null (bukan 0): buku lama sebelum fitur ini ada tidak
 * boleh terbaca seolah saldo awalnya nol rupiah — itu klaim yang salah,
 * bedanya harus jelas "belum pernah diisi".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_books', function (Blueprint $table) {
            $table->unsignedInteger('opening_balance')->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('cash_books', function (Blueprint $table) {
            $table->dropColumn('opening_balance');
        });
    }
};

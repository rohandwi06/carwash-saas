<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cara bayar ketiga: QRIS (permintaan owner 10/10).
 *
 * Sebelumnya hanya 'cash' dan 'tf'. QRIS dicatat sendiri karena uangnya
 * masuk lewat jalur lain dari transfer biasa, dan owner ingin mencocokkan
 * keduanya terpisah. Seperti 'tf', QRIS TIDAK masuk laci: tidak ikut
 * setoran cash buku kas.
 *
 * Hanya memperluas pilihan kolomnya; tidak ada baris yang diubah.
 */
return new class extends Migration
{
    private const TABEL = ['transactions', 'fnb_sales'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->enum('payment_method', ['cash', 'tf', 'qris'])->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            // Mempersempit enum saat masih ada baris 'qris' akan gagal (atau
            // mengosongkan nilainya), jadi dikembalikan ke 'tf' lebih dulu:
            // sama-sama bukan uang laci.
            DB::table($tabel)->where('payment_method', 'qris')->update(['payment_method' => 'tf']);

            Schema::table($tabel, function (Blueprint $table) {
                $table->enum('payment_method', ['cash', 'tf'])->change();
            });
        }
    }
};

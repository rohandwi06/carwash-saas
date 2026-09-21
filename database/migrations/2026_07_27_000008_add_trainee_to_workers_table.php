<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda karyawan training.
 *
 * Upahnya TIDAK ikut dibagi rata: training menerima nominal tetap per cucian
 * (diatur owner di Pengaturan -> Karyawan), sisanya baru dibagi rata ke
 * pekerja senior. Aturan hitungnya ada di App\Services\WageService.
 *
 * Nilainya disimpan di pekerjanya, bukan di transaksi: status training
 * berlaku selama orangnya masih training, bukan per cucian. Konsekuensinya
 * disengaja — upah transaksi LAMA tidak berubah saat statusnya kelak dicabut,
 * karena wage_share sudah tersimpan di tabel pivot saat transaksi dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->boolean('is_trainee')->default(false)->after('is_present');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('is_trainee');
        });
    }
};

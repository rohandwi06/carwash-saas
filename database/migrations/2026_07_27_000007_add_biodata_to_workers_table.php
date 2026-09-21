<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biodata pekerja: NIK, tempat & tanggal lahir, kontak, alamat.
 *
 * Semua nullable — pekerja yang sudah terdaftar tidak boleh mendadak tidak
 * bisa disimpan hanya karena biodatanya belum lengkap. Owner mengisinya
 * belakangan, satu per satu.
 *
 * UMUR SENGAJA TIDAK DISIMPAN. Umur berubah sendiri tiap tahun; kalau
 * ditulis ke kolom, angkanya membusuk diam-diam dan baru ketahuan salah saat
 * dipakai untuk hal penting. Cukup tanggal lahir yang disimpan, umur dihitung
 * saat dibaca (lihat accessor 'age' di App\Models\Worker).
 *
 * NIK diberi indeks unik supaya satu orang tidak terdaftar dua kali dengan
 * ejaan nama berbeda. Nilai NULL boleh berulang, jadi pekerja yang NIK-nya
 * belum diisi tetap bisa disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('nik', 32)->nullable()->unique()->after('name');
            $table->date('birth_date')->nullable()->after('nik');
            $table->string('birth_place', 60)->nullable()->after('birth_date');
            $table->string('phone', 24)->nullable()->after('birth_place');
            $table->string('address', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropColumn(['nik', 'birth_date', 'birth_place', 'phone', 'address']);
        });
    }
};

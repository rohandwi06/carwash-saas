<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak akuntabilitas: setiap input kasir menyimpan NAMA akun yang login
 * saat itu, supaya di rekap harian ketahuan siapa yang mencatat apa.
 * Disimpan sebagai teks (bukan FK) agar riwayat tetap utuh walau akunnya
 * kelak dihapus owner.
 */
return new class extends Migration
{
    private const TABLES = ['transactions', 'fnb_sales', 'expenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('created_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('created_by');
            });
        }
    }
};

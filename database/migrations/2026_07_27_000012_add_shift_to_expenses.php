<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengeluaran ikut dibubuhi shift.
 *
 * Menyusul transactions & fnb_sales. Tanpa ini, Rekap Hari Ini yang disaring
 * per shift tidak bisa menghitung laba dengan jujur: pengeluaran hanya punya
 * tanggal, jadi pilihannya cuma mengabaikannya (laba per shift kelebihan) atau
 * memotongkan pengeluaran sehari penuh ke tiap shift (kekurangan, dan kalau
 * kedua shift dijumlahkan pengeluarannya terhitung dua kali).
 *
 * Saat migrasi ini ditulis tabel expenses masih kosong, jadi tidak ada baris
 * lama yang perlu ditebak shiftnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('date')
                ->constrained()->nullOnDelete();
            $table->string('shift_name')->nullable()->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn('shift_name');
        });
    }
};

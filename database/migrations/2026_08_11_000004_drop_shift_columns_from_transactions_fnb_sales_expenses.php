<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shift_id/shift_name pada transactions, fnb_sales, expenses tidak lagi
 * dibaca kode mana pun setelah laporan pindah ke buku kas (book_id) — satu-
 * satunya tugasnya dulu adalah menandai baris untuk rekap per shift, dan itu
 * sudah digantikan sepenuhnya.
 *
 * Tabel 'shifts' SENDIRI, App\Models\Shift, dan ShiftGuard TIDAK disentuh:
 * jam operasional yang mengunci aplikasi kasir di luar jam tetap jalan
 * seperti sebelumnya. Yang dicabut hanya jejak shift di baris transaksi.
 */
return new class extends Migration
{
    private const TABEL = ['transactions', 'fnb_sales', 'expenses'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shift_id');
                $table->dropColumn('shift_name');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('date')->constrained()->nullOnDelete();
                $table->string('shift_name')->nullable()->after('shift_id');
            });
        }
    }
};

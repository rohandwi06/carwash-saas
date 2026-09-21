<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai tiap transaksi/F&B/pengeluaran dengan buku kas yang sedang
 * berjalan saat dicatat — pengganti shift_id/shift_name (dilepas di
 * 2026_08_11_000004 setelah datanya dipindah ke cash_books).
 */
return new class extends Migration
{
    private const TABEL = ['transactions', 'fnb_sales', 'expenses'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->foreignId('book_id')->nullable()->after('shift_name')
                    ->constrained('cash_books')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropConstrainedForeignId('book_id');
            });
        }
    }
};

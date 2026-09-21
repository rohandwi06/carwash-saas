<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggolongan otomatis transaksi ke shift yang sedang berjalan.
 *
 * Shift ditentukan SAAT transaksi dibuat lalu disimpan, bukan dihitung ulang
 * saat laporan dibaca. Alasannya: owner boleh mengubah jam shift kapan saja,
 * dan kalau laporan menghitung ulang dari jam-jam terbaru, rekap bulan lalu
 * ikut bergeser sendiri — angka yang sudah dipakai bagi hasil tidak boleh
 * berubah surut.
 *
 * shift_name disalin karena alasan yang sama seperti worker_name di deposit:
 * shift yang kelak dihapus atau diganti nama tidak boleh membuat riwayat
 * kehilangan keterangannya.
 *
 * F&B ikut dibubuhi supaya omzet per shift utuh. Tanpa itu, penjualan
 * makanan/minuman yang berdiri sendiri tidak masuk shift mana pun dan
 * jumlah per shift tidak akan pernah cocok dengan total harian.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'fnb_sales'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('date')
                    ->constrained()->nullOnDelete();
                $table->string('shift_name')->nullable()->after('shift_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['transactions', 'fnb_sales'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shift_id');
                $table->dropColumn('shift_name');
            });
        }
    }
};

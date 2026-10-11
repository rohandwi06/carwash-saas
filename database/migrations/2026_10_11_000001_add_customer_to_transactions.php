<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detail pelanggan di transaksi cuci: nama dan nomor telepon (permintaan
 * owner 11/10).
 *
 * Dicatat PER TRANSAKSI, bukan daftar pelanggan: tidak ada tabel pelanggan,
 * tidak ada isi otomatis dari plat. Keduanya opsional — kasir boleh langsung
 * menyimpan tanpa mengisi, sama seperti plat.
 *
 * Draft ikut punya kolomnya supaya yang sudah diketik kasir tidak hilang saat
 * cucian disimpan sebagai draft dulu.
 */
return new class extends Migration
{
    private const TABEL = ['transactions', 'transaction_drafts'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->string('customer_name', 60)->nullable()->after('plate');
                $table->string('customer_phone', 20)->nullable()->after('customer_name');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropColumn(['customer_name', 'customer_phone']);
            });
        }
    }
};

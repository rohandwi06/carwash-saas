<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo pelanggan setia (permintaan owner 16/10): setiap kedatangan ke-N
 * sebuah plat/nomor telepon berhak cuci gratis atau potongan.
 *
 * Yang dicatat di transaksinya adalah promo yang SUDAH DIPAKAI, sebagai
 * salinan aturan saat itu — pengaturan promo boleh berubah besok, transaksi
 * lama tidak boleh ikut berubah:
 *
 *   promo_discount  rupiah yang dipotong dari harga layanan. `total` sudah
 *                   bersih dari potongan ini (uang yang benar-benar dibayar).
 *   promo_rule      aturannya: 'gratis', 'persen:20', 'rupiah:5000'. Dipakai
 *                   menghitung ulang potongan bila transaksinya dikoreksi.
 *   promo_wage      'penuh' (upah dihitung dari harga sebelum potongan) atau
 *                   'ikut' (upah ikut turun sebesar potongannya).
 *
 * Pengaturan promo sendiri tinggal di tabel settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('promo_discount')->default(0)->after('total');
            $table->string('promo_rule', 20)->nullable()->after('promo_discount');
            $table->string('promo_wage', 8)->nullable()->after('promo_rule');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['promo_discount', 'promo_rule', 'promo_wage']);
        });
    }
};

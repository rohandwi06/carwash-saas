<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kembaran 2026_09_03_000001 untuk sisi F&B: penjualan F&B sudah bisa
 * menerima tip sejak 2026_08_20_000004, tapi draft-nya belum — tip yang
 * sudah diketik hilang saat pesanan disimpan sebagai draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_drafts', function (Blueprint $table) {
            $table->unsignedInteger('tip')->default(0)->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_drafts', function (Blueprint $table) {
            $table->dropColumn('tip');
        });
    }
};

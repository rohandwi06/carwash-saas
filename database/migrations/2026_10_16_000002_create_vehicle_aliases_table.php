<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI yang belajar dari koreksi (permintaan owner 16/10). Dua ingatan:
 *
 *   vehicle_aliases       ejaan yang diketik kasir untuk sebuah mobil di
 *                         katalog ("raise" -> Toyota Raize). Setelah dipakai
 *                         dua kali, pencarian langsung menemukannya tanpa
 *                         bertanya ke AI. Disimpan dalam bentuk rapat: huruf
 *                         kecil, tanpa spasi dan tanda baca.
 *   vehicles.corrected_at kapan owner terakhir MENGGANTI jenis mobil ini.
 *                         Mobil-mobil yang dikoreksi dikirim ke AI sebagai
 *                         contoh penggolongan cucian ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 60)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('corrected_at')->nullable()->after('needs_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_aliases');
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('corrected_at');
        });
    }
};

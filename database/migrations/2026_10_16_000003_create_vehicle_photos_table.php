<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto kondisi kendaraan (permintaan owner 16/10): dipotret kasir saat
 * kendaraan masuk, menempel pada transaksinya, untuk menjawab komplain lecet
 * atau baret. Opsional, dan dihapus otomatis setelah 60 hari supaya ruang
 * hosting tidak penuh (FotoKendaraanService).
 *
 * Berkasnya di storage/app/private/photos — di luar folder publik, hanya bisa
 * dibuka lewat API yang wajib login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('path', 120);
            $table->unsignedInteger('size')->default(0);
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photos');
    }
};

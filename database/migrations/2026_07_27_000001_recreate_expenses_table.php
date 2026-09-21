<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengeluaran dihidupkan lagi: belanja sabun, listrik, servis alat, dll.
 * Nilainya ikut mengurangi laba bersih di rekap harian & pembukuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->string('category')->default('lain');   // bahan|operasional|gaji|alat|lain
            $table->unsignedInteger('amount');
            $table->date('date')->index();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

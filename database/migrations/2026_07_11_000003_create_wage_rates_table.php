<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_rates', function (Blueprint $table) {
            $table->id();
            $table->string('category')->unique();
            $table->unsignedInteger('amount'); // upah per kendaraan (Rp)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_rates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fitur pengeluaran dihapus dari sistem — tabelnya ikut dibuang. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('expenses');
    }

    public function down(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->unsignedInteger('amount');
            $table->date('date');
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }
};

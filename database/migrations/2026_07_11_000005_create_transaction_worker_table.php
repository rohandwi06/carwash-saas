<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_worker', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('wage_share'); // bagian upah pekerja ini (Rp)
            $table->unique(['transaction_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_worker');
    }
};

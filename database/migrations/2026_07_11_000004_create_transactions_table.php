<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('queue_no');
            $table->string('vehicle_name');
            $table->string('category');                    // motor|kecil|sedang|besar
            $table->string('service')->default('reguler'); // reguler|dalam|hidro
            $table->enum('payment_method', ['cash', 'tf']);
            $table->string('plate')->nullable();
            $table->unsignedInteger('tip')->default(0);
            $table->boolean('is_bonus')->default(false);
            $table->unsignedInteger('total');              // dihitung server, bukan dari client
            $table->date('date')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

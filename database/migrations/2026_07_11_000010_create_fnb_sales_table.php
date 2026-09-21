<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_sales', function (Blueprint $table) {
            $table->id();
            $table->enum('payment_method', ['cash', 'tf']);
            $table->unsignedInteger('total'); // dihitung server dari item
            $table->date('date')->index();
            $table->timestamps();
        });

        Schema::create('fnb_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fnb_sale_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');       // snapshot nama saat terjual
            $table->unsignedInteger('price');     // snapshot harga saat terjual
            $table->unsignedInteger('qty');
            $table->unsignedInteger('subtotal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_sale_items');
        Schema::dropIfExists('fnb_sales');
    }
};

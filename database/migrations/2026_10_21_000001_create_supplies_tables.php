<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang — perlengkapan cuci yang habis dipakai (sabun, semir, lap, parfum).
 *
 * Berbeda dari products (F&B): barang ini tidak dijual, jadi tidak punya
 * harga jual dan tidak masuk omzet. Yang dilacak hanya sisanya.
 *
 * Stok berupa desimal karena sebagian barang berupa cairan ("debit"): sabun
 * 3,5 liter. Barang hitungan (lap, botol) cukup memakai angka bulat.
 *
 * supply_movements mencatat setiap gerak stok:
 *   beli  : stok masuk (bisa sekalian jadi pengeluaran -> expense_id)
 *   pakai : dicatat manual oleh kasir/owner
 *   hitung: opname — owner/kasir mengetik sisa sebenarnya, qty = selisihnya
 *   cuci  : dipotong otomatis dari takaran per cucian (transaction_id)
 *
 * supply_usage_rules = takaran otomatis. Satu aturan cocok dengan sebuah
 * cucian bila jenis kendaraan & layanannya cocok (null = semua), atau bila
 * cucian itu memakai add-on yang disebut (addon_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('unit', 20);
            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('supply_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->decimal('qty', 12, 3);
            $table->decimal('stock_after', 12, 3);
            $table->unsignedInteger('price')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 160)->nullable();
            $table->date('date');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['supply_id', 'date']);
        });

        Schema::create('supply_usage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 12, 3);
            $table->string('category_slug')->nullable();
            $table->string('service_slug')->nullable();
            $table->foreignId('addon_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_usage_rules');
        Schema::dropIfExists('supply_movements');
        Schema::dropIfExists('supplies');
    }
};

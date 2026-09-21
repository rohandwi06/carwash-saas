<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titip jual: orang lain menitipkan barang, cucian yang menjualkan.
 *
 * Tiga tabel, tiga pertanyaan yang harus bisa dijawab kapan saja:
 *   consignors            — siapa penitipnya & bagi hasilnya bagaimana
 *   consignment_movements — barang masuk & barang diambil kembali (retur)
 *   consignment_payouts   — kapan uangnya disetorkan
 *
 * Yang TERJUAL sengaja tidak punya tabel sendiri: itu sudah ada di
 * fnb_sale_items, lengkap dengan harga saat itu. Menyalinnya ke tabel kedua
 * berarti dua angka yang bisa berbeda — dan yang satu pasti salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('note', 200)->nullable();

            // 'setor'  = penitip minta angka tetap per barang (harga setornya
            //            ada di products.payout_price), sisanya milik cucian.
            // 'persen' = cucian mengambil share_percent dari harga jual.
            $table->string('share_mode', 10)->default('setor');
            $table->unsignedTinyInteger('share_percent')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('consignment_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);              // masuk | retur
            $table->unsignedInteger('qty');
            $table->date('date');
            $table->string('note', 200)->nullable();
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index(['consignor_id', 'date']);
        });

        Schema::create('consignment_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('date');
            $table->string('note', 200)->nullable();
            $table->string('created_by', 60)->nullable();

            // Setoran = uang FISIK keluar dari laci, jadi ia juga lahir sebagai
            // baris pengeluaran supaya saldo buku kas tetap ketemu. Barisnya
            // ditandai is_consignment agar TIDAK ikut mengurangi laba untuk
            // kedua kalinya — hak penitip sudah dipotong sejak barang laku.
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['consignor_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_payouts');
        Schema::dropIfExists('consignment_movements');
        Schema::dropIfExists('consignors');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft cucian: kasir mencatat kendaraan yang masuk lebih dulu, transaksinya
 * disimpan belakangan saat pembayaran. Disimpan di server (bukan browser)
 * supaya draft tetap ada walau tablet ditutup dan bisa dilanjut kasir lain.
 *
 * Isinya sengaja LONGGAR (harga tidak disimpan): harga & upah baru dikunci
 * saat draft benar-benar jadi transaksi, jadi tetap server yang menghitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_name');
            $table->string('category');
            $table->string('service')->default('reguler');
            $table->string('plate')->nullable();
            $table->string('note', 160)->nullable();
            $table->json('worker_ids')->nullable();
            $table->json('addon_ids')->nullable();
            $table->date('date')->index();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_drafts');
    }
};

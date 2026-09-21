<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft makanan & minuman: pesanan dicatat dulu (mis. pelanggan masih menunggu
 * cuciannya, atau bayarnya nanti sekalian), penjualannya disimpan belakangan.
 *
 * Cerminan transaction_drafts untuk cucian, dengan alasan yang sama:
 *  - Disimpan di SERVER, bukan browser, supaya draft tidak hilang saat tablet
 *    ditutup dan bisa dilanjutkan kasir lain di shift berikutnya.
 *  - Harga sengaja TIDAK disimpan — cuma product_id & qty. Harga baru dikunci
 *    saat draft benar-benar jadi penjualan, jadi tetap server yang menghitung
 *    dan draft lama tidak memakai harga yang sudah kedaluwarsa.
 *  - Stok TIDAK dipotong di sini. Draft bukan penjualan; stok baru berkurang
 *    di FnbService::create(). Konsekuensinya draft bisa saja gagal disimpan
 *    nanti kalau stoknya keburu habis terjual — itu memang perilaku yang
 *    benar, karena barangnya nyata-nyata sudah tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();   // penanda pesanan: nama/plat/meja
            $table->json('items');                 // [{product_id, qty}, ...]
            $table->string('note', 160)->nullable();
            $table->date('date')->index();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_drafts');
    }
};

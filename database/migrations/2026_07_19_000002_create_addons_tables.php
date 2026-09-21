<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan tambahan (add-on) yang bisa dipilih bareng cuci:
 * semir ban, poles, anti jamur kaca, dst.
 *
 * Pivot menyimpan SALINAN nama & harga saat transaksi dibuat, supaya
 * riwayat lama tidak ikut berubah kalau owner menaikkan harga add-on
 * atau menghapusnya dari daftar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('addon_transaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');            // salinan saat transaksi dibuat
            $table->unsignedInteger('price');  // salinan saat transaksi dibuat
            $table->unique(['transaction_id', 'addon_id']);
        });

        // Isi awal: add-on yang umum ditawarkan cuci mobil/motor.
        // Owner bebas mengubah harga, menonaktifkan, atau menghapusnya.
        $awal = [
            ['Semir Ban',          10000],
            ['Poles Body / Wax',   50000],
            ['Anti Jamur Kaca',    35000],
            ['Cuci Mesin',         40000],
            ['Vacuum Interior',    15000],
            ['Pewangi Mobil',      15000],
            ['Poles Lampu',        30000],
            ['Kit Dashboard',      10000],
        ];

        foreach ($awal as $i => [$nama, $harga]) {
            DB::table('addons')->insert([
                'name' => $nama, 'price' => $harga, 'is_active' => true,
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_transaction');
        Schema::dropIfExists('addons');
    }
};

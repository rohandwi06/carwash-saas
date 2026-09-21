<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item F&B sebelumnya hanya menyimpan snapshot nama produk. Cukup untuk
 * mencetak resi, tapi tidak cukup untuk MENGEMBALIKAN stok saat transaksi
 * dibatalkan — nama bisa berubah kalau menu di-rename, dan dua produk boleh
 * bernama mirip. Simpan product_id-nya supaya stok bisa dipulihkan tepat.
 *
 * Baris lama diisi sebisanya dengan mencocokkan nama; yang tidak ketemu
 * (atau namanya dobel) dibiarkan NULL dan stoknya tidak ikut dikembalikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_sale_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('fnb_sale_id')
                ->constrained()->nullOnDelete();
        });

        // Backfill: hanya untuk nama yang cocok persis dan tidak ambigu.
        $unik = DB::table('products')
            ->select('name', DB::raw('MIN(id) as id'), DB::raw('COUNT(*) as jumlah'))
            ->groupBy('name')
            ->having('jumlah', '=', 1)
            ->pluck('id', 'name');

        foreach ($unik as $name => $id) {
            DB::table('fnb_sale_items')
                ->where('product_name', $name)
                ->update(['product_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('fnb_sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};

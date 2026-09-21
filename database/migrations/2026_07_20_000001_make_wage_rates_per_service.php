<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wage_rates', function (Blueprint $table) {
            $table->string('service')->default('reguler')->after('category');
        });

        Schema::table('wage_rates', function (Blueprint $table) {
            $table->dropUnique(['category']);
            $table->unique(['category', 'service']);
        });

        // Salin upah lama per kategori ke semua layanan yang tersedia untuk kategori itu.
        // Dengan begitu nilai yang sudah owner set tidak hilang saat sistem pindah
        // dari "upah per kendaraan" menjadi "upah per layanan".
        $lama = DB::table('wage_rates')
            ->where('service', 'reguler')
            ->pluck('amount', 'category');

        DB::table('wash_prices')
            ->select('category_slug', 'service_slug')
            ->orderBy('category_slug')
            ->orderBy('service_slug')
            ->get()
            ->each(function ($row) use ($lama) {
                DB::table('wage_rates')->updateOrInsert(
                    ['category' => $row->category_slug, 'service' => $row->service_slug],
                    ['amount' => (int) ($lama[$row->category_slug] ?? 0), 'created_at' => now(), 'updated_at' => now()],
                );
            });
    }

    public function down(): void
    {
        DB::table('wage_rates')
            ->where('service', '<>', 'reguler')
            ->delete();

        Schema::table('wage_rates', function (Blueprint $table) {
            $table->dropUnique(['category', 'service']);
            $table->unique('category');
            $table->dropColumn('service');
        });
    }
};

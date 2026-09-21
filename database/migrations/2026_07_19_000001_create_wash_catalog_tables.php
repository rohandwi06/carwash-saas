<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis kendaraan, jenis layanan, dan matriks harganya pindah dari
 * config/carwash.php ke database supaya owner bisa TAMBAH & HAPUS,
 * bukan cuma mengubah angkanya.
 *
 * Acuannya tetap SLUG (bukan id) supaya transaksi lama — yang menyimpan
 * kategori/layanan sebagai teks — tetap terbaca.
 *
 * wash_prices menyimpan harga TOTAL per (kategori x layanan). Ada barisnya
 * = layanan itu tersedia untuk kategori tsb. Motor misalnya cuma punya
 * baris 'reguler', jadi pemilih layanan tidak muncul untuk motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('shape')->default('hatch'); // bentuk siluet di layar kasir
            $table->string('examples')->nullable();    // contoh kendaraan, tampil di kartu
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wash_services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wash_prices', function (Blueprint $table) {
            $table->id();
            $table->string('category_slug');
            $table->string('service_slug');
            $table->unsignedInteger('price'); // harga TOTAL, bukan tambahan
            $table->unique(['category_slug', 'service_slug']);
        });

        $this->isiDariConfig();
    }

    /** Salin isi config/carwash.php + override harga yang sudah dipakai owner. */
    private function isiDariConfig(): void
    {
        $bentuk = ['motor' => 'moto', 'kecil' => 'hatch', 'sedang' => 'mpv', 'besar' => 'van'];
        $contoh = [
            'motor'  => 'Vario · PCX · Scoopy',
            'kecil'  => 'Brio · Agya · Jazz',
            'sedang' => 'Avanza · Innova · Fortuner',
            'besar'  => 'Alphard · Hiace · Hilux DC',
        ];

        // Harga yang sedang berlaku = config + override owner (tabel settings)
        $json      = DB::table('settings')->where('key', 'carwash_price_overrides')->value('value');
        $overrides = $json ? json_decode($json, true) : [];

        $urut = 0;
        foreach (config('carwash.categories', []) as $slug => $kat) {
            DB::table('wash_categories')->insert([
                'slug'       => $slug,
                'label'      => $kat['label'],
                'shape'      => $bentuk[$slug] ?? 'hatch',
                'examples'   => $contoh[$slug] ?? null,
                'sort_order' => $urut++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $urut = 0;
        foreach (config('carwash.services', []) as $slug => $sv) {
            DB::table('wash_services')->insert([
                'slug'       => $slug,
                'label'      => $sv['label'],
                'sort_order' => $urut++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (config('carwash.categories', []) as $katSlug => $kat) {
            $dasar = $overrides['categories'][$katSlug] ?? $kat['price'];

            foreach (config('carwash.services', []) as $svSlug => $sv) {
                // Motor hanya punya cuci reguler (perilaku lama dipertahankan)
                if ($katSlug === 'motor' && $svSlug !== 'reguler') {
                    continue;
                }

                $tambahan = $overrides['service_extras'][$katSlug][$svSlug]
                    ?? $kat['service_extras'][$svSlug]
                    ?? $overrides['services'][$svSlug]
                    ?? $sv['extra'];

                DB::table('wash_prices')->insert([
                    'category_slug' => $katSlug,
                    'service_slug'  => $svSlug,
                    'price'         => $dasar + ($svSlug === 'reguler' ? 0 : $tambahan),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wash_prices');
        Schema::dropIfExists('wash_services');
        Schema::dropIfExists('wash_categories');
    }
};

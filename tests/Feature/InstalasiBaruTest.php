<?php

namespace Tests\Feature;

use App\Models\WageRate;
use App\Models\WashPrice;
use Database\Seeders\WageRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keadaan database cucian yang baru dipasang (migrate --seed, yang juga
 * menjadi database.sql dari buat-paket.ps1). Cucian baru tidak punya data
 * lama untuk disalin, jadi celah di sini baru ketahuan di klien kedua.
 */
class InstalasiBaruTest extends TestCase
{
    use RefreshDatabase;

    public function test_setiap_layanan_berharga_punya_tarif_upah_bukan_nol(): void
    {
        $this->seed();

        $kosong = WashPrice::all()->filter(fn ($p) => (int) WageRate::where('category', $p->category_slug)
            ->where('service', $p->service_slug)->value('amount') === 0);

        $this->assertCount(0, $kosong, 'Layanan tanpa upah: '
            .$kosong->map(fn ($p) => $p->category_slug.'/'.$p->service_slug)->implode(', '));
    }

    public function test_seeder_upah_tidak_menimpa_tarif_yang_sudah_diatur_owner(): void
    {
        $this->seed();
        WageRate::where('category', 'kecil')->where('service', 'reguler')->update(['amount' => 17500]);

        $this->seed(WageRateSeeder::class);

        $this->assertSame(17500, (int) WageRate::where('category', 'kecil')->where('service', 'reguler')->value('amount'));
    }
}

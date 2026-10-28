<?php

namespace Tests\Feature;

use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekap Hari Ini akun kasir (permintaan owner 28/10): cukup uang masuk dan
 * berapa yang disetor. Upah pekerja, kompensasi, dan laba bersih hanya untuk
 * owner — kartunya disembunyikan di web & aplikasi, dan CSV harian yang
 * diunduh kasir tidak memuatnya.
 */
class RekapKasirTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_csv_kasir_tanpa_upah_dan_laba_owner_lengkap(): void
    {
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        WashCategory::create(['slug' => 'ujimobil', 'label' => 'Mobil Uji', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 30000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 12000]);
        $andi = Worker::create(['name' => 'Andi']);
        $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
            'worker_ids' => [$andi->id],
        ], $this->header('kasir'))->assertCreated();

        $unduh = fn (string $peran) => $this->get('/api/reports/daily/csv?date='.now()->toDateString(), $this->header($peran))
            ->assertOk()->streamedContent();

        $kasir = $unduh('kasir');
        $this->assertStringContainsString('"Cash Total";30000', $kasir);
        $this->assertStringContainsString('Pengeluaran;', $kasir, 'Pengeluaran ikut menentukan setoran.');
        $this->assertStringNotContainsString('Upah pekerja', $kasir);
        $this->assertStringNotContainsString('Kompensasi karyawan', $kasir);
        $this->assertStringNotContainsString('LABA BERSIH', $kasir);

        $owner = $unduh('owner');
        $this->assertStringContainsString('"Upah pekerja";12000', $owner);
        $this->assertStringContainsString('"LABA BERSIH";18000', $owner);
    }
}

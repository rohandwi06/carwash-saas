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
 * Omzet = uang masuk KOTOR (cucian + F&B + tip), tanpa dikurangi pengeluaran
 * — keputusan owner 2026-10-12. Sebelumnya omzet sudah dipotong pengeluaran,
 * dan terbaca seperti salah hitung (cucian Rp 795.000, omzet Rp 685.000).
 *
 * Yang dijaga: omzet tidak ikut turun saat ada pengeluaran, sama di Dashboard
 * dan Pembukuan, dan LABA tidak berubah oleh pergantian arti ini. Juga: upah
 * satu cucian dibagi habis ke pekerjanya, tanpa rupiah yang hilang.
 */
class OmzetKotorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        WashCategory::create(['slug' => 'ujimobil', 'label' => 'Mobil Uji', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 250000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 100000]);
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_omzet_tidak_dikurangi_pengeluaran_dan_laba_tetap(): void
    {
        $pekerja = collect(['Adi', 'Budi', 'Cici'])->map(fn ($n) => Worker::create(['name' => $n])->id)->all();
        $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
            'tip' => 5000, 'worker_ids' => $pekerja,
        ], $this->header('kasir'))->assertCreated();
        $this->postJson('/api/expenses', ['description' => 'Sabun', 'amount' => 110000], $this->header())
            ->assertSuccessful();

        $hari = now()->toDateString();
        $masuk = 250000 + 5000;
        $laba = $masuk - 100000 - 110000;

        $statistik = $this->getJson('/api/reports/stats?period=harian', $this->header())->assertOk()->json('data.summary');
        $this->assertSame($masuk, $statistik['omzet'], 'Omzet Dashboard = uang masuk kotor.');
        $this->assertSame($laba, $statistik['profit']);

        $rentang = $this->getJson("/api/reports/date-range?from=$hari&to=$hari", $this->header())->assertOk()->json('data');
        $this->assertSame($masuk, $rentang['summary']['total'], 'Omzet Pembukuan = uang masuk kotor.');
        $this->assertSame($masuk, $rentang['days'][0]['omzet']);
        $this->assertSame($laba, $rentang['days'][0]['profit']);

        $harian = $this->getJson("/api/reports/daily?date=$hari", $this->header())->assertOk()->json('data');
        $this->assertSame($laba, $harian['profit'], 'Laba harian tidak berubah oleh arti omzet yang baru.');
        // Rp 100.000 bertiga: 33.334 + 33.333 + 33.333, bukan 3 x 33.333.
        $this->assertSame(100000, $harian['wages'], 'Upah dibagi habis, tidak ada rupiah yang hilang.');
        $this->assertSame($masuk, $harian['books'][0]['omzet'], 'Omzet per buku juga kotor.');
    }
}

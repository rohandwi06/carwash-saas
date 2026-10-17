<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ganti jenis satu mobil -> AI menebak alasannya dan mengusulkan mobil
 * sejenis yang masih salah jenis; owner memilih, lalu mobil & transaksi
 * lamanya dibetulkan (permintaan owner 2026-10-17).
 */
class KendaraanSejenisTest extends TestCase
{
    use RefreshDatabase;

    private array $prompt = [];

    protected function setUp(): void
    {
        parent::setUp();
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        Vehicle::query()->delete();
        WashCategory::create(['slug' => 'kecil', 'label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashCategory::create(['slug' => 'mobil', 'label' => 'Mobil', 'sort_order' => 2]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashService::create(['slug' => 'poles', 'label' => 'Poles', 'sort_order' => 2]);
        foreach (['kecil' => 35000, 'mobil' => 40000] as $kat => $harga) {
            WashPrice::create(['category_slug' => $kat, 'service_slug' => 'reguler', 'price' => $harga]);
        }
        // Poles hanya untuk "mobil": transaksi poles tidak bisa pindah ke kecil.
        WashPrice::create(['category_slug' => 'mobil', 'service_slug' => 'poles', 'price' => 90000]);

        foreach (['Honda Brio', 'Toyota Agya', 'Daihatsu Ayla', 'Toyota Avanza'] as $nama) {
            Vehicle::create(['name' => $nama, 'category' => 'mobil']);
        }

        config(['carwash.gemini.key' => 'kunci-uji']);
        Http::fake(function (HttpRequest $r) {
            $this->prompt[] = data_get($r->data(), 'contents.0.parts.0.text');
            $agya = Vehicle::where('name', 'Toyota Agya')->value('id');
            $ayla = Vehicle::where('name', 'Daihatsu Ayla')->value('id');

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'aturan' => 'hatchback kecil, panjang di bawah 4 meter',
                'pindah' => [
                    ['id' => $agya, 'alasan' => 'hatchback 3,7 m'],
                    ['id' => $ayla, 'alasan' => 'hatchback 3,6 m'],
                    ['id' => 999999, 'alasan' => 'id karangan AI'],
                ],
            ])]]]]]]);
        });
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cuci(string $nama, string $kat, string $layanan = 'reguler'): Transaction
    {
        $id = $this->postJson('/api/transactions', [
            'vehicle_name' => $nama, 'category' => $kat, 'service' => $layanan, 'payment_method' => 'cash',
        ], $this->header('kasir'))->assertCreated()->json('data.id');

        return Transaction::findOrFail($id);
    }

    public function test_ai_mengusulkan_mobil_sejenis_dengan_alasan(): void
    {
        $this->cuci('Toyota Agya', 'mobil');
        $brio = Vehicle::where('name', 'Honda Brio')->first();
        $this->patchJson("/api/vehicles/{$brio->id}", ['category' => 'kecil'], $this->header())->assertOk();

        $r = $this->postJson("/api/vehicles/{$brio->id}/sejenis", ['dari' => 'mobil'], $this->header())
            ->assertOk()->json('data');

        $this->assertSame('hatchback kecil, panjang di bawah 4 meter', $r['aturan']);
        $this->assertSame(['Toyota Agya', 'Daihatsu Ayla'], array_column($r['usulan'], 'name'),
            'Id karangan AI dibuang; urutan mengikuti jawaban AI.');
        $this->assertSame(1, $r['usulan'][0]['transaksi'], 'Satu transaksi Agya akan ikut dibetulkan.');

        // Yang ditanyakan ke AI hanya mobil di jenis lama, bukan Brio sendiri.
        $this->assertStringContainsString('Honda Brio', $this->prompt[0]);
        $this->assertStringContainsString('Toyota Avanza', $this->prompt[0]);
        $this->assertStringNotContainsString("] Honda Brio", $this->prompt[0]);

        // Hanya usulan: belum ada yang berubah.
        $this->assertSame('mobil', Vehicle::where('name', 'Toyota Agya')->value('category'));

        // Dibuka lagi = dari ingatan, AI tidak ditanya ulang.
        $this->postJson("/api/vehicles/{$brio->id}/sejenis", ['dari' => 'mobil'], $this->header())->assertOk();
        $this->assertCount(1, $this->prompt);
    }

    public function test_terapkan_memindah_mobil_dan_membetulkan_transaksi_lama(): void
    {
        $agya = $this->cuci('Toyota Agya', 'mobil');
        $polesAgya = $this->cuci('Toyota Agya', 'mobil', 'poles');
        $avanza = $this->cuci('Toyota Avanza', 'mobil');
        $this->assertSame(40000, $agya->total);

        $ids = Vehicle::whereIn('name', ['Toyota Agya', 'Daihatsu Ayla'])->pluck('id')->all();
        $r = $this->postJson('/api/vehicles/pindah-sejenis', [
            'ids' => $ids, 'category' => 'kecil', 'alasan' => 'hatchback kecil',
        ], $this->header())->assertOk()->json('data');

        $this->assertSame(['mobil' => 2, 'transaksi' => 1, 'dilewati' => 1], $r,
            'Transaksi poles dilewati: poles tidak tersedia untuk mobil kecil.');
        $this->assertSame('kecil', Vehicle::where('name', 'Toyota Agya')->value('category'));
        $this->assertNotNull(Vehicle::where('name', 'Toyota Agya')->value('corrected_at'),
            'Ditandai koreksi: ikut jadi contoh untuk AI.');

        $agya->refresh();
        $this->assertSame('kecil', $agya->category);
        $this->assertSame(35000, $agya->total, 'Harga dihitung ulang.');
        $this->assertSame(1, $agya->edit_count);
        $this->assertStringContainsString('hatchback kecil', $agya->edit_reason);
        $this->assertSame('Owner Uji', $agya->edited_by);

        $this->assertSame('mobil', $polesAgya->refresh()->category);
        $this->assertSame('mobil', $avanza->refresh()->category, 'Mobil yang tidak dipilih tidak disentuh.');
    }

    public function test_hanya_owner(): void
    {
        $brio = Vehicle::where('name', 'Honda Brio')->first();
        $this->postJson("/api/vehicles/{$brio->id}/sejenis", ['dari' => 'mobil'], $this->header('kasir'))->assertForbidden();
        $this->postJson('/api/vehicles/pindah-sejenis', ['ids' => [$brio->id], 'category' => 'kecil'], $this->header('kasir'))
            ->assertForbidden();
    }

    public function test_tanpa_calon_ai_tidak_ditanya(): void
    {
        Vehicle::where('name', '!=', 'Honda Brio')->delete();
        $brio = Vehicle::where('name', 'Honda Brio')->first();
        $brio->update(['category' => 'kecil']);

        $r = $this->postJson("/api/vehicles/{$brio->id}/sejenis", ['dari' => 'mobil'], $this->header())
            ->assertOk()->json('data');
        $this->assertSame([], $r['usulan']);
        $this->assertCount(0, $this->prompt);
    }
}

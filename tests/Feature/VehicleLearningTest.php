<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Models\VehicleAlias;
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
 * AI yang belajar dari koreksi — permintaan owner 2026-10-16.
 *
 * Yang dijaga:
 *  - ejaan kasir diingat dan, setelah dua kali untuk mobil yang sama,
 *    langsung ditemukan pencarian sebagai jawaban yakin;
 *  - ejaan yang sudah ketemu lewat nama katalog tidak diingat, dan ejaan
 *    yang pindah mobil mulai dari nol;
 *  - koreksi jenis oleh owner ditandai dan dikirim ke AI sebagai contoh,
 *    dan ingatan AI yang lama tidak dipakai lagi sesudah koreksi.
 */
class VehicleLearningTest extends TestCase
{
    use RefreshDatabase;

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
        foreach (['kecil' => 35000, 'mobil' => 40000] as $kat => $harga) {
            WashPrice::create(['category_slug' => $kat, 'service_slug' => 'reguler', 'price' => $harga]);
        }
        Vehicle::create(['name' => 'Toyota Raize', 'category' => 'mobil']);
        Vehicle::create(['name' => 'Toyota Avanza', 'category' => 'mobil']);
        Vehicle::create(['name' => 'Honda Brio', 'category' => 'kecil']);
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cuci(string $nama, string $kat, ?string $ketikan): void
    {
        $this->postJson('/api/transactions', [
            'vehicle_name' => $nama, 'category' => $kat, 'payment_method' => 'cash', 'search_query' => $ketikan,
        ], $this->header('kasir'))->assertCreated();
    }

    private function cari(string $q): array
    {
        return $this->getJson('/api/vehicles/search?q='.urlencode($q), $this->header('kasir'))->assertOk()->json('data');
    }

    public function test_ejaan_kasir_aktif_setelah_dua_kali(): void
    {
        $this->cuci('Toyota Raize', 'mobil', 'raise');
        $this->assertSame(1, VehicleAlias::where('alias', 'raise')->value('hits'));
        $this->assertNotEquals(2.0, (float) ($this->cari('raise')[0]['score'] ?? 0), 'Sekali belum dipercaya.');

        $this->cuci('Toyota Raize', 'mobil', 'Raise ');
        $hasil = $this->cari('RAISE');
        $this->assertSame('Toyota Raize', $hasil[0]['name']);
        $this->assertEquals(2.0, $hasil[0]['score'], 'Skor yakin: AI tidak ikut ditanya.');
    }

    public function test_ejaan_yang_sudah_ketemu_atau_bukan_katalog_tidak_diingat(): void
    {
        $this->cuci('Toyota Avanza', 'mobil', 'avanza');      // ada di nama katalog
        $this->cuci('Mobil Kecil', 'kecil', 'sigra');          // dipilih per jenis, bukan dari katalog
        $this->cuci('Toyota Raize', 'mobil', null);            // tanpa ketikan

        $this->assertSame(0, VehicleAlias::count());
    }

    public function test_ejaan_yang_pindah_mobil_mulai_dari_nol(): void
    {
        $this->cuci('Toyota Raize', 'mobil', 'rize');
        $this->cuci('Toyota Raize', 'mobil', 'rize');
        $this->cuci('Honda Brio', 'kecil', 'rize');

        $a = VehicleAlias::where('alias', 'rize')->first();
        $this->assertSame('Honda Brio', $a->vehicle->name);
        $this->assertSame(1, $a->hits, 'Satu ketukan meleset tidak langsung jadi aturan.');
    }

    public function test_koreksi_owner_ditandai(): void
    {
        $raize = Vehicle::where('name', 'Toyota Raize')->first();
        $this->patchJson("/api/vehicles/{$raize->id}", ['category' => 'mobil'], $this->header())->assertOk();
        $this->assertNull($raize->fresh()->corrected_at, 'Jenis tidak berubah = bukan koreksi.');

        $this->patchJson("/api/vehicles/{$raize->id}", ['category' => 'kecil'], $this->header())->assertOk();
        $this->assertNotNull($raize->fresh()->corrected_at);

        $brio = Vehicle::where('name', 'Honda Brio')->first();
        $this->postJson('/api/vehicles/massal', ['aksi' => 'pindah', 'ids' => [$brio->id], 'category' => 'mobil'], $this->header())
            ->assertOk();
        $this->assertNotNull($brio->fresh()->corrected_at);

        $this->postJson('/api/vehicles', ['name' => 'Daihatsu Rocky', 'category' => 'mobil', 'dari_koreksi' => true], $this->header())
            ->assertCreated();
        $this->assertNotNull(Vehicle::where('name', 'Daihatsu Rocky')->value('corrected_at'));
    }

    public function test_koreksi_dikirim_ke_ai_dan_ingatan_lama_tidak_dipakai(): void
    {
        config(['carwash.gemini.key' => 'kunci-uji']);
        $prompt = [];
        Http::fake(function (HttpRequest $r) use (&$prompt) {
            $prompt[] = data_get($r->data(), 'contents.0.parts.0.text');

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'name' => 'Daihatsu Rocky', 'category' => 'mobil', 'reason' => 'uji', 'recognized' => true,
            ])]]]]]]);
        });

        $tanya = fn () => $this->postJson('/api/ai/classify-vehicle', ['query' => 'roky'], $this->header('kasir'))->assertOk();

        $tanya();
        $this->assertCount(1, $prompt);
        $this->assertStringNotContainsString('koreksi dari pemiliknya', $prompt[0]);

        $tanya();
        $this->assertCount(1, $prompt, 'Tanpa koreksi baru, jawaban diambil dari ingatan.');

        $raize = Vehicle::where('name', 'Toyota Raize')->first();
        $this->patchJson("/api/vehicles/{$raize->id}", ['category' => 'kecil'], $this->header())->assertOk();

        $tanya();
        $this->assertCount(2, $prompt, 'Sesudah koreksi, AI ditanya ulang.');
        $this->assertStringContainsString('koreksi dari pemiliknya', $prompt[1]);
        $this->assertStringContainsString('Toyota Raize = "kecil"', $prompt[1]);
    }

    public function test_daftar_ejaan_hanya_owner_dan_bisa_dilupakan(): void
    {
        $this->cuci('Toyota Raize', 'mobil', 'raise');
        $this->getJson('/api/vehicle-aliases', $this->header('kasir'))->assertForbidden();

        $d = $this->getJson('/api/vehicle-aliases', $this->header())->assertOk()->json('data');
        $this->assertSame([['id' => $d[0]['id'], 'alias' => 'raise', 'hits' => 1, 'aktif' => false, 'vehicle' => 'Toyota Raize']], $d);

        $this->deleteJson('/api/vehicle-aliases/'.$d[0]['id'], [], $this->header())->assertOk();
        $this->assertSame(0, VehicleAlias::count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\VehiclePhoto;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use App\Services\FotoKendaraanService;
use Tests\TestCase;

/**
 * Foto kendaraan — permintaan owner 2026-10-16.
 *
 * Yang dijaga: foto kondisi menempel di transaksinya dan hanya bisa dibuka
 * yang login; berkas yang bukan gambar ditolak; paling banyak 6 foto per
 * transaksi; foto lebih tua dari 60 hari terhapus; hanya owner yang
 * menghapus; dan AI "kenali dari foto" mengirim gambarnya, memakai katalog
 * sebagai penentu, serta hanya menerima plat yang bentuknya plat.
 */
class FotoKendaraanTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function tearDown(): void
    {
        // Folder uji dibuang beserta isinya.
        if (is_dir($this->folder)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->folder);
        }
        parent::tearDown();
    }

    /** PNG 1x1 piksel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uji-foto-'.uniqid();
        config(['carwash.foto_dir' => $this->folder]);
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        WashCategory::create(['slug' => 'kecil', 'label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashCategory::create(['slug' => 'mobil', 'label' => 'Mobil', 'sort_order' => 2]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'kecil', 'service_slug' => 'reguler', 'price' => 35000]);
        WashPrice::create(['category_slug' => 'mobil', 'service_slug' => 'reguler', 'price' => 40000]);
    }

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cuci(): int
    {
        return $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'mobil', 'payment_method' => 'cash',
        ], $this->header())->assertCreated()->json('data.id');
    }

    private function unggah(int $trx, string $isi = 'data:image/png;base64,'.self::PNG): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/transactions/$trx/photos", ['image' => $isi], $this->header());
    }

    public function test_foto_menempel_di_transaksi_dan_bisa_dibuka(): void
    {
        $trx = $this->cuci();
        $id = $this->unggah($trx)->assertCreated()->json('data.id');

        $foto = VehiclePhoto::find($id);
        $this->assertFileExists(FotoKendaraanService::lokasi($foto->path));
        $this->assertSame('Kasir Uji', $foto->created_by);

        $daftar = $this->getJson('/api/transactions', $this->header())->assertOk()->json('data');
        $this->assertSame([$id], array_column($daftar[0]['photos'], 'id'));
        $this->assertArrayNotHasKey('path', $daftar[0]['photos'][0], 'Lokasi berkas tidak dikirim ke layar.');

        $this->get("/api/photos/$id", $this->header())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson("/api/photos/$id")->assertUnauthorized();
    }

    public function test_bukan_gambar_ditolak_dan_maksimal_enam(): void
    {
        $trx = $this->cuci();
        $this->unggah($trx, 'data:image/png;base64,'.base64_encode('bukan gambar'))->assertStatus(422);
        $this->unggah($trx, 'bukan base64 sama sekali !!!')->assertStatus(422);

        for ($i = 0; $i < 6; $i++) {
            $this->unggah($trx)->assertCreated();
        }
        $this->unggah($trx)->assertStatus(422);
    }

    public function test_foto_lebih_tua_dari_60_hari_terhapus(): void
    {
        $lama = VehiclePhoto::find($this->unggah($this->cuci())->json('data.id'));

        $this->travel(61)->days();
        $this->unggah($this->cuci())->assertCreated();   // unggahan baru ikut membersihkan

        $this->assertNull(VehiclePhoto::find($lama->id));
        $this->assertFileDoesNotExist(FotoKendaraanService::lokasi($lama->path));
        $this->get("/api/photos/{$lama->id}", $this->header())->assertNotFound();
    }

    public function test_hanya_owner_yang_menghapus(): void
    {
        $id = $this->unggah($this->cuci())->json('data.id');

        $this->deleteJson("/api/photos/$id", [], $this->header('kasir'))->assertForbidden();
        $this->deleteJson("/api/photos/$id", [], $this->header('owner'))->assertOk();
        $this->assertSame(0, VehiclePhoto::count());
    }

    public function test_kenali_dari_foto(): void
    {
        config(['carwash.gemini.key' => 'kunci-uji']);
        $terkirim = null;
        Http::fake(function (HttpRequest $r) use (&$terkirim) {
            $terkirim = $r->data();

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'name' => 'Toyota Raize', 'category' => 'kecil', 'plate' => 'b1234xyz', 'reason' => 'uji', 'recognized' => true,
            ])]]]]]]);
        });
        \App\Models\Vehicle::create(['name' => 'Toyota Raize', 'category' => 'mobil']);

        $r = $this->postJson('/api/ai/classify-photo', ['image' => 'data:image/png;base64,'.self::PNG], $this->header())
            ->assertOk()->json('data');

        $this->assertSame(self::PNG, data_get($terkirim, 'contents.0.parts.1.inline_data.data'));
        $this->assertSame('image/png', data_get($terkirim, 'contents.0.parts.1.inline_data.mime_type'));
        $this->assertSame('mobil', $r['category'], 'Katalog menang atas tebakan AI.');
        $this->assertTrue($r['dikoreksi_katalog']);
        $this->assertSame('B 1234 XYZ', $r['plate']);

        $this->postJson('/api/ai/classify-photo', ['image' => base64_encode('bukan gambar')], $this->header())->assertStatus(422);
    }

    public function test_plat_hanya_yang_berbentuk_plat(): void
    {
        $sah = \App\Services\GeminiVehicleService::class;
        $this->assertSame('S 1 AB', $sah::platSah('s-1-ab'));
        $this->assertSame('AB 123', $sah::platSah('AB123'));
        $this->assertSame('', $sah::platSah('tidak terbaca'));
        $this->assertSame('', $sah::platSah('1234'));
    }
}

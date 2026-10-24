<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Supply;
use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Models\WorkerCompensation;
use App\Services\AuthTokenService;
use App\Services\LanggananService;
use App\Services\PaketService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paket langganan kasir / usaha / juragan: satu kode untuk semua cucian,
 * modul dikunci menurut setelan `paket` (config carwash.paket).
 *
 * Yang dijaga:
 *  - cucian lama tanpa setelan tidak kehilangan apa pun;
 *  - paket kasir menolak rute modul, tapi semua yang menyentuh uang jalan;
 *  - otomatisasi modul (takaran barang, aturan harian, promo) ikut berhenti;
 *  - turun paket tidak menghapus apa pun, dan pembatalan tetap merapikan stok.
 */
class PaketTest extends TestCase
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
        WashService::create(['slug' => 'reguler', 'label' => 'Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 30000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 12000]);
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function p(): PaketService
    {
        return app(PaketService::class);
    }

    private function cuci(array $tambahan = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/transactions', $tambahan + [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
        ], $this->header('kasir'));
    }

    private function sabun(): Supply
    {
        $id = $this->postJson('/api/supplies', [
            'name' => 'Sabun', 'unit' => 'liter', 'stock' => 20, 'rules' => [['qty' => 0.5]],
        ], $this->header())->assertCreated()->json('data.id');

        return Supply::findOrFail($id);
    }

    public function test_cucian_lama_tanpa_setelan_semua_modul_nyala(): void
    {
        $h = $this->header();
        $this->getJson('/api/langganan', $h)->assertOk()
            ->assertJsonPath('data.paket', null)
            ->assertJsonPath('data.modul', ['barang' => true, 'promo' => true, 'aturan_harian' => true, 'foto' => true]);

        $this->getJson('/api/supplies', $h)->assertOk();
        $this->getJson('/api/promo', $h)->assertOk();
        $this->getJson('/api/daily-rules', $h)->assertOk();
    }

    public function test_paket_kasir_menolak_modul_tapi_uang_tetap_jalan(): void
    {
        $this->p()->ganti('kasir');
        $owner = $this->header();
        $kasir = $this->header('kasir');

        $ditolak = [
            ['GET', '/api/supplies', $kasir],
            ['GET', '/api/promo/check?plate=B1234XY', $kasir],
            ['POST', '/api/ai/classify-photo', $kasir],
            ['GET', '/api/supplies', $owner],
            ['POST', '/api/supplies', $owner],
            ['GET', '/api/promo', $owner],
            ['PUT', '/api/promo', $owner],
            ['GET', '/api/daily-rules', $owner],
            ['POST', '/api/daily-rules', $owner],
        ];
        foreach ($ditolak as [$metode, $url, $h]) {
            $this->json($metode, $url, [], $h)->assertStatus(403)
                ->assertJsonPath('message', PaketService::PESAN);
        }
        // Pesan netral: tanpa harga, tanpa tautan (aturan Play Store).
        $this->assertDoesNotMatchRegularExpression('/rp|http|bayar|upgrade/i', PaketService::PESAN);

        // Semua yang menyentuh uang tetap jalan, termasuk titip jual & kompensasi manual.
        $this->cuci()->assertCreated();
        $this->getJson('/api/consignors', $owner)->assertOk();
        $this->getJson('/api/worker-compensations', $owner)->assertOk();
        $this->getJson('/api/reports/daily', $owner)->assertOk();

        $this->getJson('/api/langganan', $kasir)->assertOk()
            ->assertJsonPath('data.paket', 'kasir')
            ->assertJsonPath('data.modul', ['barang' => false, 'promo' => false, 'aturan_harian' => false, 'foto' => false]);
    }

    public function test_usaha_dan_juragan_punya_semua_modul(): void
    {
        foreach (['usaha', 'juragan'] as $paket) {
            $this->p()->ganti($paket);
            $this->assertSame(['barang' => true, 'promo' => true, 'aturan_harian' => true, 'foto' => true], $this->p()->modul());
            $this->getJson('/api/supplies', $this->header('kasir'))->assertOk();
        }
    }

    public function test_paket_kasir_tidak_memotong_barang_dan_tidak_menjalankan_aturan(): void
    {
        $s = $this->sabun();
        $andi = Worker::create(['name' => 'Andi']);
        $this->postJson('/api/daily-rules', [
            'type' => 'kompensasi', 'amount' => 10000, 'reason' => 'Uang makan', 'all_workers' => true,
        ], $this->header())->assertCreated();

        $this->p()->ganti('kasir');
        $this->cuci(['worker_ids' => [$andi->id], 'supplies' => [['supply_id' => $s->id, 'qty' => 2]]])->assertCreated();
        $this->assertEqualsWithDelta(20.0, $s->fresh()->stock, 0.0001, 'Takaran & jumlah kiriman kasir diabaikan.');
        $this->assertSame(0, WorkerCompensation::count());

        // Naik lagi: barang & aturan kembali bekerja, datanya masih utuh.
        $this->p()->ganti('usaha');
        $this->cuci(['worker_ids' => [$andi->id]])->assertCreated();
        $this->assertEqualsWithDelta(19.5, $s->fresh()->stock, 0.0001);
        $this->assertSame(1, WorkerCompensation::count());
    }

    public function test_turun_paket_pembatalan_tetap_mengembalikan_barang(): void
    {
        $s = $this->sabun();
        $trx = Transaction::findOrFail($this->cuci()->assertCreated()->json('data.id'));
        $this->assertEqualsWithDelta(19.5, $s->fresh()->stock, 0.0001);

        $this->p()->ganti('kasir');
        app(TransactionService::class)->void($trx, 'salah input', 'Owner Uji');
        $this->assertEqualsWithDelta(20.0, $s->fresh()->stock, 0.0001);
    }

    public function test_promo_ditolak_bukan_diabaikan_di_paket_kasir(): void
    {
        $this->p()->ganti('kasir');
        $this->cuci(['plate' => 'B1234XY', 'pakai_promo' => true])->assertStatus(422)
            ->assertJsonPath('message', PaketService::PESAN);
        $this->assertSame(0, Transaction::count());
    }

    public function test_aktivasi_pusat_bisa_mengganti_paket(): void
    {
        config(['carwash.langganan.kunci' => 'kunci-uji']);
        app(LanggananService::class)->mulaiTerkunci();
        $kirim = function (array $isi) {
            $badan = json_encode($isi + ['waktu' => time()]);

            return $this->call('POST', '/api/langganan/perpanjang', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TANDA' => hash_hmac('sha256', $badan, 'kunci-uji'),
            ], $badan);
        };

        $kirim(['pesanan' => 'ORD-1', 'hari' => 30, 'paket' => 'usaha'])->assertOk()
            ->assertJsonPath('data.aktif', true)->assertJsonPath('data.paket', 'usaha');
        $sampai = Setting::get(LanggananService::KEY);

        // Paket tak dikenal: tidak ada yang berubah sama sekali.
        $kirim(['pesanan' => 'ORD-2', 'hari' => 30, 'paket' => 'emas'])->assertStatus(422);
        $this->assertSame($sampai, Setting::get(LanggananService::KEY));
        $this->assertSame('usaha', $this->p()->paket());

        // Ganti paket di tengah masa aktif: hari 0, tanggal akhir tetap.
        $kirim(['pesanan' => 'ORD-3', 'hari' => 0, 'paket' => 'kasir'])->assertOk()->assertJsonPath('data.paket', 'kasir');
        $this->assertSame($sampai, Setting::get(LanggananService::KEY));

        $kirim(['pesanan' => 'ORD-4', 'hari' => 0])->assertStatus(422);
    }

    public function test_perintah_artisan_paket(): void
    {
        $this->artisan('langganan', ['aksi' => 'paket', 'nilai' => 'kasir'])->assertSuccessful();
        $this->assertSame('kasir', $this->p()->paket());
        $this->artisan('langganan', ['aksi' => 'paket', 'nilai' => 'semua'])->assertSuccessful();
        $this->assertNull($this->p()->paket());

        // Perintah lama tetap sama.
        app(LanggananService::class)->mulaiTerkunci();
        $this->artisan('langganan', ['aksi' => 'tambah', 'nilai' => '30'])->assertSuccessful();
        $this->assertTrue(app(LanggananService::class)->aktif());

        $this->expectException(\InvalidArgumentException::class);
        $this->artisan('langganan', ['aksi' => 'paket', 'nilai' => 'emas'])->run();
    }

    public function test_isi_setelan_tak_dikenal_dianggap_cucian_lama(): void
    {
        Setting::put(PaketService::KEY, 'Usaha ');
        $this->assertNull($this->p()->paket());
        $this->assertTrue($this->p()->punya('barang'));
    }
}

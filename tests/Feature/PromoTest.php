<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promo pelanggan setia — permintaan owner 2026-10-16.
 *
 * Yang dijaga: pelanggan dikenali dari plat ATAU telepon dengan cara tulis
 * apa pun; hak dihitung seperti kartu stempel dan tidak hangus bila dilewati;
 * server yang memutuskan berhak dan besar potongannya; potongan hanya untuk
 * harga layanan; upah penuh atau ikut harga promo sesuai pilihan owner; dan
 * koreksi transaksi tidak menghapus promonya.
 */
class PromoTest extends TestCase
{
    use RefreshDatabase;

    private int $addonId;

    protected function setUp(): void
    {
        parent::setUp();
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        Addon::query()->delete();
        WashCategory::create(['slug' => 'ujimobil', 'label' => 'Mobil Uji', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashService::create(['slug' => 'komplit', 'label' => 'Komplit', 'sort_order' => 2]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 40000]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'komplit', 'price' => 60000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 12000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'komplit', 'amount' => 18000]);
        $this->addonId = Addon::create(['name' => 'Semir', 'price' => 5000, 'is_active' => true])->id;
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function aturPromo(array $ubah = []): void
    {
        $this->putJson('/api/promo', $ubah + ['aktif' => true, 'jenis' => 'gratis', 'setiap' => 3, 'upah' => 'penuh'],
            $this->header())->assertOk();
    }

    private function cuci(array $ubah = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/transactions', $ubah + [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash', 'plate' => 'B 1234 XY',
        ], $this->header('kasir'));
    }

    private function cek(string $plat = 'B 1234 XY', string $telepon = ''): array
    {
        return $this->getJson('/api/promo/check?plate='.urlencode($plat).'&phone='.urlencode($telepon), $this->header('kasir'))
            ->assertOk()->json('data');
    }

    public function test_promo_mati_tidak_menghitung_dan_menolak_dipakai(): void
    {
        $this->cuci()->assertCreated();
        $this->assertFalse($this->cek()['aktif']);
        $this->cuci(['pakai_promo' => true])->assertStatus(422);
    }

    public function test_kedatangan_ke_n_berhak_dengan_cara_tulis_apa_pun(): void
    {
        $this->aturPromo();
        $this->cuci(['plate' => 'B 1234 XY'])->assertCreated();
        $this->cuci(['plate' => 'b1234xy'])->assertCreated();

        $c = $this->cek('B-1234-XY');
        $this->assertSame(3, $c['kunjungan']);
        $this->assertTrue($c['berhak']);

        // Telepon saja juga dikenali: 0812… dan +62 812… nomor yang sama.
        $this->cuci(['plate' => null, 'customer_phone' => '0812-3456-7890'])->assertCreated();
        $c = $this->cek('', '+62 812 3456 7890');
        $this->assertSame(2, $c['kunjungan']);
        $this->assertFalse($c['berhak']);
        $this->assertSame(1, $c['sisa']);
    }

    public function test_cuci_gratis_hanya_layanan_dan_upah_penuh(): void
    {
        $this->aturPromo();
        $pekerja = Worker::create(['name' => 'Adi'])->id;
        $this->cuci()->assertCreated();
        $this->cuci()->assertCreated();

        $trx = $this->cuci(['pakai_promo' => true, 'addon_ids' => [$this->addonId], 'worker_ids' => [$pekerja]])
            ->assertCreated()->json('data');

        $this->assertSame(5000, $trx['total'], 'Layanan gratis, add-on tetap dibayar.');
        $this->assertSame(40000, $trx['promo_discount']);
        $this->assertSame('gratis', $trx['promo_rule']);
        $this->assertSame(12000, $trx['workers'][0]['pivot']['wage_share'], 'Upah penuh seperti cucian biasa.');

        // Haknya sudah dipakai: kedatangan berikutnya tidak berhak lagi.
        $this->assertFalse($this->cek()['berhak']);
        $this->cuci(['pakai_promo' => true])->assertStatus(422);
    }

    public function test_upah_ikut_harga_promo(): void
    {
        $this->aturPromo(['jenis' => 'persen', 'nilai' => 25, 'upah' => 'ikut']);
        $pekerja = Worker::create(['name' => 'Adi'])->id;
        $this->cuci()->assertCreated();
        $this->cuci()->assertCreated();

        $trx = $this->cuci(['pakai_promo' => true, 'worker_ids' => [$pekerja]])->assertCreated()->json('data');

        $this->assertSame(30000, $trx['total']);
        $this->assertSame(10000, $trx['promo_discount']);
        $this->assertSame(9000, $trx['workers'][0]['pivot']['wage_share'], '12.000 x 30.000/40.000');
    }

    public function test_potongan_rupiah_tidak_melebihi_harga_layanan(): void
    {
        $this->aturPromo(['jenis' => 'rupiah', 'nilai' => 100000, 'setiap' => 2]);
        $this->cuci()->assertCreated();

        $trx = $this->cuci(['pakai_promo' => true])->assertCreated()->json('data');
        $this->assertSame(0, $trx['total']);
        $this->assertSame(40000, $trx['promo_discount']);
    }

    public function test_hak_tidak_hangus_bila_dilewati_dan_batal_tidak_dihitung(): void
    {
        $this->aturPromo();
        $this->cuci()->assertCreated();
        $batal = $this->cuci()->assertCreated()->json('data.id');
        $this->postJson("/api/transactions/$batal/void", ['reason' => 'salah input'], $this->header())->assertOk();
        $this->assertSame(2, $this->cek()['kunjungan'], 'Cucian batal bukan kedatangan.');

        $this->cuci()->assertCreated();
        $this->cuci()->assertCreated();   // kedatangan ke-3 lewat tanpa promo
        $c = $this->cek();
        $this->assertSame(4, $c['kunjungan']);
        $this->assertTrue($c['berhak'], 'Hak kedatangan ke-3 masih ada.');
    }

    public function test_hitungan_mulai_saat_promo_dinyalakan(): void
    {
        $this->cuci()->assertCreated();
        $this->cuci()->assertCreated();
        $this->travel(1)->days();
        $this->aturPromo();

        $this->assertSame(1, $this->cek()['kunjungan'], 'Cucian sebelum promo dinyalakan tidak ikut.');
    }

    public function test_koreksi_tetap_membawa_promo(): void
    {
        $this->aturPromo(['setiap' => 2]);
        $this->cuci()->assertCreated();
        $id = $this->cuci(['pakai_promo' => true])->assertCreated()->json('data.id');

        $this->patchJson("/api/transactions/$id", ['service' => 'komplit', 'edit_reason' => 'ganti layanan'], $this->header())
            ->assertOk();

        $trx = Transaction::find($id);
        $this->assertSame(0, (int) $trx->total, 'Cuci gratis tetap gratis setelah layanan dikoreksi.');
        $this->assertSame(60000, (int) $trx->promo_discount);
    }

    public function test_hanya_owner_yang_mengatur(): void
    {
        $this->putJson('/api/promo', ['aktif' => true, 'jenis' => 'gratis', 'setiap' => 5, 'upah' => 'penuh'],
            $this->header('kasir'))->assertForbidden();
        $this->putJson('/api/promo', ['aktif' => true, 'jenis' => 'persen', 'nilai' => 150, 'setiap' => 5, 'upah' => 'penuh'],
            $this->header())->assertStatus(422);
        $this->putJson('/api/promo', ['aktif' => true, 'jenis' => 'gratis', 'setiap' => 1, 'upah' => 'penuh'],
            $this->header())->assertStatus(422);
    }
}

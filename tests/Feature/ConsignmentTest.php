<?php

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\Product;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\ConsignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Titip jual — yang dijaga di sini adalah UANGNYA, bukan layarnya.
 *
 * Satu kalimat yang harus selalu benar:
 *   uang penitip boleh lewat laci cucian, tapi tidak boleh sekali pun
 *   mampir ke angka laba.
 *
 * Kalau tes ini jebol, gejalanya bukan error — melainkan owner yang merasa
 * untung tiap hari lalu kaget waktu menyetor ke penitipnya.
 */
class ConsignmentTest extends TestCase
{
    use RefreshDatabase;

    private function ownerHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function kasirHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('kasir', 'Kasir Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    /** Bu Sri nitip gorengan: minta Rp 5.000, dijual Rp 7.000. */
    private function penitipSetor(): Consignor
    {
        return Consignor::create(['name' => 'Bu Sri', 'share_mode' => 'setor']);
    }

    private function barang(Consignor $c, int $price = 7000, ?int $payout = 5000): Product
    {
        return Product::create([
            'name' => 'Gorengan', 'type' => 'makanan', 'price' => $price,
            'stock' => 0, 'is_active' => true,
            'consignor_id' => $c->id, 'payout_price' => $payout,
        ]);
    }

    private function jual(Product $p, int $qty = 1): void
    {
        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/fnb-sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $p->id, 'qty' => $qty]],
            ])->assertStatus(201);
    }

    public function test_barang_masuk_menambah_stok_dan_tercatat_riwayatnya(): void
    {
        $p = $this->barang($this->penitipSetor());

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/consignment-movements', ['product_id' => $p->id, 'type' => 'masuk', 'qty' => 20])
            ->assertStatus(201)
            ->assertJsonPath('meta.stock', 20);

        $this->assertDatabaseHas('consignment_movements', ['product_id' => $p->id, 'type' => 'masuk', 'qty' => 20]);
    }

    public function test_retur_tidak_boleh_melebihi_sisa_di_rak(): void
    {
        $p = $this->barang($this->penitipSetor());
        app(ConsignmentService::class)->terima($p, 5);

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/consignment-movements', ['product_id' => $p->id, 'type' => 'retur', 'qty' => 6])
            ->assertStatus(422);

        $this->assertSame(5, $p->refresh()->stock);
    }

    /** Inti seluruh fitur: 7.000 masuk laci, tapi cuma 2.000 yang jadi laba. */
    public function test_barang_laku_menambah_kas_penuh_tapi_laba_cuma_marginnya(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 10);

        $this->jual($p, 1);

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertSame(7000, $rekap['fnb_total'], 'Pelanggan membayar 7.000 — resi & omzet F&B harus utuh.');
        $this->assertSame(7000, $rekap['cash_total'], 'Uang fisik di laci bertambah 7.000.');
        $this->assertSame(5000, $rekap['consignor_share'], 'Hak Bu Sri dipisahkan hari itu juga.');
        $this->assertSame(2000, $rekap['profit'], 'Laba cuma marginnya, bukan 7.000.');
        $this->assertSame(5000, app(ConsignmentService::class)->utang($penitip));
    }

    public function test_mode_persen_membulatkan_jatah_cucian_bukan_jatah_penitip(): void
    {
        // 20% dari 7.500 = 1.500 -> penitip 6.000. Angka ganjil sengaja dipilih
        // supaya pembulatan benar-benar diuji, bukan kebetulan bulat.
        $penitip = Consignor::create(['name' => 'Pak Budi', 'share_mode' => 'persen', 'share_percent' => 20]);
        $p = Product::create(['name' => 'Keripik', 'type' => 'makanan', 'price' => 7500,
            'stock' => 0, 'is_active' => true, 'consignor_id' => $penitip->id]);
        app(ConsignmentService::class)->terima($p, 3);

        $this->jual($p, 2);

        $this->assertSame(12000, app(ConsignmentService::class)->utang($penitip));
        $this->assertSame(3000, app(BookkeepingService::class)->dailyRecap(now()->toDateString())['profit']);
    }

    /** Setoran mengurangi kas, TIDAK mengurangi laba untuk kedua kalinya. */
    public function test_setoran_mengurangi_kas_tapi_bukan_laba(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 10);
        $this->jual($p, 1);

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/consignors/'.$penitip->id.'/payouts', ['amount' => 5000])
            ->assertStatus(201)
            ->assertJsonPath('meta.sisa_utang', 0);

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertSame(5000, $rekap['expenses'], 'Uangnya memang keluar dari laci.');
        $this->assertSame(5000, $rekap['consignor_payout']);
        $this->assertSame(2000, $rekap['profit'], 'Laba tetap 2.000 — bukan -3.000.');
    }

    public function test_setoran_tidak_boleh_melebihi_utang(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 10);
        $this->jual($p, 1);

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/consignors/'.$penitip->id.'/payouts', ['amount' => 6000])
            ->assertStatus(422);

        $this->assertSame(5000, app(ConsignmentService::class)->utang($penitip));
    }

    /** Penjualan dibatalkan = utangnya ikut batal, bukan tertinggal. */
    public function test_penjualan_dibatalkan_menghapus_utang_ke_penitip(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 10);
        $this->jual($p, 1);

        $sale = \App\Models\FnbSale::latest('id')->first();
        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/fnb-sales/'.$sale->id.'/void', ['reason' => 'salah input'])
            ->assertOk();

        $this->assertSame(0, app(ConsignmentService::class)->utang($penitip));
        $this->assertSame(10, $p->refresh()->stock, 'Barangnya kembali ke rak.');
        $this->assertSame(0, app(BookkeepingService::class)->dailyRecap(now()->toDateString())['consignor_share']);
    }

    /** Harga setor naik besok tidak boleh mengubah utang yang sudah lahir. */
    public function test_hak_penitip_dibekukan_saat_penjualan(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 10);
        $this->jual($p, 1);

        $p->update(['payout_price' => 6500]);   // negosiasi ulang

        $this->assertSame(5000, app(ConsignmentService::class)->utang($penitip),
            'Utang yang sudah terjadi tidak boleh ikut bergerak.');
    }

    public function test_harga_setor_tidak_boleh_melebihi_harga_jual(): void
    {
        $penitip = $this->penitipSetor();

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/products', ['name' => 'Rugi', 'type' => 'makanan',
                'price' => 5000, 'payout_price' => 6000, 'consignor_id' => $penitip->id])
            ->assertStatus(422);
    }

    public function test_stok_titipan_tidak_bisa_diketik_langsung(): void
    {
        $p = $this->barang($this->penitipSetor());

        $this->withHeaders($this->ownerHeader())
            ->patchJson('/api/products/'.$p->id, ['stock' => 99])
            ->assertStatus(422);

        $this->assertSame(0, $p->refresh()->stock);

        // Barang milik cucian sendiri tetap boleh diketik seperti biasa.
        $sendiri = Product::create(['name' => 'Kopi', 'type' => 'minuman', 'price' => 5000, 'stock' => 1]);
        $this->withHeaders($this->ownerHeader())
            ->patchJson('/api/products/'.$sendiri->id, ['stock' => 99])
            ->assertOk();
    }

    public function test_penitip_tidak_bisa_dihapus_selagi_punya_utang_atau_barang(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 4);
        $this->jual($p, 1);

        $this->deleteJson('/api/consignors/'.$penitip->id, [], $this->ownerHeader())
            ->assertStatus(422);   // masih ada utang

        app(ConsignmentService::class)->bayar($penitip, 5000);

        $this->deleteJson('/api/consignors/'.$penitip->id, [], $this->ownerHeader())
            ->assertStatus(422);   // utang lunas, tapi barangnya masih di rak

        app(ConsignmentService::class)->retur($p->refresh(), 3);

        $this->deleteJson('/api/consignors/'.$penitip->id, [], $this->ownerHeader())->assertOk();
    }

    public function test_rekap_mencocokkan_masuk_laku_retur_dan_sisa(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);
        app(ConsignmentService::class)->terima($p, 20);
        $this->jual($p, 15);
        app(ConsignmentService::class)->retur($p->refresh(), 3);

        $rekap = $this->getJson('/api/consignors/'.$penitip->id, $this->ownerHeader())
            ->assertOk()->json('data');

        $baris = $rekap['items'][0];
        $this->assertSame(20, $baris['masuk']);
        $this->assertSame(15, $baris['terjual']);
        $this->assertSame(3, $baris['retur']);
        $this->assertSame(2, $baris['sisa_seharusnya']);
        $this->assertSame(2, $baris['sisa_di_rak']);
        $this->assertSame(75000, $rekap['hak_penitip']);      // 15 x 5.000
        $this->assertSame(30000, $rekap['bagian_cucian']);    // 15 x 2.000
    }

    public function test_kasir_tidak_boleh_menyentuh_titip_jual(): void
    {
        $penitip = $this->penitipSetor();
        $p = $this->barang($penitip);

        $this->getJson('/api/consignors', $this->kasirHeader())->assertStatus(403);
        $this->postJson('/api/consignors/'.$penitip->id.'/payouts', ['amount' => 1000], $this->kasirHeader())
            ->assertStatus(403);
        $this->postJson('/api/consignment-movements',
            ['product_id' => $p->id, 'type' => 'masuk', 'qty' => 5], $this->kasirHeader())
            ->assertStatus(403);
    }

    /** Barang sendiri tidak boleh ikut terseret jadi utang ke siapa pun. */
    public function test_barang_milik_sendiri_tidak_terpengaruh(): void
    {
        $p = Product::create(['name' => 'Kopi', 'type' => 'minuman', 'price' => 5000, 'stock' => 10]);

        $this->jual($p, 2);

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertSame(0, $rekap['consignor_share']);
        $this->assertSame(10000, $rekap['profit']);
    }
}

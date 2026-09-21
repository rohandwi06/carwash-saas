<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\FnbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tip pada penjualan makanan & minuman.
 *
 * Aturannya dibuat sama persis dengan tip transaksi cuci yang sudah ada:
 *  - tip DI LUAR total (total = omzet menu), jadi omzet F&B tidak
 *    menggelembung karena tip;
 *  - tip ikut baris "Tip" di rekap dan ikut laba bersih;
 *  - tip TIDAK ikut uang yang wajib disetor kasir (cashAmount), sama seperti
 *    tip cuci.
 *
 * Yang paling penting dijaga: laporan harian, laporan rentang, dan statistik
 * Dashboard memakai tiga jalur hitung berbeda — semuanya harus menyebut tip
 * yang sama, kalau tidak owner melihat angka berbeda di tiap layar.
 */
class FnbTipTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function produk(int $stock = 10, int $price = 5000): Product
    {
        return Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => $price, 'stock' => $stock, 'is_active' => true,
        ]);
    }

    private function rekap(): array
    {
        return app(BookkeepingService::class)->dailyRecap(now()->toDateString());
    }

    public function test_tip_tersimpan_dan_tidak_masuk_total(): void
    {
        $p = $this->produk(price: 5000);

        $sale = $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 2]],
            'tip' => 3000,
        ], $this->header())->assertCreated()->json('data');

        $this->assertSame(10000, $sale['total'], 'Total harus tetap harga menu saja.');
        $this->assertSame(3000, $sale['tip']);
    }

    public function test_tip_masuk_baris_tip_dan_laba_bukan_omzet_fnb(): void
    {
        $p = $this->produk(price: 5000);

        $sebelum = $this->rekap();

        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 2]],
            'tip' => 3000,
        ], $this->header())->assertCreated();

        $sesudah = $this->rekap();

        $this->assertSame($sebelum['fnb_total'] + 10000, $sesudah['fnb_total'],
            'Omzet F&B hanya bertambah sebesar harga menu.');
        $this->assertSame($sebelum['tip'] + 3000, $sesudah['tip'],
            'Tip harus masuk baris Tip.');
        $this->assertSame($sebelum['profit'] + 13000, $sesudah['profit'],
            'Laba bertambah sebesar menu + tip.');
    }

    public function test_tip_boleh_kosong(): void
    {
        $p = $this->produk();

        $sale = $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
        ], $this->header())->assertCreated()->json('data');

        $this->assertSame(0, $sale['tip']);
        $this->assertSame(0, $this->rekap()['tip']);
    }

    public function test_tip_minus_ditolak(): void
    {
        $p = $this->produk();

        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'tip' => -5000,
        ], $this->header())->assertStatus(422);
    }

    /** Laporan rentang memakai jalur SQL sendiri — harus setuju dengan harian. */
    public function test_laporan_rentang_setuju_dengan_harian(): void
    {
        $p = $this->produk();
        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'tip' => 4000,
        ], $this->header())->assertCreated();

        $tgl     = now()->toDateString();
        $harian  = $this->rekap();
        $rentang = app(BookkeepingService::class)->dateRange($tgl, $tgl);

        $baris = collect($rentang['days'] ?? $rentang['by_date'] ?? [])
            ->firstWhere('date', $tgl);

        $this->assertNotNull($baris, 'Baris tanggal harus ada di laporan rentang.');
        $this->assertSame($harian['tip'], (int) $baris['tip'],
            'Tip di laporan rentang harus sama dengan laporan harian.');
        $this->assertSame($harian['profit'], (int) $baris['profit']);
    }

    /** Tip tidak ikut uang yang wajib disetor kasir — sama seperti tip cuci. */
    public function test_tip_tidak_menambah_setoran_kas(): void
    {
        $p = $this->produk();
        $buku  = app(\App\Services\CashBookService::class)->current();
        $awal  = app(\App\Services\CashBookService::class)->cashAmount($buku);

        app(FnbService::class)->create([
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'tip' => 7000,
            'book_id' => $buku->id,
        ]);

        $akhir = app(\App\Services\CashBookService::class)->cashAmount($buku);
        $this->assertSame($awal + 5000, $akhir,
            'Setoran hanya bertambah sebesar harga menu, tanpa tip.');
    }

    /** Penjualan yang dibatalkan tidak boleh menyisakan tipnya di rekap. */
    public function test_tip_ikut_gugur_saat_penjualan_dibatalkan(): void
    {
        $p = $this->produk();
        $sale = app(FnbService::class)->create([
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'tip' => 6000,
        ]);

        $this->assertSame(6000, $this->rekap()['tip']);

        app(FnbService::class)->void($sale, 'salah input', 'owner');

        $this->assertSame(0, $this->rekap()['tip'],
            'Tip penjualan yang dibatalkan harus ikut keluar dari rekap.');
    }
}

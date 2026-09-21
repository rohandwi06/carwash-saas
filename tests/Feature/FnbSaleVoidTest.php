<?php

namespace Tests\Feature;

use App\Models\FnbSale;
use App\Models\Product;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\CashBookService;
use App\Services\FnbService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembatalan penjualan makanan & minuman itu sendiri (pembatalan transaksi
 * cuci yang MEMBAWA F&B dijaga terpisah di FnbVoidTest).
 *
 * Aturan yang dikunci di sini sama dengan pembatalan transaksi cuci:
 *  - kasir hanya MENGAJUKAN; uangnya tetap dihitung sampai owner memutuskan,
 *    supaya angka harian tidak bisa diubah sepihak;
 *  - owner membatalkan langsung, stok menu kembali, barisnya tidak dihapus.
 */
class FnbSaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
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

    private function jual(Product $p, int $qty = 2): FnbSale
    {
        return app(FnbService::class)->create([
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => $qty]],
        ]);
    }

    private function omzetFnbHariIni(): int
    {
        return (int) app(BookkeepingService::class)
            ->dailyRecap(now()->toDateString())['fnb_total'];
    }

    public function test_owner_membatalkan_langsung_stok_kembali(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);
        $this->assertSame(8, $p->fresh()->stock);

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            ['reason' => 'salah input'], $this->header('owner'))->assertOk();

        $this->assertSame(10, $p->fresh()->stock, 'Stok harus kembali setelah dibatalkan.');
        $this->assertNotNull($sale->fresh()->voided_at);
        // Barisnya TIDAK dihapus — jejaknya tetap ada.
        $this->assertDatabaseHas('fnb_sales', ['id' => $sale->id]);
    }

    public function test_kasir_hanya_mengajukan_uang_masih_dihitung(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);
        $omzetAwal = $this->omzetFnbHariIni();

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            ['reason' => 'pelanggan batal'], $this->header('kasir'))->assertOk();

        $sale->refresh();
        $this->assertNull($sale->voided_at, 'Pengajuan kasir belum boleh membatalkan.');
        $this->assertSame('menunggu', $sale->void_status);
        $this->assertSame(8, $p->fresh()->stock, 'Stok belum boleh kembali sebelum owner setuju.');
        $this->assertSame($omzetAwal, $this->omzetFnbHariIni(),
            'Uang harus tetap dihitung sampai owner memutuskan.');
    }

    public function test_owner_menyetujui_pengajuan_kasir(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);
        $omzetAwal = $this->omzetFnbHariIni();

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            ['reason' => 'pelanggan batal'], $this->header('kasir'))->assertOk();
        $this->postJson('/api/fnb-sales/'.$sale->id.'/void/approve',
            [], $this->header('owner'))->assertOk();

        $this->assertNotNull($sale->fresh()->voided_at);
        $this->assertSame(10, $p->fresh()->stock);
        $this->assertSame($omzetAwal - 10000, $this->omzetFnbHariIni(),
            'Setelah disetujui, uangnya keluar dari rekap.');
    }

    public function test_owner_menolak_penjualan_tetap_sah(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            ['reason' => 'salah'], $this->header('kasir'))->assertOk();
        $this->postJson('/api/fnb-sales/'.$sale->id.'/void/reject',
            ['reason' => 'tidak terbukti'], $this->header('owner'))->assertOk();

        $sale->refresh();
        $this->assertSame('ditolak', $sale->void_status);
        $this->assertNull($sale->voided_at);
        $this->assertSame(8, $p->fresh()->stock);
        // Jejak pengajuan tetap disimpan, bukan dihapus.
        $this->assertNotNull($sale->void_requested_at);
    }

    public function test_void_dua_kali_tidak_menggandakan_stok(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);

        app(FnbService::class)->void($sale, 'sekali', 'owner');
        app(FnbService::class)->void($sale->fresh(), 'dua kali', 'owner');

        $this->assertSame(10, $p->fresh()->stock, 'Stok tidak boleh bertambah dua kali.');
    }

    public function test_kasir_tidak_bisa_menyetujui_atau_menolak(): void
    {
        $p = $this->produk();
        $sale = $this->jual($p);

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            ['reason' => 'x'], $this->header('kasir'))->assertOk();

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void/approve',
            [], $this->header('kasir'))->assertStatus(403);
        $this->postJson('/api/fnb-sales/'.$sale->id.'/void/reject',
            ['reason' => 'y'], $this->header('kasir'))->assertStatus(403);
    }

    public function test_alasan_wajib_diisi(): void
    {
        $p = $this->produk();
        $sale = $this->jual($p);

        $this->postJson('/api/fnb-sales/'.$sale->id.'/void',
            [], $this->header('owner'))->assertStatus(422);
    }

    /**
     * Pesanan yang menempel pada cucian ikut membawa data transaksinya, supaya
     * kasir bisa diperingatkan sebelum membatalkan sepihak.
     */
    public function test_pesanan_yang_menempel_membawa_data_cucian(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashService::firstOrCreate(['slug' => 'reguler'], ['label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::updateOrCreate(
            ['category_slug' => 'kecil', 'service_slug' => 'reguler'],
            ['price' => 50000],
        );

        $p = $this->produk(stock: 10);
        app(TransactionService::class)->create([
            'vehicle_name' => 'Avanza', 'category' => 'kecil', 'service' => 'reguler',
            'payment_method' => 'cash', 'plate' => 'B1234XY',
            'fnb_items' => [['product_id' => $p->id, 'qty' => 1]],
        ]);

        $baris = $this->getJson('/api/fnb-sales?date='.now()->toDateString(), $this->header('kasir'))
            ->assertOk()->json('data.0');

        $this->assertSame('B1234XY', $baris['transaction']['plate']);
        $this->assertSame('Avanza', $baris['transaction']['vehicle_name']);

        // Penjualan berdiri sendiri tidak membawa apa-apa untuk diperingatkan.
        $this->jual($p, 1);
        $mandiri = collect($this->getJson('/api/fnb-sales?date='.now()->toDateString(), $this->header('kasir'))
            ->json('data'))->firstWhere('transaction_id', null);
        $this->assertNull($mandiri['transaction']);
    }

    /** Uang yang wajib disetor kasir ikut berkurang saat penjualan dibatalkan. */
    public function test_setoran_kas_ikut_berkurang(): void
    {
        $p = $this->produk(stock: 10);
        $sale = $this->jual($p, 2);   // cash 10.000

        $buku = app(CashBookService::class)->current();
        $sebelum = app(CashBookService::class)->cashAmount($buku);

        app(FnbService::class)->void($sale, 'salah input', 'owner');

        $sesudah = app(CashBookService::class)->cashAmount($buku);
        $this->assertSame($sebelum - 10000, $sesudah,
            'Kasir tidak boleh diminta menyetor uang penjualan yang dibatalkan.');
    }
}

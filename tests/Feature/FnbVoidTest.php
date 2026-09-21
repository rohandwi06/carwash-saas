<?php

namespace Tests\Feature;

use App\Models\FnbSale;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\BookkeepingService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembatalan transaksi cuci yang membawa pesanan makanan/minuman.
 *
 * Dua hal yang dulu bocor dan dijaga di sini:
 *  1. stok menu tetap berkurang walau transaksinya dibatalkan;
 *  2. uang F&B-nya tetap ikut terhitung di rekap, padahal cuciannya sudah
 *     dikeluarkan — jadi omzet & laba tercatat lebih besar dari kenyataan.
 */
class FnbVoidTest extends TestCase
{
    use RefreshDatabase;

    /** Migrasi sudah mengisi katalog bawaan — cukup pastikan harganya pasti. */
    private function siapkanKatalogCuci(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashService::firstOrCreate(['slug' => 'reguler'], ['label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::updateOrCreate(
            ['category_slug' => 'kecil', 'service_slug' => 'reguler'],
            ['price' => 50000],
        );
    }

    private function buatTransaksiDenganFnb(Product $produk, int $qty = 3): Transaction
    {
        return app(TransactionService::class)->create([
            'vehicle_name'   => 'Avanza',
            'category'       => 'kecil',
            'service'        => 'reguler',
            'payment_method' => 'cash',
            'fnb_items'      => [['product_id' => $produk->id, 'qty' => $qty]],
        ]);
    }

    public function test_stok_fnb_kembali_saat_transaksi_dibatalkan(): void
    {
        $this->siapkanKatalogCuci();
        $produk = Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => 5000, 'stock' => 10, 'is_active' => true,
        ]);

        $transaksi = $this->buatTransaksiDenganFnb($produk, 3);

        // Terjual dulu: stok memang harus berkurang.
        $this->assertSame(7, $produk->fresh()->stock);

        app(TransactionService::class)->void($transaksi, 'salah input', 'owner');

        $this->assertSame(10, $produk->fresh()->stock, 'Stok F&B harus kembali setelah transaksi dibatalkan.');
    }

    public function test_uang_fnb_keluar_dari_rekap_saat_transaksi_dibatalkan(): void
    {
        $this->siapkanKatalogCuci();
        $produk = Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => 5000, 'stock' => 10, 'is_active' => true,
        ]);

        $transaksi = $this->buatTransaksiDenganFnb($produk, 3);
        $tanggal   = now()->toDateString();

        $sebelum = app(BookkeepingService::class)->dailyRecap($tanggal);
        $this->assertSame(15000, $sebelum['fnb_total']);
        $this->assertSame(50000, $sebelum['total']);

        app(TransactionService::class)->void($transaksi, 'salah input', 'owner');

        $sesudah = app(BookkeepingService::class)->dailyRecap($tanggal);
        $this->assertSame(0, $sesudah['fnb_total'], 'F&B dari transaksi batal tidak boleh ikut dihitung.');
        $this->assertSame(0, $sesudah['total']);
        $this->assertSame(0, $sesudah['profit'], 'Laba tidak boleh menyisakan uang F&B yang sudah batal.');
    }

    public function test_penjualan_fnb_berdiri_sendiri_tidak_ikut_terpengaruh(): void
    {
        $this->siapkanKatalogCuci();
        $produk = Product::create([
            'name' => 'Kopi', 'type' => 'minuman',
            'price' => 8000, 'stock' => 10, 'is_active' => true,
        ]);

        // Penjualan tanpa transaksi cuci (transaction_id NULL).
        app(\App\Services\FnbService::class)->create([
            'payment_method' => 'cash',
            'items'          => [['product_id' => $produk->id, 'qty' => 2]],
        ]);

        // Transaksi cuci lain yang dibatalkan tidak boleh menyeret penjualan ini.
        $transaksi = $this->buatTransaksiDenganFnb($produk, 1);
        app(TransactionService::class)->void($transaksi, 'salah input', 'owner');

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertSame(16000, $rekap['fnb_total'], 'Penjualan F&B mandiri harus tetap terhitung.');
        $this->assertSame(8, $produk->fresh()->stock, 'Hanya stok dari transaksi yang batal yang dikembalikan.');
    }

    public function test_void_dua_kali_tidak_menggandakan_stok(): void
    {
        $this->siapkanKatalogCuci();
        $produk = Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => 5000, 'stock' => 10, 'is_active' => true,
        ]);

        $transaksi = $this->buatTransaksiDenganFnb($produk, 3);

        $service = app(TransactionService::class);
        $service->void($transaksi, 'salah input', 'owner');
        $service->void($transaksi->fresh(), 'salah input lagi', 'owner');

        $this->assertSame(10, $produk->fresh()->stock, 'Void berulang tidak boleh menambah stok dua kali.');
    }

    public function test_item_lama_tanpa_product_id_dilewati_bukan_menebak(): void
    {
        $this->siapkanKatalogCuci();
        $produk = Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => 5000, 'stock' => 10, 'is_active' => true,
        ]);

        $transaksi = $this->buatTransaksiDenganFnb($produk, 3);

        // Tiru baris warisan: dibuat sebelum kolom product_id ada.
        FnbSale::where('transaction_id', $transaksi->id)
            ->first()->items()->update(['product_id' => null]);

        app(TransactionService::class)->void($transaksi, 'salah input', 'owner');

        $this->assertSame(7, $produk->fresh()->stock, 'Tanpa rujukan produk, stok dibiarkan apa adanya.');
    }
}

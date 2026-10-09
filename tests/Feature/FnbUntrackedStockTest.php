<?php

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\FnbSale;
use App\Models\Product;
use App\Services\AuthTokenService;
use App\Services\FnbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu tanpa stok — dibuat saat dipesan (kopi tubruk, teh).
 *
 * Yang dijaga:
 *  - bisa dijual berapa pun walau angka stoknya 0, dan stoknya tidak bergerak;
 *  - membatalkan penjualannya tidak menumbuhkan stok dari nol;
 *  - menu berstok tetap seperti dulu (bawaan), termasuk penolakan stok kurang;
 *  - barang titipan tidak bisa dijadikan tanpa stok.
 */
class FnbUntrackedStockTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function jual(Product $p, int $qty)
    {
        return $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $p->id, 'qty' => $qty]],
        ], $this->header('kasir'));
    }

    public function test_menu_baru_bisa_dibuat_tanpa_stok(): void
    {
        $p = $this->postJson('/api/products', [
            'name' => 'Kopi Tubruk', 'type' => 'minuman', 'price' => 5000,
            'stock' => 25, 'track_stock' => false,
        ], $this->header())->assertCreated()->json('data');

        $this->assertFalse($p['track_stock']);
        $this->assertSame(0, $p['stock'], 'Angka stok tidak dipakai, jadi dinolkan.');
    }

    public function test_bawaan_tetap_berstok(): void
    {
        $p = $this->postJson('/api/products', [
            'name' => 'Aqua', 'type' => 'minuman', 'price' => 3000, 'stock' => 2,
        ], $this->header())->assertCreated()->json('data');

        $this->assertTrue($p['track_stock']);
        $this->jual(Product::find($p['id']), 3)->assertStatus(422);
    }

    public function test_tanpa_stok_dijual_berapa_pun_dan_stok_tidak_bergerak(): void
    {
        $p = Product::create(['name' => 'Teh', 'type' => 'minuman', 'price' => 4000,
            'stock' => 0, 'track_stock' => false, 'is_active' => true]);

        $this->jual($p, 30)->assertCreated()->assertJsonPath('data.total', 120000);

        $this->assertSame(0, $p->fresh()->stock);
    }

    public function test_batal_tidak_menumbuhkan_stok(): void
    {
        $p = Product::create(['name' => 'Teh', 'type' => 'minuman', 'price' => 4000,
            'stock' => 0, 'track_stock' => false, 'is_active' => true]);
        $this->jual($p, 5)->assertCreated();

        app(FnbService::class)->void(FnbSale::first(), 'salah input', 'Owner Uji');

        $this->assertSame(0, $p->fresh()->stock);
    }

    public function test_menu_berstok_tetap_dipotong_dan_dikembalikan(): void
    {
        $p = Product::create(['name' => 'Aqua', 'type' => 'minuman', 'price' => 3000,
            'stock' => 10, 'is_active' => true]);
        $this->jual($p, 4)->assertCreated();
        $this->assertSame(6, $p->fresh()->stock);

        app(FnbService::class)->void(FnbSale::first(), 'salah input', 'Owner Uji');
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_beralih_ke_tanpa_stok_menolkan_stok_dan_bisa_kembali(): void
    {
        $p = Product::create(['name' => 'Kopi', 'type' => 'minuman', 'price' => 5000,
            'stock' => 7, 'is_active' => true]);

        $this->patchJson("/api/products/{$p->id}", ['track_stock' => false], $this->header())
            ->assertOk()->assertJsonPath('data.track_stock', false)->assertJsonPath('data.stock', 0);

        $this->patchJson("/api/products/{$p->id}", ['track_stock' => true, 'stock' => 12], $this->header())
            ->assertOk()->assertJsonPath('data.track_stock', true)->assertJsonPath('data.stock', 12);
    }

    public function test_barang_titipan_tidak_boleh_tanpa_stok(): void
    {
        $penitip = Consignor::create(['name' => 'Bu Sri', 'share_mode' => 'setor']);
        $p = Product::create(['name' => 'Kue', 'type' => 'makanan', 'price' => 5000, 'stock' => 0,
            'is_active' => true, 'consignor_id' => $penitip->id, 'payout_price' => 4000]);

        $this->patchJson("/api/products/{$p->id}", ['track_stock' => false], $this->header())
            ->assertStatus(422);
        $this->assertTrue($p->fresh()->track_stock);
    }
}

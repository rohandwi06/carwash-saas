<?php

namespace Tests\Feature;

use App\Models\FnbDraft;
use App\Models\Product;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Draft makanan & minuman: pesanan dicatat dulu, dibayar belakangan.
 *
 * Yang dijaga di sini adalah janji-janji yang membedakan draft dari penjualan:
 *  1. draft TIDAK memotong stok (barangnya belum benar-benar keluar);
 *  2. draft TIDAK menyimpan harga — harga baru dikunci server saat draft jadi
 *     penjualan, jadi draft lama tidak bisa membekukan harga kedaluwarsa;
 *  3. draft yang sudah jadi penjualan ikut terhapus, tidak tertinggal ganda.
 */
class FnbDraftTest extends TestCase
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

    public function test_draft_tidak_memotong_stok(): void
    {
        $p = $this->produk(stock: 10);

        $this->postJson('/api/fnb-drafts', [
            'label' => 'Meja 3',
            'items' => [['product_id' => $p->id, 'qty' => 4]],
        ], $this->header())->assertCreated();

        $this->assertSame(10, $p->fresh()->stock,
            'Draft belum penjualan — stok tidak boleh berkurang.');
    }

    public function test_draft_tidak_menyimpan_harga(): void
    {
        $p = $this->produk(price: 5000);

        $r = $this->postJson('/api/fnb-drafts', [
            'items' => [['product_id' => $p->id, 'qty' => 2]],
        ], $this->header())->assertCreated();

        $item = $r->json('data.items.0');
        $this->assertArrayNotHasKey('price', $item);
        $this->assertArrayNotHasKey('subtotal', $item);
        $this->assertSame([$p->id, 2], [$item['product_id'], $item['qty']]);
    }

    public function test_draft_terhapus_saat_jadi_penjualan(): void
    {
        $p = $this->produk(stock: 10);

        $draft = FnbDraft::create([
            'items' => [['product_id' => $p->id, 'qty' => 3]],
            'date'  => now()->toDateString(),
        ]);

        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items'          => [['product_id' => $p->id, 'qty' => 3]],
            'draft_id'       => $draft->id,
        ], $this->header())->assertCreated();

        $this->assertDatabaseMissing('fnb_drafts', ['id' => $draft->id]);
        // Baru DI SINI stok berkurang, sekali saja.
        $this->assertSame(7, $p->fresh()->stock);
    }

    public function test_penjualan_tanpa_draft_id_tidak_menghapus_draft_lain(): void
    {
        $p = $this->produk(stock: 10);
        $draft = FnbDraft::create([
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'date'  => now()->toDateString(),
        ]);

        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash',
            'items'          => [['product_id' => $p->id, 'qty' => 1]],
        ], $this->header())->assertCreated();

        $this->assertDatabaseHas('fnb_drafts', ['id' => $draft->id]);
    }

    public function test_draft_bisa_diperbarui_bukan_jadi_baris_kedua(): void
    {
        $p = $this->produk();
        $draft = FnbDraft::create([
            'label' => 'Meja 1',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'date'  => now()->toDateString(),
        ]);

        $this->patchJson('/api/fnb-drafts/'.$draft->id, [
            'label' => 'Meja 2',
            'items' => [['product_id' => $p->id, 'qty' => 5]],
        ], $this->header())->assertOk();

        $this->assertSame(1, FnbDraft::count(), 'Memperbarui draft tidak boleh membuat baris baru.');
        $this->assertSame('Meja 2', $draft->fresh()->label);
        $this->assertSame(5, $draft->fresh()->items[0]['qty']);
    }

    public function test_draft_menolak_produk_yang_tidak_ada(): void
    {
        $this->postJson('/api/fnb-drafts', [
            'items' => [['product_id' => 999999, 'qty' => 1]],
        ], $this->header())->assertStatus(422);
    }

    public function test_draft_wajib_login(): void
    {
        $this->postJson('/api/fnb-drafts', ['items' => []])->assertStatus(401);
    }

    /** Dashboard kini owner-only — kasir tidak boleh melihat laba & upah. */
    public function test_statistik_dashboard_ditolak_untuk_kasir(): void
    {
        $this->getJson('/api/reports/stats?period=harian', $this->header('kasir'))
            ->assertStatus(403);

        $this->getJson('/api/reports/stats?period=harian', $this->header('owner'))
            ->assertOk();
    }
}

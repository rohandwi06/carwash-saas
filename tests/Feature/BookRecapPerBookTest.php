<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembukuan merinci tiap buku kas: cuci, tip, F&B, upah, pengeluaran.
 * Angka per buku dikirim server sekali jalan (books[]) — yang dijaga di sini:
 * upah & pengeluaran buku ikut terkirim, dan untuk hari berbuku tunggal
 * jumlahnya sama dengan angka sehari penuh.
 */
class BookRecapPerBookTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_buku_membawa_upah_dan_pengeluarannya(): void
    {
        $p = Product::create([
            'name' => 'Kopi', 'type' => 'minuman', 'price' => 6000, 'stock' => 10, 'is_active' => true,
        ]);
        $this->postJson('/api/fnb-sales', [
            'payment_method' => 'cash', 'items' => [['product_id' => $p->id, 'qty' => 1]],
        ], $this->header())->assertCreated();
        $this->postJson('/api/expenses', ['description' => 'Sabun', 'amount' => 7000], $this->header())
            ->assertCreated();

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertCount(1, $rekap['books']);
        $buku = $rekap['books'][0];
        $this->assertSame(6000, $buku['fnb_total']);
        $this->assertSame(7000, $buku['expenses']);
        $this->assertSame($rekap['expenses'], $buku['expenses'], 'Buku tunggal = pengeluaran sehari.');
        $this->assertSame($rekap['wages'], $buku['wages'], 'Buku tunggal = upah sehari.');
    }
}

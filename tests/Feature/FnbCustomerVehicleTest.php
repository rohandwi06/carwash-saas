<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\CashBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jajanan yang dibayar terpisah lewat menu Jual F&B menunjuk mobil pembelinya
 * (customer_transaction_id), supaya tiap baris F&B & tip di laporan punya plat.
 *
 * Yang dijaga: tautan ini HANYA penanda. Beda dengan transaction_id (satu
 * resi), membatalkan cuciannya tidak menggugurkan jajanan yang sudah lunas.
 */
class FnbCustomerVehicleTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function produk(): Product
    {
        return Product::create([
            'name' => 'Kopi', 'type' => 'minuman',
            'price' => 6000, 'stock' => 10, 'is_active' => true,
        ]);
    }

    private function cucian(array $ubah = []): Transaction
    {
        return Transaction::create($ubah + [
            'queue_no' => 1, 'vehicle_name' => 'Isuzu Panther', 'category' => 'mobil',
            'service' => 'reguler', 'payment_method' => 'cash', 'plate' => 'B 1234 XY',
            'tip' => 0, 'total' => 50000, 'date' => now()->toDateString(),
            'book_id' => app(CashBookService::class)->current()->id,
        ]);
    }

    private function jual(array $tambah = [])
    {
        return $this->postJson('/api/fnb-sales', $tambah + [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->produk()->id, 'qty' => 2]],
        ], $this->header());
    }

    public function test_plat_pembeli_ikut_di_daftar_penjualan(): void
    {
        $mobil = $this->cucian();
        $this->jual(['customer_transaction_id' => $mobil->id, 'tip' => 2000])->assertCreated();

        $sale = $this->getJson('/api/fnb-sales?date='.now()->toDateString(), $this->header())
            ->assertOk()->json('data.0');

        $this->assertNull($sale['transaction_id'], 'Bukan satu resi dengan cuciannya.');
        $this->assertSame('B 1234 XY', $sale['customer_transaction']['plate']);
    }

    public function test_boleh_tanpa_mobil_untuk_aplikasi_lama(): void
    {
        $this->jual()->assertCreated()->assertJsonPath('data.customer_transaction_id', null);
    }

    public function test_mobil_batal_atau_kemarin_ditolak(): void
    {
        $batal = $this->cucian(['voided_at' => now()]);
        $this->jual(['customer_transaction_id' => $batal->id])->assertStatus(422);

        $kemarin = $this->cucian(['date' => now()->subDay()->toDateString()]);
        $this->jual(['customer_transaction_id' => $kemarin->id])->assertStatus(422);
    }

    public function test_membatalkan_cucian_tidak_menggugurkan_jajanan_terpisah(): void
    {
        $mobil = $this->cucian();
        $this->jual(['customer_transaction_id' => $mobil->id])->assertCreated();

        $mobil->update(['voided_at' => now()]);

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());
        $this->assertSame(12000, $rekap['fnb_total'], 'Jajanan sudah lunas sendiri — tetap dihitung.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Expense;
use App\Models\Supply;
use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Barang perlengkapan (permintaan owner 2026-10-21): sabun dalam liter,
 * lap dalam pcs.
 *
 * Yang dijaga:
 *  - stok turun lewat tiga jalan: catat pakai, hitung sisa, takaran per cucian;
 *  - beli dengan harga = sekalian pengeluaran hari ini;
 *  - cucian dibatalkan/dikoreksi = potongannya ikut, kecuali sudah ada opname;
 *  - kasir boleh mencatat tapi tidak boleh mengatur jenis barang.
 */
class SupplyTest extends TestCase
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
        WashCategory::create(['slug' => 'ujimotor', 'label' => 'Motor Uji', 'shape' => 'moto', 'sort_order' => 2]);
        WashService::create(['slug' => 'reguler', 'label' => 'Reguler', 'sort_order' => 1]);
        WashService::create(['slug' => 'komplit', 'label' => 'Komplit', 'sort_order' => 2]);
        foreach (['ujimobil', 'ujimotor'] as $k) {
            foreach (['reguler', 'komplit'] as $l) {
                WashPrice::create(['category_slug' => $k, 'service_slug' => $l, 'price' => 30000]);
            }
        }
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function sabun(array $rules = []): Supply
    {
        $id = $this->postJson('/api/supplies', [
            'name' => 'Sabun', 'unit' => 'liter', 'stock' => 20, 'min_stock' => 5, 'rules' => $rules,
        ], $this->header())->assertCreated()->json('data.id');

        return Supply::findOrFail($id);
    }

    private function cuci(string $kat = 'ujimobil', string $svc = 'reguler', array $addons = []): Transaction
    {
        $id = $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => $kat, 'service' => $svc,
            'payment_method' => 'cash', 'addon_ids' => $addons,
        ], $this->header('kasir'))->assertCreated()->json('data.id');

        return Transaction::findOrFail($id);
    }

    public function test_pakai_beli_dan_hitung_sisa(): void
    {
        $s = $this->sabun();
        $this->assertSame(20.0, $s->stock);
        $this->assertSame('hitung', $s->movements()->first()->kind, 'Stok awal tercatat di riwayat.');

        $this->postJson("/api/supplies/{$s->id}/use", ['qty' => 1.5], $this->header('kasir'))
            ->assertOk()->assertJsonPath('data.stock', 18.5);

        $this->postJson("/api/supplies/{$s->id}/count", ['stock' => 3.25], $this->header('kasir'))
            ->assertOk()->assertJsonPath('data.stock', 3.25)->assertJsonPath('data.is_low', true);
        $this->assertSame(-15.25, $s->movements()->latest('id')->first()->qty);

        $this->postJson("/api/supplies/{$s->id}/purchase", ['qty' => 20, 'price' => 180000], $this->header('kasir'))
            ->assertOk()->assertJsonPath('data.stock', 23.25)->assertJsonPath('data.is_low', false);

        $e = Expense::firstOrFail();
        $this->assertSame(180000, $e->amount);
        $this->assertSame('Beli Sabun 20 liter', $e->description);
        $this->assertNotNull($e->book_id, 'Masuk buku kas yang sedang terbuka.');
    }

    public function test_beli_tanpa_harga_tidak_jadi_pengeluaran(): void
    {
        $s = $this->sabun();
        $this->postJson("/api/supplies/{$s->id}/purchase", ['qty' => 5], $this->header('kasir'))->assertOk();
        $this->assertSame(0, Expense::count());
    }

    public function test_takaran_otomatis_per_cucian(): void
    {
        $semir = Addon::create(['name' => 'Semir', 'price' => 5000, 'is_active' => true]);
        $s = $this->sabun([
            ['qty' => 0.05, 'category_slug' => 'ujimobil'],
            ['qty' => 0.02, 'category_slug' => 'ujimotor'],
            ['qty' => 0.03, 'service_slug' => 'komplit'],
            ['qty' => 0.1, 'addon_id' => $semir->id],
        ]);

        $this->cuci('ujimobil');                         // 0,05
        $this->cuci('ujimotor', 'komplit');              // 0,02 + 0,03
        $this->cuci('ujimobil', 'reguler', [$semir->id]); // 0,05 + 0,1

        $this->assertEqualsWithDelta(19.75, $s->fresh()->stock, 0.0001);

        $riwayat = $this->getJson("/api/supplies/{$s->id}/movements", $this->header('kasir'))
            ->assertOk()->json('data');
        $this->assertSame('cuci', $riwayat[0]['kind'], 'Potongan otomatis digabung per hari.');
        $this->assertSame(3, $riwayat[0]['washes']);
        $this->assertSame(now()->toDateString(), $riwayat[0]['date']);
        $this->assertSame(now()->toDateString(), end($riwayat)['date'], 'Baris manual juga Y-m-d.');
        $this->assertEqualsWithDelta(-0.25, $riwayat[0]['qty'], 0.0001);
    }

    public function test_batal_mengembalikan_kecuali_sudah_dihitung(): void
    {
        $s = $this->sabun([['qty' => 0.5]]);
        $a = $this->cuci();
        $b = $this->cuci();
        $this->assertEqualsWithDelta(19.0, $s->fresh()->stock, 0.0001);

        app(TransactionService::class)->void($a, 'salah input', 'Owner Uji');
        $this->assertEqualsWithDelta(19.5, $s->fresh()->stock, 0.0001);

        // Sesudah opname, sisa sebenarnya sudah memuat cucian b.
        $this->postJson("/api/supplies/{$s->id}/count", ['stock' => 10], $this->header())->assertOk();
        app(TransactionService::class)->void($b, 'salah input', 'Owner Uji');
        $this->assertEqualsWithDelta(10.0, $s->fresh()->stock, 0.0001);
    }

    public function test_koreksi_cucian_menyesuaikan_takaran(): void
    {
        $s = $this->sabun([['qty' => 0.05], ['qty' => 0.2, 'service_slug' => 'komplit']]);
        $t = $this->cuci();
        $this->assertEqualsWithDelta(19.95, $s->fresh()->stock, 0.0001);

        $this->patchJson("/api/transactions/{$t->id}", ['service' => 'komplit', 'edit_reason' => 'salah'], $this->header())
            ->assertOk();
        $this->assertEqualsWithDelta(19.75, $s->fresh()->stock, 0.0001);
        $this->assertSame(1, $s->movements()->where('kind', 'cuci')->count(), 'Tetap satu baris per cucian.');
    }

    public function test_susulan_tidak_memotong(): void
    {
        $s = $this->sabun([['qty' => 0.5]]);
        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDay()->toDateString(), 'book_number' => 1,
            'rows' => [['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash']],
        ], $this->header())->assertCreated();

        $this->assertSame(20.0, $s->fresh()->stock);
    }

    public function test_takaran_bisa_dikosongkan(): void
    {
        $s = $this->sabun([['qty' => 0.5]]);
        $this->patchJson("/api/supplies/{$s->id}", ['name' => 'Sabun', 'rules' => []], $this->header())
            ->assertOk()->assertJsonCount(0, 'data.rules');

        $this->cuci();
        $this->assertSame(20.0, $s->fresh()->stock);
    }

    public function test_kasir_tidak_boleh_mengatur_barang(): void
    {
        $s = $this->sabun();
        $this->postJson('/api/supplies', ['name' => 'Lap', 'unit' => 'pcs'], $this->header('kasir'))->assertForbidden();
        $this->patchJson("/api/supplies/{$s->id}", ['min_stock' => 1], $this->header('kasir'))->assertForbidden();
        $this->deleteJson("/api/supplies/{$s->id}", [], $this->header('kasir'))->assertForbidden();
    }

    public function test_tabel_barang_hilang_tidak_menggagalkan_cucian(): void
    {
        \Illuminate\Support\Facades\Schema::drop('supply_usage_rules');

        $this->cuci();
        $this->assertSame(1, Transaction::count());
    }
}

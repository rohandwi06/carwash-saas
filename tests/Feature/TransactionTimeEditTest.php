<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jam transaksi bisa dikoreksi owner — permintaan owner 2026-10-12 ("di edit
 * pembukuan gak ada edit jam").
 *
 * Yang dijaga: hanya jam-menitnya yang berubah (tanggal dan buku kas tetap),
 * koreksi tanpa jam tidak menggeser waktunya, dan jam yang salah tulis ditolak.
 */
class TransactionTimeEditTest extends TestCase
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
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 30000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 12000]);
    }

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cucian(): Transaction
    {
        $id = $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
        ], $this->header('kasir'))->assertCreated()->json('data.id');

        return Transaction::findOrFail($id);
    }

    public function test_jam_berubah_tanggal_dan_total_tetap(): void
    {
        $this->travelTo(now()->setTime(15, 20, 33));
        $trx = $this->cucian();

        $this->patchJson("/api/transactions/{$trx->id}", ['time' => '08:05', 'edit_reason' => 'salah jam'], $this->header())
            ->assertOk();

        $baru = $trx->fresh();
        $this->assertSame('08:05', $baru->created_at->format('H:i'));
        $this->assertSame($trx->created_at->toDateString(), $baru->created_at->toDateString());
        $this->assertSame($trx->date->toDateString(), $baru->date->toDateString());
        $this->assertSame($trx->book_id, $baru->book_id);
        $this->assertSame(30000, (int) $baru->total);
        $this->assertSame('salah jam', $baru->edit_reason);
    }

    public function test_koreksi_tanpa_jam_tidak_menggeser_waktu(): void
    {
        $this->travelTo(now()->setTime(15, 20, 33));
        $trx = $this->cucian();

        $this->travel(2)->hours();
        $this->patchJson("/api/transactions/{$trx->id}", ['plate' => 'S 1 AB', 'edit_reason' => 'tambah plat'], $this->header())
            ->assertOk();

        $this->assertTrue($trx->created_at->equalTo($trx->fresh()->created_at));
    }

    public function test_jam_salah_tulis_ditolak_dan_kasir_tidak_boleh(): void
    {
        $trx = $this->cucian();

        foreach (['25:00', '8.05', 'pagi'] as $jam) {
            $this->patchJson("/api/transactions/{$trx->id}", ['time' => $jam, 'edit_reason' => 'uji'], $this->header())
                ->assertStatus(422);
        }
        $this->patchJson("/api/transactions/{$trx->id}", ['time' => '08:05', 'edit_reason' => 'uji'], $this->header('kasir'))
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\WashCategory;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom Cash Motor di pembukuan dulu hanya menghitung kategori ber-slug
 * 'motor' — nama bawaan OTIN. Cucian lain menamai jenis kendaraannya
 * sendiri, jadi penentunya sekarang BENTUK kendaraan yang dipilih owner.
 */
class VehicleShapeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function trx(string $kategori, int $total, string $bayar = 'cash'): void
    {
        static $antre = 0;
        Transaction::create([
            'queue_no' => ++$antre, 'vehicle_name' => 'Uji', 'category' => $kategori,
            'service' => 'reguler', 'payment_method' => $bayar, 'total' => $total,
            'date' => now()->toDateString(),
        ]);
    }

    public function test_owner_memilih_bentuk_saat_membuat_jenis_kendaraan(): void
    {
        $this->postJson('/api/wash-categories', [
            'label'  => 'Motor Besar',
            'shape'  => 'moto',
            'prices' => ['reguler' => 20000],
            'wages'  => ['reguler' => 5000],
        ], $this->owner())->assertCreated();

        $this->assertDatabaseHas('wash_categories', ['label' => 'Motor Besar', 'shape' => 'moto']);
    }

    public function test_tanpa_bentuk_tetap_mobil_kecil_seperti_dulu(): void
    {
        $this->postJson('/api/wash-categories', [
            'label'  => 'Pickup',
            'prices' => ['reguler' => 40000],
            'wages'  => ['reguler' => 10000],
        ], $this->owner())->assertCreated();

        $this->assertDatabaseHas('wash_categories', ['label' => 'Pickup', 'shape' => 'hatch']);
    }

    public function test_bentuk_yang_tidak_dikenal_ditolak(): void
    {
        $this->postJson('/api/wash-categories', [
            'label'  => 'Kapal',
            'shape'  => 'kapal',
            'prices' => ['reguler' => 1],
            'wages'  => ['reguler' => 0],
        ], $this->owner())->assertUnprocessable();
    }

    public function test_cash_motor_menghitung_semua_kategori_berbentuk_motor(): void
    {
        WashCategory::create(['slug' => 'motor-besar', 'label' => 'Motor Besar', 'shape' => 'moto']);

        $this->trx('motor', 15000);        // bawaan pemasangan, berbentuk motor
        $this->trx('motor-besar', 20000);  // buatan owner, berbentuk motor
        $this->trx('kecil', 35000);        // mobil
        $this->trx('motor-besar', 20000, 'tf'); // transfer: bukan cash sama sekali

        $r = app(BookkeepingService::class)->dailyRecap(now()->toDateString());

        $this->assertSame(35000, $r['cash_motor']);
        $this->assertSame(35000, $r['cash_mobil']);
    }

    public function test_mengubah_bentuk_memindahkan_kolom_cash(): void
    {
        $motor = WashCategory::where('slug', 'motor')->firstOrFail();
        $this->trx('motor', 15000);

        $this->patchJson('/api/wash-categories/'.$motor->id, [
            'label'  => 'Motor',
            'shape'  => 'hatch',
            'prices' => ['reguler' => 15000],
            'wages'  => ['reguler' => 5000],
        ], $this->owner())->assertOk();

        $r = app(BookkeepingService::class)->dailyRecap(now()->toDateString());
        $this->assertSame(0, $r['cash_motor']);
        $this->assertSame(15000, $r['cash_mobil']);
    }
}

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
 * Detail pelanggan (nama + nomor telepon) di transaksi cuci — permintaan
 * owner 2026-10-11.
 *
 * Yang dijaga: keduanya opsional, tersimpan di transaksinya, ikut terbawa
 * lewat draft, bisa dikoreksi, dan nomor telepon tidak menerima huruf.
 */
class CustomerDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Katalog minimal milik tes ini sendiri — tidak bergantung pada seeder.
        WashCategory::query()->delete();
        WashService::query()->delete();
        WashPrice::query()->delete();
        WageRate::query()->delete();
        WashCategory::create(['slug' => 'ujimobil', 'label' => 'Mobil Uji', 'sort_order' => 1]);
        WashService::create(['slug' => 'reguler', 'label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::create(['category_slug' => 'ujimobil', 'service_slug' => 'reguler', 'price' => 30000]);
        WageRate::create(['category' => 'ujimobil', 'service' => 'reguler', 'amount' => 12000]);
    }

    private function header(string $role = 'kasir'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cucian(array $ubah = []): array
    {
        return $ubah + ['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash'];
    }

    public function test_nama_dan_telepon_tersimpan_di_transaksi(): void
    {
        $trx = $this->postJson('/api/transactions', $this->cucian([
            'customer_name' => 'Pak Budi', 'customer_phone' => '0812-3456-7890',
        ]), $this->header())->assertCreated()->json('data');

        $this->assertSame('Pak Budi', $trx['customer_name']);
        $this->assertSame('0812-3456-7890', $trx['customer_phone']);
    }

    public function test_boleh_dikosongkan(): void
    {
        $this->postJson('/api/transactions', $this->cucian(), $this->header())->assertCreated();

        $trx = Transaction::firstOrFail();
        $this->assertNull($trx->customer_name);
        $this->assertNull($trx->customer_phone);
    }

    public function test_telepon_berisi_huruf_ditolak(): void
    {
        $this->postJson('/api/transactions', $this->cucian(['customer_phone' => '0812abc']), $this->header())
            ->assertStatus(422)->assertJsonValidationErrors('customer_phone');

        $this->assertSame(0, Transaction::count());
    }

    public function test_draft_membawa_detail_pelanggan(): void
    {
        $draft = $this->postJson('/api/drafts', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil',
            'customer_name' => 'Bu Sari', 'customer_phone' => '+62 811 222 333',
        ], $this->header())->assertCreated()->json('data');

        $this->assertSame('Bu Sari', $draft['customer_name']);
        $this->assertSame('+62 811 222 333', $draft['customer_phone']);
    }

    public function test_koreksi_bisa_mengubah_dan_mengosongkan(): void
    {
        $id = $this->postJson('/api/transactions', $this->cucian([
            'customer_name' => 'Pak Budi', 'customer_phone' => '08123',
        ]), $this->header())->assertCreated()->json('data.id');

        $this->patchJson("/api/transactions/$id", [
            'customer_name' => 'Pak Budiman', 'customer_phone' => null, 'edit_reason' => 'salah ketik',
        ], $this->header('owner'))->assertOk();

        $trx = Transaction::findOrFail($id);
        $this->assertSame('Pak Budiman', $trx->customer_name);
        $this->assertNull($trx->customer_phone);
    }
}

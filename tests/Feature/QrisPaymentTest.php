<?php

namespace Tests\Feature;

use App\Models\CashBook;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QRIS sebagai cara bayar ketiga (permintaan owner 2026-10-10).
 *
 * Yang dijaga:
 *  - cucian dan penjualan F&B bisa dicatat dengan 'qris';
 *  - rekap melaporkannya di barisnya sendiri, tidak tercampur ke TF atau Cash;
 *  - seperti TF, QRIS bukan uang laci: tidak ikut setoran cash buku kas.
 */
class QrisPaymentTest extends TestCase
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

    private function header(string $role = 'owner'): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function baris(string $bayar): array
    {
        return ['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => $bayar];
    }

    public function test_cucian_qris_dilaporkan_di_barisnya_sendiri(): void
    {
        $tgl = now()->subDays(2)->toDateString();

        $this->postJson('/api/transactions/backdated', [
            'date' => $tgl,
            'book_number' => 1,
            'rows' => [$this->baris('cash'), $this->baris('tf'), $this->baris('qris'), $this->baris('qris')],
        ], $this->header())->assertCreated();

        $rekap = app(BookkeepingService::class)->dailyRecap($tgl);
        $this->assertSame(120000, $rekap['total']);
        $this->assertSame(30000, $rekap['tf'], 'QRIS tidak boleh tercampur ke TF.');
        $this->assertSame(60000, $rekap['qris']);
        $this->assertSame(30000, $rekap['cash_total'], 'QRIS bukan uang laci.');
        $this->assertSame(['cash', 'tf', 'qris'], array_column($rekap['by_payment'], 'method'));
        $this->assertSame(60000, $rekap['by_payment'][2]['total']);

        $buku = CashBook::where('date', $tgl)->where('number', 1)->firstOrFail();
        $this->assertSame(30000, $buku->amount, 'Setoran cash hanya dari yang dibayar cash.');
    }

    public function test_fnb_qris_dilaporkan_di_barisnya_sendiri(): void
    {
        $p = Product::create([
            'name' => 'Es Teh', 'type' => 'minuman',
            'price' => 5000, 'stock' => 10, 'is_active' => true,
        ]);

        foreach (['cash' => 1, 'tf' => 2, 'qris' => 3] as $bayar => $qty) {
            $this->postJson('/api/fnb-sales', [
                'payment_method' => $bayar,
                'items' => [['product_id' => $p->id, 'qty' => $qty]],
            ], $this->header('kasir'))->assertCreated();
        }

        $rekap = app(BookkeepingService::class)->dailyRecap(now()->toDateString());
        $this->assertSame(30000, $rekap['fnb_total']);
        $this->assertSame(5000, $rekap['fnb_cash']);
        $this->assertSame(10000, $rekap['fnb_tf']);
        $this->assertSame(15000, $rekap['fnb_qris']);
        $this->assertSame(5000, $rekap['cash_total']);
    }

    public function test_cara_bayar_tak_dikenal_ditolak(): void
    {
        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDay()->toDateString(),
            'rows' => [$this->baris('gopay')],
        ], $this->header())->assertStatus(422);

        $this->assertSame(0, Transaction::count());
    }
}

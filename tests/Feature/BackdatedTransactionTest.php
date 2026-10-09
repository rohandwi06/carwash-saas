<?php

namespace Tests\Feature;

use App\Models\CashBook;
use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cucian tanggal lampau yang diisi owner dari Pembukuan (banyak baris).
 *
 * Yang dijaga:
 *  - tanggalnya memang tanggal lampau itu, dan rekap hari itu menghitungnya;
 *  - TIDAK membuka/menyentuh buku kas mana pun (book_id null);
 *  - upah dibagi ke pekerja hari itu;
 *  - hanya owner, hanya tanggal yang sudah lewat, dan semua-atau-tidak-sama-sekali.
 */
class BackdatedTransactionTest extends TestCase
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

    private function baris(array $ubah = []): array
    {
        return $ubah + ['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash'];
    }

    public function test_cucian_lampau_masuk_ke_tanggalnya_tanpa_buku_kas(): void
    {
        $tgl = now()->subDays(5)->toDateString();
        $pekerja = [Worker::create(['name' => 'Adi'])->id, Worker::create(['name' => 'Budi'])->id];

        $this->postJson('/api/transactions/backdated', [
            'date' => $tgl,
            'worker_ids' => $pekerja,
            'rows' => [
                $this->baris(['plate' => 'N 1 AB', 'time' => '09:15']),
                $this->baris(['vehicle_name' => 'Innova', 'payment_method' => 'tf', 'tip' => 5000]),
            ],
        ], $this->header())->assertCreated()->assertJsonCount(2, 'data');

        $trx = Transaction::orderBy('queue_no')->get();
        $this->assertCount(2, $trx);
        $this->assertSame([$tgl, $tgl], $trx->map(fn ($t) => $t->date->toDateString())->all());
        $this->assertSame([null, null], $trx->pluck('book_id')->all(), 'Tidak menempel ke buku kas.');
        $this->assertSame(0, CashBook::count(), 'Buku kas tanggal lampau tidak boleh dibuka.');
        $this->assertSame('09:15', $trx[0]->created_at->format('H:i'));
        $this->assertSame($tgl, $trx[1]->created_at->toDateString(), 'Tanpa jam pun tetap di tanggalnya.');
        $this->assertStringContainsString('susulan', $trx[0]->created_by);
        $this->assertSame([1, 2], $trx->pluck('queue_no')->all());

        $rekap = app(BookkeepingService::class)->dailyRecap($tgl);
        $this->assertSame(60000, $rekap['total']);
        $this->assertSame(5000, $rekap['tip']);
        $this->assertSame(30000, $rekap['tf']);
        $this->assertSame(24000, $rekap['wages'], 'Upah 12.000 per cucian, dua cucian.');
        $this->assertSame(0, app(BookkeepingService::class)->dailyRecap(now()->toDateString())['total'],
            'Hari ini tidak ikut terisi.');
    }

    public function test_masuk_buku_pilihan_yang_langsung_disetor(): void
    {
        $tgl = now()->subDays(3)->toDateString();
        $kirim = fn (array $isi) => $this->postJson('/api/transactions/backdated', ['date' => $tgl] + $isi, $this->header());

        // Buku 1 belum ada -> dibuat, langsung disetor, setoran = cash-nya saja.
        $kirim(['book_number' => 1, 'rows' => [
            $this->baris(), $this->baris(['payment_method' => 'tf']),
        ]])->assertCreated();

        $buku1 = CashBook::where('date', $tgl)->where('number', 1)->firstOrFail();
        $this->assertSame('deposited', $buku1->status);
        $this->assertSame(30000, $buku1->amount, 'TF tidak ikut setoran cash.');
        $this->assertStringContainsString('susulan', $buku1->opened_by);
        $this->assertSame([$buku1->id, $buku1->id], Transaction::pluck('book_id')->all());

        // Menambah lagi ke Buku 1: buku yang sama, setorannya ikut naik.
        $kirim(['book_number' => 1, 'rows' => [$this->baris()]])->assertCreated();
        $this->assertSame(1, CashBook::where('date', $tgl)->count());
        $this->assertSame(60000, $buku1->fresh()->amount);

        // Buku 2 = buku berikutnya, boleh. Buku 4 melompati Buku 3, ditolak.
        $kirim(['book_number' => 2, 'rows' => [$this->baris()]])->assertCreated();
        $kirim(['book_number' => 4, 'rows' => [$this->baris()]])->assertStatus(422);
        $this->assertSame([1, 2], CashBook::where('date', $tgl)->orderBy('number')->pluck('number')->all());
        $this->assertSame(0, CashBook::where('status', 'open')->count(), 'Tidak ada buku terbuka di tanggal lampau.');

        $rekap = app(BookkeepingService::class)->dailyRecap($tgl);
        $this->assertSame([60000, 30000], array_column($rekap['books'], 'amount'));
        $this->assertSame(['deposited', 'deposited'], array_column($rekap['books'], 'status'));
    }

    public function test_pengeluaran_lampau_ikut_buku_dan_mengurangi_setoran(): void
    {
        $tgl = now()->subDays(2)->toDateString();

        $this->postJson('/api/transactions/backdated', [
            'date' => $tgl, 'book_number' => 1,
            'rows' => [$this->baris(), $this->baris()],
            'expenses' => [
                ['description' => 'Sabun', 'amount' => 15000],
                ['description' => 'Bensin genset', 'amount' => 10000],
            ],
        ], $this->header())->assertCreated();

        $buku = CashBook::where('date', $tgl)->firstOrFail();
        $keluar = \App\Models\Expense::orderBy('id')->get();
        $this->assertCount(2, $keluar);
        $this->assertSame([$buku->id, $buku->id], $keluar->pluck('book_id')->all());
        $this->assertSame($tgl, $keluar[0]->date->toDateString());
        $this->assertStringContainsString('susulan', $keluar[0]->created_by);
        $this->assertSame(35000, $buku->amount, 'Setoran = cash 60.000 - pengeluaran 25.000.');

        $rekap = app(BookkeepingService::class)->dailyRecap($tgl);
        $this->assertSame(25000, $rekap['expenses']);
        $this->assertSame(35000, $rekap['profit'], 'Laba = cuci 60.000 - pengeluaran 25.000 (tanpa pekerja, upah 0).');
    }

    public function test_boleh_hanya_pengeluaran_tapi_tidak_boleh_kosong(): void
    {
        $tgl = now()->subDays(2)->toDateString();

        $this->postJson('/api/transactions/backdated', [
            'date' => $tgl, 'expenses' => [['description' => 'Nota lama', 'amount' => 5000]],
        ], $this->header())->assertCreated()->assertJsonCount(0, 'data');
        $this->assertSame(1, \App\Models\Expense::count());
        $this->assertNull(\App\Models\Expense::first()->book_id, 'Tanpa buku bila tidak dipilih.');

        $this->postJson('/api/transactions/backdated', ['date' => $tgl, 'rows' => [], 'expenses' => []],
            $this->header())->assertStatus(422);
    }

    public function test_fnb_lampau_masuk_buku_tanpa_memotong_stok(): void
    {
        $tgl = now()->subDays(2)->toDateString();
        $kopi = \App\Models\Product::create(['name' => 'Kopi', 'type' => 'minuman', 'price' => 5000,
            'stock' => 3, 'is_active' => true]);

        $this->postJson('/api/transactions/backdated', [
            'date' => $tgl, 'book_number' => 1,
            'rows' => [$this->baris()],
            'fnb' => [
                ['product_id' => $kopi->id, 'qty' => 10, 'payment_method' => 'cash'],
                ['product_id' => $kopi->id, 'qty' => 2, 'payment_method' => 'tf'],
            ],
        ], $this->header())->assertCreated();

        $this->assertSame(3, $kopi->fresh()->stock, 'Stok hari ini tidak boleh ikut terpotong.');

        $buku = CashBook::where('date', $tgl)->firstOrFail();
        $jual = \App\Models\FnbSale::with('items')->orderBy('id')->get();
        $this->assertCount(2, $jual);
        $this->assertSame([$buku->id, $buku->id], $jual->pluck('book_id')->all());
        $this->assertSame($tgl, $jual[0]->date->toDateString());
        $this->assertSame($tgl, $jual[0]->created_at->toDateString());
        $this->assertSame(50000, $jual[0]->total);
        $this->assertSame(10, $jual[0]->items[0]->qty);
        $this->assertSame(30000 + 50000, $buku->amount, 'Setoran = cash cuci + cash F&B; TF tidak ikut.');

        $rekap = app(BookkeepingService::class)->dailyRecap($tgl);
        $this->assertSame(60000, $rekap['fnb_total']);
        $this->assertSame(50000, $rekap['fnb_cash']);
        $this->assertSame(10000, $rekap['fnb_tf']);
    }

    public function test_fnb_lampau_menolak_barang_titipan(): void
    {
        $penitip = \App\Models\Consignor::create(['name' => 'Bu Sri', 'share_mode' => 'setor']);
        $kue = \App\Models\Product::create(['name' => 'Kue', 'type' => 'makanan', 'price' => 5000, 'stock' => 5,
            'is_active' => true, 'consignor_id' => $penitip->id, 'payout_price' => 4000]);

        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDay()->toDateString(),
            'rows' => [$this->baris()],
            'fnb' => [['product_id' => $kue->id, 'qty' => 1, 'payment_method' => 'cash']],
        ], $this->header())->assertStatus(422);

        $this->assertSame(0, Transaction::count(), 'Cuciannya ikut batal - semua atau tidak sama sekali.');
        $this->assertSame(0, \App\Models\FnbSale::count());
    }

    public function test_hanya_owner(): void
    {
        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDay()->toDateString(), 'rows' => [$this->baris()],
        ], $this->header('kasir'))->assertStatus(403);

        $this->assertSame(0, Transaction::count());
    }

    public function test_hari_ini_dan_masa_depan_ditolak(): void
    {
        foreach ([now()->toDateString(), now()->addDay()->toDateString()] as $tgl) {
            $this->postJson('/api/transactions/backdated', ['date' => $tgl, 'rows' => [$this->baris()]],
                $this->header())->assertStatus(422);
        }
        $this->assertSame(0, Transaction::count());
    }

    public function test_satu_baris_salah_membatalkan_semuanya(): void
    {
        // Layanan 'reguler' ada, tapi jenis kendaraan kedua tidak punya harganya.
        WashCategory::create(['slug' => 'tanpaharga', 'label' => 'Tanpa Harga', 'sort_order' => 2]);

        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDay()->toDateString(),
            'rows' => [$this->baris(), $this->baris(['category' => 'tanpaharga'])],
        ], $this->header())->assertStatus(422)->assertJsonFragment(['message' => "Baris 2: Layanan 'reguler' tidak tersedia untuk jenis kendaraan 'tanpaharga'."]);

        $this->assertSame(0, Transaction::count(), 'Baris pertama tidak boleh tertinggal.');
    }
}

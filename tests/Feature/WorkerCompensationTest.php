<?php

namespace Tests\Feature;

use App\Models\CashBook;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Models\WorkerCompensation;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\CashBookService;
use App\Services\StatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kompensasi karyawan ("sangu" dari owner) — permintaan owner 2026-10-11.
 *
 * Yang dijaga:
 *  - memotong laba bersih di barisnya sendiri, TIDAK mengubah upah;
 *  - uangnya bukan dari laci: omzet dan setoran cash tidak berubah;
 *  - laporan harian, laporan rentang, dan statistik Dashboard menyebut laba
 *    yang sama;
 *  - hanya owner.
 */
class WorkerCompensationTest extends TestCase
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

    /** Satu cucian cash Rp 30.000 hari ini, dikerjakan $pekerja (upah Rp 12.000). */
    private function cucian(Worker $pekerja): void
    {
        $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
            'worker_ids' => [$pekerja->id],
        ], $this->header('kasir'))->assertCreated();
    }

    private function rekap(): array
    {
        return app(BookkeepingService::class)->dailyRecap(now()->toDateString());
    }

    public function test_memotong_laba_tanpa_mengubah_upah_omzet_dan_setoran(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->cucian($andi);
        $sebelum = $this->rekap();
        $buku = CashBook::firstOrFail();
        $setoranSebelum = app(CashBookService::class)->cashAmount($buku);

        $this->postJson('/api/worker-compensations', [
            'worker_ids' => [$andi->id], 'amount' => 15000, 'reason' => 'Uang makan',
        ], $this->header())->assertCreated()->assertJsonCount(1, 'data');

        $sesudah = $this->rekap();
        $this->assertSame(15000, $sesudah['compensation']);
        $this->assertSame('Andi', $sesudah['compensation_list'][0]['worker_name']);
        $this->assertSame($sebelum['profit'] - 15000, $sesudah['profit']);
        $this->assertSame($sebelum['wages'], $sesudah['wages'], 'Kompensasi bukan upah.');
        $this->assertSame($sebelum['cash_total'], $sesudah['cash_total']);
        $this->assertSame($setoranSebelum, app(CashBookService::class)->cashAmount($buku),
            'Uangnya dari owner, setoran cash kasir tidak berubah.');
        $this->assertSame(0, app(BookkeepingService::class)->dailyRecap(now()->toDateString(), $buku->id)['compensation'],
            'Kompensasi tidak menempel ke buku kas.');
    }

    public function test_jumlah_berlaku_per_orang_per_tanggal(): void
    {
        $a = Worker::create(['name' => 'Andi']);
        $b = Worker::create(['name' => 'Bayu']);
        $c = Worker::create(['name' => 'Candra']);
        $tgl = [now()->subDay()->toDateString(), now()->toDateString()];

        $this->postJson('/api/worker-compensations', [
            'worker_ids' => [$a->id, $b->id, $c->id], 'amount' => 10000, 'reason' => 'Uang bensin', 'dates' => $tgl,
        ], $this->header())->assertCreated()->assertJsonCount(6, 'data');

        $this->assertSame(60000, (int) WorkerCompensation::sum('amount'));
        $this->assertSame(30000, $this->rekap()['compensation']);

        $this->getJson('/api/worker-compensations?dates[]='.$tgl[0].'&dates[]='.$tgl[1], $this->header())
            ->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('summary.total', 60000);
    }

    public function test_tiga_jalur_laporan_menyebut_laba_yang_sama(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->cucian($andi);
        $this->postJson('/api/worker-compensations', [
            'worker_ids' => [$andi->id], 'amount' => 5000, 'reason' => 'Uang makan',
        ], $this->header())->assertCreated();

        $hari = now()->toDateString();
        $harian  = $this->rekap()['profit'];
        $rentang = app(BookkeepingService::class)->dateRange($hari, $hari)['summary'];
        $statistik = $this->getJson('/api/reports/stats?period=harian', $this->header())->assertOk()->json('data.summary');

        $this->assertSame(30000 - 12000 - 5000, $harian);
        $this->assertSame($harian, $rentang['profit']);
        $this->assertSame(5000, $rentang['compensation']);
        $this->assertSame($harian, $statistik['profit']);
    }

    public function test_hapus_mengembalikan_laba(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $id = $this->postJson('/api/worker-compensations', [
            'worker_ids' => [$andi->id], 'amount' => 15000, 'reason' => 'Uang makan',
        ], $this->header())->assertCreated()->json('data.0.id');

        $this->deleteJson("/api/worker-compensations/$id", [], $this->header())->assertOk();

        $this->assertSame(0, $this->rekap()['compensation']);
    }

    public function test_pekerja_dihapus_catatannya_tetap_memotong_laba(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->postJson('/api/worker-compensations', [
            'worker_ids' => [$andi->id], 'amount' => 15000, 'reason' => 'Uang makan',
        ], $this->header())->assertCreated();

        $andi->delete();

        $this->assertSame(15000, $this->rekap()['compensation']);
        $this->assertSame('Andi', WorkerCompensation::firstOrFail()->worker_name);
    }

    public function test_hanya_owner_dan_jumlah_harus_positif(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $isi = ['worker_ids' => [$andi->id], 'amount' => 15000, 'reason' => 'Uang makan'];

        $this->postJson('/api/worker-compensations', $isi, $this->header('kasir'))->assertStatus(403);
        $this->getJson('/api/worker-compensations', $this->header('kasir'))->assertStatus(403);
        $this->postJson('/api/worker-compensations', ['amount' => 0] + $isi, $this->header())->assertStatus(422);

        $this->assertSame(0, WorkerCompensation::count());
    }
}

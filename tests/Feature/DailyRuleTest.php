<?php

namespace Tests\Feature;

use App\Models\DailyRule;
use App\Models\Transaction;
use App\Models\WageAdjustment;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Models\WorkerCompensation;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan otomatis harian untuk potongan upah & kompensasi — permintaan owner
 * 2026-10-11.
 *
 * Yang dijaga:
 *  - hanya pekerja yang MENGERJAKAN CUCIAN hari itu yang kena, dan hanya
 *    sekali per aturan per hari berapa pun cuciannya;
 *  - cucian dibatalkan / pekerja dicopot -> catatan otomatisnya ditarik;
 *  - catatan otomatis yang dihapus owner tidak dibuat lagi hari itu;
 *  - mengubah / mematikan aturan berlaku mulai hari ini, tidak mundur;
 *  - hanya owner.
 */
class DailyRuleTest extends TestCase
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

    /** Satu cucian cash Rp 30.000 hari ini (upah Rp 12.000 dibagi rata). */
    private function cucian(Worker ...$pekerja): int
    {
        return $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
            'worker_ids' => array_map(fn (Worker $w) => $w->id, $pekerja),
        ], $this->header('kasir'))->assertCreated()->json('data.id');
    }

    private function aturan(array $isi): array
    {
        return $this->postJson('/api/daily-rules', $isi + ['reason' => 'Uang makan'], $this->header())
            ->assertCreated()->json('data');
    }

    private function rekap(): array
    {
        return app(BookkeepingService::class)->dailyRecap(now()->toDateString());
    }

    public function test_kompensasi_hanya_untuk_yang_kerja_dan_sekali_sehari(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);
        Worker::create(['name' => 'Candra']);   // tidak masuk hari ini
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);

        $this->assertSame(0, WorkerCompensation::count(), 'Belum ada cucian, belum ada yang kerja.');

        $this->cucian($andi);
        $this->cucian($andi);            // cucian kedua tidak menggandakan
        $this->cucian($andi, $bayu);

        $baris = WorkerCompensation::orderBy('worker_id')->get();
        $this->assertSame(['Andi', 'Bayu'], $baris->pluck('worker_name')->all());
        $this->assertSame([10000, 10000], $baris->pluck('amount')->all());
        $this->assertSame(['Otomatis', 'Otomatis'], $baris->pluck('created_by')->all());
        $this->assertSame(20000, $this->rekap()['compensation']);
    }

    public function test_potongan_untuk_satu_pekerja_mengurangi_upahnya(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);
        $this->aturan(['type' => 'potongan', 'worker_id' => $andi->id, 'amount' => 5000, 'reason' => 'Cicilan kasbon']);

        $id = $this->cucian($andi, $bayu);   // upah 12.000 -> 6.000 per orang

        $potongan = WageAdjustment::sole();
        $this->assertSame($andi->id, $potongan->worker_id);
        $this->assertSame(WageAdjustment::POTONGAN, $potongan->type);
        $this->assertSame(Transaction::findOrFail($id)->book_id, $potongan->book_id, 'Menempel ke buku kas cuciannya.');
        $this->assertSame(12000 - 5000, $this->rekap()['wages']);
    }

    public function test_aturan_baru_langsung_berlaku_untuk_yang_sudah_kerja_hari_ini(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->cucian($andi);

        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);

        $this->assertSame(1, WorkerCompensation::count());
    }

    public function test_cucian_dibatalkan_catatan_otomatisnya_ditarik(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);
        $this->aturan(['type' => 'potongan', 'amount' => 2000, 'reason' => 'Iuran']);

        $satu = $this->cucian($andi);
        $this->cucian($bayu);
        $this->assertSame(2, WorkerCompensation::count());
        $this->assertSame(2, WageAdjustment::count());

        $this->postJson("/api/transactions/$satu/void", ['reason' => 'salah input'], $this->header())->assertOk();

        $this->assertSame(['Bayu'], WorkerCompensation::pluck('worker_name')->all(), 'Andi tidak jadi kerja hari ini.');
        $this->assertSame([$bayu->id], WageAdjustment::pluck('worker_id')->all());
    }

    public function test_koreksi_pekerja_memindahkan_catatan_otomatis(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);
        $id = $this->cucian($andi);

        $this->patchJson("/api/transactions/$id", ['worker_ids' => [$bayu->id], 'edit_reason' => 'salah pilih pekerja'],
            $this->header())->assertOk();

        $this->assertSame(['Bayu'], WorkerCompensation::pluck('worker_name')->all());
    }

    public function test_catatan_otomatis_yang_dihapus_tidak_dibuat_lagi_hari_itu(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);
        $this->aturan(['type' => 'potongan', 'amount' => 2000, 'reason' => 'Iuran']);
        $this->cucian($andi);

        $this->deleteJson('/api/worker-compensations/'.WorkerCompensation::sole()->id, [], $this->header())->assertOk();
        $this->deleteJson('/api/wage-adjustments/'.WageAdjustment::sole()->id, [], $this->header())->assertOk();
        $this->cucian($andi);   // cucian berikutnya di hari yang sama

        $this->assertSame(0, WorkerCompensation::count(), 'Owner sudah menghapusnya untuk hari ini.');
        $this->assertSame(0, WageAdjustment::count());
    }

    public function test_catatan_manual_tidak_pernah_disentuh(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->postJson('/api/worker-compensations', ['worker_ids' => [$andi->id], 'amount' => 7000, 'reason' => 'Bonus'],
            $this->header())->assertCreated();
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);

        $id = $this->cucian($andi);
        $this->postJson("/api/transactions/$id/void", ['reason' => 'salah input'], $this->header())->assertOk();

        $this->assertSame([7000], WorkerCompensation::pluck('amount')->all(), 'Yang dicatat tangan tetap ada.');
    }

    public function test_mengubah_dan_mematikan_berlaku_mulai_hari_ini_tidak_mundur(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $kemarin = now()->subDay()->toDateString();
        $aturan = $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);
        // Anggap aturannya sudah jalan sejak minggu lalu.
        DailyRule::whereKey($aturan['id'])->update(['start_date' => now()->subWeek()->toDateString()]);

        $this->postJson('/api/transactions/backdated', [
            'date' => $kemarin, 'worker_ids' => [$andi->id],
            'rows' => [['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash']],
        ], $this->header())->assertCreated();
        $this->cucian($andi);
        $this->assertSame(2, WorkerCompensation::count(), 'Kemarin dan hari ini.');

        $this->patchJson('/api/daily-rules/'.$aturan['id'], ['amount' => 15000], $this->header())->assertOk();

        $this->assertSame(10000, WorkerCompensation::whereDate('date', $kemarin)->sole()->amount, 'Kemarin tidak berubah.');
        $this->assertSame(15000, WorkerCompensation::whereDate('date', now()->toDateString())->sole()->amount);

        $this->patchJson('/api/daily-rules/'.$aturan['id'], ['is_active' => false], $this->header())->assertOk();
        $this->cucian($andi);

        $this->assertSame([10000], WorkerCompensation::pluck('amount')->all(), 'Hari ini ditarik, kemarin tetap.');
    }

    public function test_tidak_berlaku_untuk_tanggal_sebelum_aturan_dibuat(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->aturan(['type' => 'kompensasi', 'amount' => 10000]);

        $this->postJson('/api/transactions/backdated', [
            'date' => now()->subDays(3)->toDateString(), 'worker_ids' => [$andi->id],
            'rows' => [['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash']],
        ], $this->header())->assertCreated();

        $this->assertSame(0, WorkerCompensation::count());
    }

    public function test_menghapus_aturan_menyisakan_catatan_lama(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $kemarin = now()->subDay()->toDateString();
        $aturan = $this->aturan(['type' => 'potongan', 'amount' => 2000, 'reason' => 'Iuran']);
        DailyRule::whereKey($aturan['id'])->update(['start_date' => now()->subWeek()->toDateString()]);
        $this->postJson('/api/transactions/backdated', [
            'date' => $kemarin, 'worker_ids' => [$andi->id],
            'rows' => [['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash']],
        ], $this->header())->assertCreated();
        $this->cucian($andi);

        $this->deleteJson('/api/daily-rules/'.$aturan['id'], [], $this->header())->assertOk();

        $sisa = WageAdjustment::sole();
        $this->assertSame($kemarin, $sisa->date->toDateString());
        $this->assertNull($sisa->rule_id, 'Jadi catatan biasa: uangnya memang sudah dipotong.');
        $this->assertSame(0, DailyRule::count());
    }

    public function test_hanya_owner_dan_isian_wajib(): void
    {
        $isi = ['type' => 'kompensasi', 'amount' => 10000, 'reason' => 'Uang makan'];

        $this->postJson('/api/daily-rules', $isi, $this->header('kasir'))->assertStatus(403);
        $this->getJson('/api/daily-rules', $this->header('kasir'))->assertStatus(403);
        $this->postJson('/api/daily-rules', ['amount' => 0] + $isi, $this->header())->assertStatus(422);
        $this->postJson('/api/daily-rules', ['reason' => ''] + $isi, $this->header())->assertStatus(422);
        $this->postJson('/api/daily-rules', ['type' => 'bonus'] + $isi, $this->header())->assertStatus(422);

        $this->assertSame(0, DailyRule::count());
    }
}

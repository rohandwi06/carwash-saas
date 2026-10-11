<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Models\Worker;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\SalaryService;
use App\Services\WageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cara upah ketiga: UPAH PER BULAN — permintaan owner 2026-10-11.
 *
 * Yang dijaga:
 *  - gaji dibebankan rata per hari kalender, jumlah sebulan tepat = gajinya;
 *  - selama mode bulanan, cucian tidak membawa upah sendiri;
 *  - berlaku mulai hari dinyalakan, tidak mundur dan tidak untuk hari esok;
 *    berganti cara upah / mengubah gaji tidak mengubah hari yang sudah lewat;
 *  - potongan mengurangi gaji bulan berjalan (angka harian boleh minus);
 *  - laporan harian, rentang, dan Dashboard menyebut laba yang sama.
 *
 * Semua tes memaku tanggal di Oktober 2026 (31 hari) supaya angkanya bulat.
 */
class MonthlyWageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');

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

    private function mode(string $mode): void
    {
        $this->putJson('/api/wage-sharing', ['mode' => $mode], $this->header())->assertOk();
    }

    /** @param array<int,int> $gaji worker_id => gaji per bulan */
    private function gaji(array $gaji): void
    {
        $this->putJson('/api/worker-salaries', [
            'salaries' => collect($gaji)->map(fn ($g, $id) => ['worker_id' => $id, 'amount' => $g])->values()->all(),
        ], $this->header())->assertOk();
    }

    private function cucian(Worker ...$pekerja): int
    {
        return $this->postJson('/api/transactions', [
            'vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash',
            'worker_ids' => array_map(fn (Worker $w) => $w->id, $pekerja),
        ], $this->header('kasir'))->assertCreated()->json('data.id');
    }

    private function rekap(?string $tgl = null): array
    {
        return app(BookkeepingService::class)->dailyRecap($tgl ?? now()->toDateString());
    }

    public function test_gaji_dibebankan_rata_per_hari_dan_cucian_tidak_membawa_upah(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);
        Worker::create(['name' => 'Candra']);   // tidak diberi gaji
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000, $bayu->id => 1550000]);

        $id = $this->cucian($andi);

        $this->assertSame([0], Transaction::findOrFail($id)->workers->pluck('pivot.wage_share')->all());
        $rekap = $this->rekap();
        $this->assertSame(100000 + 50000, $rekap['wages'], '3.100.000 / 31 dan 1.550.000 / 31.');
        $this->assertSame(30000 - 150000, $rekap['profit']);

        $baris = collect($rekap['worker_wages'])->keyBy('name');
        $this->assertSame(['Andi', 'Bayu'], $baris->keys()->sort()->values()->all(), 'Bayu muncul walau tidak mengerjakan cucian.');
        $this->assertSame(100000, $baris['Andi']['salary']);
        $this->assertSame(1, $baris['Andi']['vehicles']);
        $this->assertSame(50000, $baris['Bayu']['wage']);
    }

    public function test_jumlah_sebulan_tepat_sama_dengan_gajinya(): void
    {
        $this->assertSame(96774, SalaryService::bagianHari(3000000, 1, 31));
        $this->assertSame(96780, SalaryService::bagianHari(3000000, 31, 31), 'Sisa pembagian di hari terakhir.');

        $this->travelTo('2026-10-01 08:00:00');
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3000000]);

        $this->travelTo('2026-10-31 20:00:00');
        $upah = app(WageService::class)->rangeByDate('2026-10-01', '2026-10-31');

        $this->assertCount(31, $upah);
        $this->assertSame(3000000, array_sum($upah));
    }

    public function test_tidak_mundur_dan_tidak_untuk_hari_esok(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');           // dinyalakan tanggal 15
        $this->gaji([$andi->id => 3100000]);

        $this->travelTo('2026-10-17 10:00:00');
        $upah = app(WageService::class)->rangeByDate('2026-10-01', '2026-10-31');

        $this->assertSame(['2026-10-15', '2026-10-16', '2026-10-17'], array_keys($upah));
        $this->assertSame(0, $this->rekap('2026-10-14')['wages']);
        $this->assertSame(0, $this->rekap('2026-10-18')['wages'], 'Gaji hari esok belum terjadi.');
    }

    public function test_potongan_mengurangi_gaji_bulan_berjalan_angka_harian_boleh_minus(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $bayu = Worker::create(['name' => 'Bayu']);   // tanpa gaji
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);

        foreach ([$andi, $bayu] as $w) {
            $this->postJson('/api/wage-adjustments', [
                'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 250000, 'reason' => 'Kasbon',
            ], $this->header())->assertCreated();
        }

        $baris = collect($this->rekap()['worker_wages'])->keyBy('name');
        $this->assertSame(100000 - 250000, $baris['Andi']['wage'], 'Kasbon lebih besar dari gaji sehari: tetap dipotong penuh.');
        $this->assertSame(250000, $baris['Andi']['penalty_applied']);
        $this->assertSame(0, $baris['Bayu']['wage'], 'Tanpa gaji bulanan, upah tetap tidak pernah minus.');

        // Sehari sesudahnya: dua hari gaji dikurangi kasbonnya.
        $this->travelTo('2026-10-16 10:00:00');
        $this->assertSame(200000 - 250000, array_sum(app(WageService::class)->rangeByDate('2026-10-15', '2026-10-16')));
    }

    public function test_gaji_diubah_berlaku_mulai_hari_ini(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);

        $this->travelTo('2026-10-16 10:00:00');
        $this->gaji([$andi->id => 6200000]);

        $this->assertSame(100000, $this->rekap('2026-10-15')['wages'], 'Kemarin tetap gaji lama.');
        $this->assertSame(200000, $this->rekap('2026-10-16')['wages']);
        $this->assertSame(6200000, collect($this->getJson('/api/worker-salaries', $this->header())->json('data'))
            ->firstWhere('worker_id', $andi->id)['amount']);
    }

    public function test_masuk_dan_keluar_mode_bulanan_berlaku_untuk_seluruh_hari_itu(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $pagi = $this->cucian($andi);     // tercatat per kendaraan: Rp 12.000
        $this->assertSame(12000, $this->rekap()['wages']);

        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);

        $this->assertSame([0], Transaction::findOrFail($pagi)->workers->pluck('pivot.wage_share')->all(),
            'Cucian pagi tidak dibayar dobel: hari ini sudah digaji harian.');
        $this->assertSame(100000, $this->rekap()['wages']);

        // Besoknya owner kembali ke upah per kendaraan.
        $this->travelTo('2026-10-16 10:00:00');
        $siang = $this->cucian($andi);
        $this->assertSame(100000, $this->rekap()['wages']);

        $this->mode('nominal');

        $this->assertSame([12000], Transaction::findOrFail($siang)->workers->pluck('pivot.wage_share')->all());
        $this->assertSame(12000, $this->rekap()['wages'], 'Hari ini kembali per kendaraan, tanpa gaji harian.');
        $this->assertSame(100000, $this->rekap('2026-10-15')['wages'], 'Kemarin tetap digaji harian.');
        $this->assertSame([0], Transaction::findOrFail($pagi)->workers->pluck('pivot.wage_share')->all());
    }

    public function test_cucian_susulan_sebelum_mode_bulanan_tetap_per_kendaraan(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);

        $this->postJson('/api/transactions/backdated', [
            'date' => '2026-10-10', 'worker_ids' => [$andi->id],
            'rows' => [['vehicle_name' => 'Avanza', 'category' => 'ujimobil', 'payment_method' => 'cash']],
        ], $this->header())->assertCreated();

        $this->assertSame(12000, $this->rekap('2026-10-10')['wages']);
    }

    public function test_tiga_jalur_laporan_menyebut_laba_yang_sama(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);
        $this->cucian($andi);

        $hari = now()->toDateString();
        $harian  = $this->rekap()['profit'];
        $rentang = app(BookkeepingService::class)->dateRange($hari, $hari)['summary'];
        $statistik = $this->getJson('/api/reports/stats?period=harian', $this->header())->assertOk()->json('data.summary');

        $this->assertSame(30000 - 100000, $harian);
        $this->assertSame($harian, $rentang['profit']);
        $this->assertSame(100000, $rentang['wages']);
        $this->assertSame($harian, $statistik['profit']);
    }

    public function test_hari_tutup_tetap_dibebani_gaji_tapi_bukan_hari_beroperasi(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $this->mode('bulanan');
        $this->gaji([$andi->id => 3100000]);
        $this->cucian($andi);

        $this->travelTo('2026-10-17 10:00:00');   // tanggal 16 dan 17 tanpa cucian
        $ringkas = app(BookkeepingService::class)->dateRange('2026-10-01', '2026-10-31')['summary'];

        $this->assertSame(300000, $ringkas['wages']);
        $this->assertSame(1, $ringkas['operating_days']);
    }

    public function test_hanya_owner_dan_isian_wajar(): void
    {
        $andi = Worker::create(['name' => 'Andi']);
        $isi = ['salaries' => [['worker_id' => $andi->id, 'amount' => 3000000]]];

        $this->putJson('/api/worker-salaries', $isi, $this->header('kasir'))->assertStatus(403);
        $this->getJson('/api/worker-salaries', $this->header('kasir'))->assertStatus(403);
        $this->putJson('/api/worker-salaries', ['salaries' => [['worker_id' => $andi->id, 'amount' => -1]]], $this->header())->assertStatus(422);
        $this->putJson('/api/wage-sharing', ['mode' => 'mingguan'], $this->header())->assertStatus(422);

        $this->assertSame(0, collect($this->getJson('/api/worker-salaries', $this->header())->json('data'))->sum('amount'));
    }
}

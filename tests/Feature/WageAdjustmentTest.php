<?php

namespace Tests\Feature;

use App\Models\WageAdjustment;
use App\Models\WageRate;
use App\Models\Worker;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\AuthTokenService;
use App\Services\BookkeepingService;
use App\Services\TransactionService;
use App\Services\WageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Potongan upah (hukuman) & penimpaan angka upah oleh owner.
 *
 * Yang dijaga di sini:
 *  - uang yang tidak jadi dibayarkan SELALU menaikkan laba bersih;
 *  - laporan harian dan laporan rentang menampilkan angka yang SAMA — kalau
 *    salah satu jalur hitung lupa menerapkan penyesuaian, dua layar akan
 *    menyebut upah berbeda untuk hari yang sama;
 *  - potongan tidak pernah membuat upah jadi minus;
 *  - kasir tidak bisa menyentuhnya sama sekali.
 */
class WageAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    /** Katalog + tarif upah: satu cucian 'kecil/reguler' memberi upah 20.000. */
    private function siapkan(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashService::firstOrCreate(['slug' => 'reguler'], ['label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::updateOrCreate(
            ['category_slug' => 'kecil', 'service_slug' => 'reguler'],
            ['price' => 50000],
        );

        $rate = WageRate::firstOrNew(['category' => 'kecil', 'service' => 'reguler']);
        $rate->amount = 20000;
        $rate->trainee_amount ??= 0;
        $rate->save();
    }

    private function cuci(Worker $w): void
    {
        app(TransactionService::class)->create([
            'vehicle_name' => 'Avanza', 'category' => 'kecil', 'service' => 'reguler',
            'payment_method' => 'cash', 'worker_ids' => [$w->id],
        ]);
    }

    private function rekap(): array
    {
        return app(BookkeepingService::class)->dailyRecap(now()->toDateString());
    }

    public function test_potongan_mengurangi_upah_dan_menaikkan_laba(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $sebelum = $this->rekap();
        $this->assertSame(20000, $sebelum['wages']);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan',
            'amount' => 5000, 'reason' => 'telat',
        ], $this->header('owner'))->assertCreated();

        $sesudah = $this->rekap();
        $this->assertSame(15000, $sesudah['wages'], 'Upah yang dilaporkan harus sudah dipotong.');
        $this->assertSame($sebelum['profit'] + 5000, $sesudah['profit'],
            'Uang yang tidak jadi dibayarkan harus menambah laba bersih.');
    }

    public function test_timpa_mengganti_angka_upah(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'timpa', 'amount' => 8000,
        ], $this->header('owner'))->assertCreated();

        $this->assertSame(8000, $this->rekap()['wages']);
    }

    public function test_timpa_boleh_nol(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'timpa', 'amount' => 0,
            'reason' => 'tidak masuk kerja',
        ], $this->header('owner'))->assertCreated();

        $this->assertSame(0, $this->rekap()['wages']);
    }

    public function test_potongan_nol_ditolak(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 0,
        ], $this->header('owner'))->assertStatus(422);
    }

    public function test_potongan_lebih_besar_dari_upah_tidak_bikin_minus(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);   // upah 20.000

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 99000,
        ], $this->header('owner'))->assertCreated();

        $rekap = $this->rekap();
        $this->assertSame(0, $rekap['wages'], 'Upah tidak boleh minus.');
        // Labanya naik sebatas upah yang batal dibayarkan, bukan sebesar
        // potongan yang diketik — uang yang tidak ada tidak bisa jadi laba.
        $this->assertSame(50000, $rekap['profit']);
    }

    public function test_timpa_lalu_potongan_berurutan(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);   // 20.000

        $h = $this->header('owner');
        $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => 'timpa', 'amount' => 12000], $h)->assertCreated();
        $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => 'potongan', 'amount' => 2000], $h)->assertCreated();

        // timpa menetapkan dasar (12.000), lalu potongan menguranginya.
        $this->assertSame(10000, $this->rekap()['wages']);
    }

    public function test_timpa_terbaru_yang_dipakai(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $h = $this->header('owner');
        $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => 'timpa', 'amount' => 9000], $h)->assertCreated();
        $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => 'timpa', 'amount' => 3000], $h)->assertCreated();

        $this->assertSame(3000, $this->rekap()['wages']);
    }

    /**
     * Laporan rentang (rangeByDate & byDateRange) memakai jalur hitung yang
     * berbeda dari laporan harian — keduanya harus setuju, kalau tidak owner
     * melihat dua angka upah untuk hari yang sama.
     */
    public function test_laporan_rentang_setuju_dengan_laporan_harian(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 7000,
        ], $this->header('owner'))->assertCreated();

        $tgl    = now()->toDateString();
        $harian = $this->rekap()['wages'];
        $wages  = app(WageService::class);

        $this->assertSame($harian, $wages->rangeByDate($tgl, $tgl)[$tgl] ?? 0,
            'rangeByDate harus sama dengan rekap harian.');

        $perPekerja = $wages->byDateRange($tgl, $tgl);
        $this->assertSame($harian, (int) $perPekerja->sum('total_wage'),
            'byDateRange harus sama dengan rekap harian.');
        $this->assertSame(7000, (int) $perPekerja->sum('total_penalty'));
    }

    /** Potongan untuk pekerja yang hari itu tidak kebagian cucian tetap terlihat. */
    public function test_potongan_tanpa_cucian_tetap_muncul(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);

        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 5000,
        ], $this->header('owner'))->assertCreated();

        $baris = app(WageService::class)->dailyRecap(now()->toDateString())
            ->firstWhere('id', $w->id);

        $this->assertNotNull($baris, 'Pekerja dengan potongan harus tetap muncul di rekap.');
        $this->assertSame(0, $baris['wage']);
        $this->assertSame(5000, $baris['penalty']);
    }

    public function test_hapus_penyesuaian_mengembalikan_upah(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        $id = $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 5000,
        ], $this->header('owner'))->json('data.id');

        $this->assertSame(15000, $this->rekap()['wages']);

        $this->deleteJson('/api/wage-adjustments/'.$id, [], $this->header('owner'))->assertOk();

        $this->assertSame(20000, $this->rekap()['wages'], 'Upah harus kembali utuh.');
    }

    public function test_kasir_tidak_bisa_menyentuh(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $h = $this->header('kasir');

        $this->getJson('/api/wage-adjustments', $h)->assertStatus(403);
        $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => 'potongan', 'amount' => 5000], $h)->assertStatus(403);

        $adj = WageAdjustment::create([
            'worker_id' => $w->id, 'worker_name' => $w->name,
            'type' => 'potongan', 'amount' => 1000, 'date' => now()->toDateString(),
        ]);
        $this->deleteJson('/api/wage-adjustments/'.$adj->id, [], $h)->assertStatus(403);
    }

    public function test_potongan_hanya_berlaku_di_tanggalnya(): void
    {
        $this->siapkan();
        $w = Worker::create(['name' => 'Budi']);
        $this->cuci($w);

        // Potongan untuk KEMARIN tidak boleh menyentuh upah hari ini.
        $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 5000,
            'date' => now()->subDay()->toDateString(),
        ], $this->header('owner'))->assertCreated();

        $this->assertSame(20000, $this->rekap()['wages']);
    }
}

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
        $this->postJson('/api/wage-adjustments/bulk-delete', ['ids' => [$adj->id]], $h)->assertStatus(403);
        $this->assertDatabaseHas('wage_adjustments', ['id' => $adj->id]);
    }

    /**
     * Hapus sekaligus dari kalender: hanya id yang dikirim yang hilang, dan
     * upah hari itu kembali utuh — sama seperti menghapus satu per satu.
     */
    public function test_hapus_sekaligus_hanya_id_yang_dikirim(): void
    {
        $this->siapkan();
        $budi = Worker::create(['name' => 'Budi']);
        $andi = Worker::create(['name' => 'Andi']);
        $this->cuci($budi);
        $h = $this->header('owner');

        $potong = fn (Worker $w, int $jumlah, ?string $tgl = null) => $this->postJson('/api/wage-adjustments', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => $jumlah,
            'date' => $tgl ?? now()->toDateString(),
        ], $h)->json('data.id');

        $b1 = $potong($budi, 5000);
        $b2 = $potong($budi, 2000, now()->subDay()->toDateString());
        $a1 = $potong($andi, 1000);
        $this->assertSame(15000, $this->rekap()['wages']);

        $this->postJson('/api/wage-adjustments/bulk-delete', ['ids' => [$b1, $b2]], $h)
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('wage_adjustments', ['id' => $b1]);
        $this->assertDatabaseMissing('wage_adjustments', ['id' => $b2]);
        $this->assertDatabaseHas('wage_adjustments', ['id' => $a1]);
        $this->assertSame(20000, $this->rekap()['wages'], 'Upah Budi harus kembali utuh.');
    }

    /**
     * Catat sekaligus dari kalender: angka yang sama untuk SETIAP tanggal,
     * dan filter dates[] mengembalikan tanggal pilihan saja — hari di
     * antaranya yang tidak dipilih tidak ikut terhitung.
     */
    public function test_catat_sekaligus_per_tanggal(): void
    {
        $this->siapkan();
        $budi = Worker::create(['name' => 'Budi']);
        $this->cuci($budi);
        $h = $this->header('owner');
        $hariIni = now()->toDateString();

        $res = $this->postJson('/api/wage-adjustments/bulk', [
            'worker_id' => $budi->id, 'type' => 'potongan', 'amount' => 5000,
            'reason' => 'kasbon', 'dates' => [$hariIni, '2026-09-09', '2026-09-14'],
        ], $h)->assertCreated();

        $this->assertCount(3, $res->json('data'));
        $this->assertSame(15000, $this->rekap()['wages'], 'Potongan hari ini harus ikut memotong upah.');

        // Hari di antara yang TIDAK dipilih tidak boleh ikut terhitung.
        WageAdjustment::create([
            'worker_id' => $budi->id, 'worker_name' => 'Budi',
            'type' => 'potongan', 'amount' => 99000, 'date' => '2026-09-10',
        ]);

        $daftar = $this->getJson('/api/wage-adjustments?dates[]=2026-09-09&dates[]=2026-09-14', $h)
            ->assertOk();
        $this->assertCount(2, $daftar->json('data'));
        $this->assertSame(10000, $daftar->json('summary.total_potongan'));
        $this->assertSame('kasbon', $daftar->json('data.0.reason'));
    }

    /**
     * Potong Rp 100.000 untuk 7 hari = total dibagi rata, BUKAN 100.000 per
     * hari. Sisa pembagian ke tanggal terakhir, jadi jumlahnya tepat 100.000.
     */
    public function test_potongan_total_dibagi_rata(): void
    {
        $w = Worker::create(['name' => 'Budi']);
        $tanggal = ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10',
                    '2026-09-11', '2026-09-12', '2026-09-13'];

        $res = $this->postJson('/api/wage-adjustments/bulk', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 100000,
            'split' => true, 'dates' => array_reverse($tanggal),   // urutan kiriman tidak penting
        ], $this->header('owner'))->assertCreated();

        $this->assertSame(
            [14285, 14285, 14285, 14285, 14285, 14285, 14290],
            array_column($res->json('data'), 'amount'),
        );
        $this->assertSame('2026-09-13', $res->json('data.6.date'), 'Sisa ke tanggal terakhir.');
        $this->assertSame(100000, (int) WageAdjustment::sum('amount'));
    }

    public function test_potongan_dibagi_rata_ditolak_bila_total_terlalu_kecil(): void
    {
        $w = Worker::create(['name' => 'Budi']);

        $this->postJson('/api/wage-adjustments/bulk', [
            'worker_id' => $w->id, 'type' => 'potongan', 'amount' => 2,
            'split' => true, 'dates' => ['2026-09-07', '2026-09-08', '2026-09-09'],
        ], $this->header('owner'))->assertStatus(422);

        $this->assertSame(0, WageAdjustment::count());
    }

    /** Timpa tidak pernah dibagi: angkanya upah pengganti untuk SETIAP hari. */
    public function test_timpa_tidak_dibagi_walau_split(): void
    {
        $w = Worker::create(['name' => 'Budi']);

        $res = $this->postJson('/api/wage-adjustments/bulk', [
            'worker_id' => $w->id, 'type' => 'timpa', 'amount' => 30000,
            'split' => true, 'dates' => ['2026-09-07', '2026-09-08'],
        ], $this->header('owner'))->assertCreated();

        $this->assertSame([30000, 30000], array_column($res->json('data'), 'amount'));
    }

    public function test_catat_sekaligus_ditolak_bila_tidak_sah(): void
    {
        $w = Worker::create(['name' => 'Budi']);
        $owner = $this->header('owner');
        $dasar = ['worker_id' => $w->id, 'type' => 'potongan', 'amount' => 5000];

        $this->postJson('/api/wage-adjustments/bulk', $dasar + ['dates' => []], $owner)->assertStatus(422);
        $this->postJson('/api/wage-adjustments/bulk', $dasar + ['dates' => ['2026-09-09', '2026-09-09']], $owner)
            ->assertStatus(422);
        $this->postJson('/api/wage-adjustments/bulk', ['amount' => 0] + $dasar + ['dates' => ['2026-09-09']], $owner)
            ->assertStatus(422);
        $this->postJson('/api/wage-adjustments/bulk', $dasar + ['dates' => ['2026-09-09']], $this->header('kasir'))
            ->assertStatus(403);

        $this->assertSame(0, WageAdjustment::count());
    }

    /**
     * Rekap upah untuk tanggal pilihan (loncat-loncat): hari di antaranya
     * yang tidak dipilih tidak ikut dijumlahkan, dan potongan tetap
     * diterapkan per tanggal.
     */
    public function test_rekap_upah_untuk_tanggal_pilihan(): void
    {
        $this->siapkan();
        $budi = Worker::create(['name' => 'Budi']);
        foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $t) {
            $this->travelTo($t.' 10:00:00');
            $this->cuci($budi);
        }
        $this->travelBack();
        WageAdjustment::create([
            'worker_id' => $budi->id, 'worker_name' => 'Budi',
            'type' => 'potongan', 'amount' => 5000, 'date' => '2026-09-10',
        ]);

        $baris = $this->getJson('/api/reports/wages?dates[]=2026-09-08&dates[]=2026-09-10',
            $this->header('owner'))->assertOk()->json('data.0');

        $this->assertSame('Budi', $baris['name']);
        $this->assertSame(['2026-09-08', '2026-09-10'], array_column($baris['daily_breakdown'], 'date'));
        $this->assertSame(2, $baris['total_vehicles']);
        $this->assertSame(40000, $baris['total_gross']);
        $this->assertSame(5000, $baris['total_penalty']);
        $this->assertSame(5000, $baris['total_penalty_applied']);
        $this->assertSame(35000, $baris['total_wage']);

        // Cara lama (from/to) tetap jalan — dipakai aplikasi Android.
        $this->getJson('/api/reports/wages?from=2026-09-08&to=2026-09-10', $this->header('owner'))
            ->assertOk()->assertJsonPath('data.0.total_vehicles', 3);
    }

    /**
     * "Dipotong berapa" harus angka yang benar-benar memotong: potongan yang
     * melebihi upah tidak menahan uang lebih dari upah itu sendiri.
     */
    public function test_potongan_terpakai_tidak_melebihi_upah(): void
    {
        $this->siapkan();
        $budi = Worker::create(['name' => 'Budi']);
        $andi = Worker::create(['name' => 'Andi']);
        $this->cuci($budi);                       // upah hitungan Budi 20.000
        $h = $this->header('owner');
        $catat = fn (Worker $w, string $jenis, int $jumlah) => $this->postJson('/api/wage-adjustments',
            ['worker_id' => $w->id, 'type' => $jenis, 'amount' => $jumlah], $h)->assertCreated();

        $catat($budi, 'potongan', 30000);         // tercatat 30.000, terpakai 20.000
        $catat($andi, 'timpa', 10000);            // Andi tidak mencuci, upah ditimpa 10.000
        $catat($andi, 'potongan', 15000);         // terpakai 10.000 dari dasar timpa

        $rekap = collect($this->getJson('/api/reports/daily?date='.now()->toDateString(), $h)
            ->assertOk()->json('data.worker_wages'))->keyBy('name');

        $this->assertSame(30000, $rekap['Budi']['penalty']);
        $this->assertSame(20000, $rekap['Budi']['penalty_applied']);
        $this->assertSame(0, $rekap['Budi']['wage']);
        $this->assertSame(10000, $rekap['Andi']['penalty_applied']);

        $rentang = collect($this->getJson('/api/reports/wages?dates[]='.now()->toDateString(), $h)
            ->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(20000, $rentang['Budi']['total_penalty_applied']);
        $this->assertSame(10000, $rentang['Andi']['total_penalty_applied']);
    }

    public function test_hapus_sekaligus_menolak_daftar_kosong(): void
    {
        $h = $this->header('owner');

        $this->postJson('/api/wage-adjustments/bulk-delete', ['ids' => []], $h)->assertStatus(422);
        $this->postJson('/api/wage-adjustments/bulk-delete', [], $h)->assertStatus(422);
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

    /**
     * Rentang sebulan (dipakai titik merah kalender Upah & potongan): hanya
     * catatan di dalam rentang. Angka 'timpa' hanya dihitung, tidak ikut
     * dijumlahkan — itu upah pengganti, bukan uang yang ditahan.
     */
    public function test_ringkasan_untuk_rentang(): void
    {
        $budi = Worker::create(['name' => 'Budi']);
        $andi = Worker::create(['name' => 'Andi']);
        $catat = fn (Worker $w, string $jenis, int $jumlah, string $tgl) => WageAdjustment::create([
            'worker_id' => $w->id, 'worker_name' => $w->name,
            'type' => $jenis, 'amount' => $jumlah, 'date' => $tgl,
        ]);

        $catat($budi, 'potongan', 5000, '2026-09-02');
        $catat($budi, 'potongan', 3000, '2026-09-15');
        $catat($budi, 'timpa', 50000, '2026-09-20');
        $catat($andi, 'potongan', 10000, '2026-09-10');
        $catat($andi, 'potongan', 99000, '2026-10-01');   // di luar rentang

        $res = $this->getJson('/api/wage-adjustments?from=2026-09-01&to=2026-09-30',
            $this->header('owner'))->assertOk();

        $this->assertCount(4, $res->json('data'));
        $this->assertSame(18000, $res->json('summary.total_potongan'));
        $this->assertSame(1, $res->json('summary.jumlah_timpa'));
    }
}

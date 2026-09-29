<?php

namespace Tests\Feature;

use App\Models\WageRate;
use App\Models\Worker;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use App\Services\TransactionService;
use App\Services\WageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembagian upah satu cucian antara karyawan tetap (senior) dan training.
 *
 * Aturan yang dijaga (keputusan owner 2026-09-29): angka training berlaku
 * untuk SETIAP anak training — "3k per orang, bukan 3k dibagi tiga" — sisanya
 * dibagi rata ke senior, dan anak training tidak pernah dapat lebih dari
 * bagiannya seandainya semua dibagi rata.
 */
class TraineeWageSplitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{senior:int[], training:int[], total:int} */
    private function bagi(int $jatah, int $jatahTraining, int $senior, int $training): array
    {
        // Bukan range(1, $senior): range(1, 0) di PHP = [1, 0], bukan kosong.
        $idSenior   = $senior   ? range(1, $senior) : [];
        $idTraining = $training ? range(100, 99 + $training) : [];
        $hasil = app(WageService::class)->bagiUpah($jatah, $jatahTraining,
            array_merge($idSenior, $idTraining), $idTraining);

        return [
            'senior'   => array_values(array_intersect_key($hasil, array_flip($idSenior))),
            'training' => array_values(array_intersect_key($hasil, array_flip($idTraining))),
            'total'    => array_sum($hasil),
        ];
    }

    /** Contoh owner: upah Rp 50.000, 5 tetap + 3 training, training Rp 3.000. */
    public function test_training_dapat_nominalnya_masing_masing(): void
    {
        $h = $this->bagi(50000, 3000, 5, 3);

        $this->assertSame([3000, 3000, 3000], $h['training'], 'Rp 3.000 per orang, bukan dibagi tiga.');
        $this->assertSame([8200, 8200, 8200, 8200, 8200], $h['senior']);
        $this->assertSame(50000, $h['total']);
    }

    public function test_tanpa_training_dibagi_rata(): void
    {
        $h = $this->bagi(15000, 2000, 2, 0);

        $this->assertSame([7500, 7500], $h['senior']);
    }

    /** Training sendirian tetap hanya dapat jatah training; sisanya milik owner. */
    public function test_training_sendirian_hanya_dapat_jatahnya(): void
    {
        $h = $this->bagi(15000, 2000, 0, 2);

        $this->assertSame([2000, 2000], $h['training']);
        $this->assertSame(4000, $h['total'], 'Sisa Rp 11.000 tidak dibagikan ke siapa pun.');
    }

    /** Jatah training di atas bagian rata dipotong: training tidak pernah melebihi senior. */
    public function test_training_tidak_pernah_melebihi_bagian_rata(): void
    {
        $motor = $this->bagi(5000, 5000, 1, 1);
        $this->assertSame([2500], $motor['training']);
        $this->assertSame([2500], $motor['senior']);

        // Banyak anak training pada cucian kecil: tiap orang maksimal 15.000 / 5.
        $ramai = $this->bagi(15000, 5000, 1, 4);
        $this->assertSame([3000, 3000, 3000, 3000], $ramai['training']);
        $this->assertSame([3000], $ramai['senior']);
        $this->assertSame(15000, $ramai['total']);
    }

    /** Lewat jalur sebenarnya: transaksi dicatat, upah tersimpan per pekerja. */
    public function test_upah_tersimpan_saat_transaksi_dicatat(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        WashService::firstOrCreate(['slug' => 'reguler'], ['label' => 'Cuci Reguler', 'sort_order' => 1]);
        WashPrice::updateOrCreate(['category_slug' => 'kecil', 'service_slug' => 'reguler'], ['price' => 50000]);
        WageRate::updateOrCreate(['category' => 'kecil', 'service' => 'reguler'],
            ['amount' => 20000, 'trainee_amount' => 3000]);

        $senior = Worker::create(['name' => 'Budi']);
        $a = Worker::create(['name' => 'Anak A', 'is_trainee' => true]);
        $b = Worker::create(['name' => 'Anak B', 'is_trainee' => true]);

        $trx = app(TransactionService::class)->create([
            'vehicle_name' => 'Avanza', 'category' => 'kecil', 'service' => 'reguler',
            'payment_method' => 'cash', 'worker_ids' => [$senior->id, $a->id, $b->id],
        ]);

        $upah = $trx->workers()->pluck('wage_share', 'workers.id');
        $this->assertSame(3000, (int) $upah[$a->id]);
        $this->assertSame(3000, (int) $upah[$b->id]);
        $this->assertSame(14000, (int) $upah[$senior->id]);
    }
}

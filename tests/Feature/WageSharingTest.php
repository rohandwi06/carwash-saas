<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\Worker;
use App\Services\AuthTokenService;
use App\Services\CashBookService;
use App\Services\WageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cara hitung upah: bagi hasil persenan ATAU upah per mobil.
 *
 * Yang dijaga:
 *  - tanpa pernah menyentuh pengaturan, hitungannya persis seperti dulu
 *    (nominal per jenis kendaraan) — cucian yang tidak memakai fitur ini
 *    tidak boleh berubah upahnya;
 *  - persenan dihitung dari total transaksi cuci (harga cuci + add-on);
 *  - mengganti cara hitung tidak mengubah upah transaksi yang sudah tercatat.
 */
class WageSharingTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function cucian(int $total): Transaction
    {
        return Transaction::create([
            'queue_no' => 1, 'vehicle_name' => 'Toyota Avanza', 'category' => 'mobil',
            'service' => 'reguler', 'payment_method' => 'cash', 'plate' => 'B 1 XY',
            'tip' => 0, 'total' => $total, 'date' => now()->toDateString(),
            'book_id' => app(CashBookService::class)->current()->id,
        ]);
    }

    /** @return int[] */
    private function duaPekerja(): array
    {
        return [Worker::create(['name' => 'Adi'])->id, Worker::create(['name' => 'Budi'])->id];
    }

    public function test_bawaan_tetap_upah_per_mobil(): void
    {
        WageRate::create(['category' => 'mobil', 'service' => 'reguler', 'amount' => 16000]);
        $wages = app(WageService::class);

        $this->assertSame(WageService::MODE_NOMINAL, $wages->sharing()['mode']);
        $this->assertSame(16000, $wages->jatahFor('mobil', 'reguler', 50000),
            'Tanpa pengaturan, harga cucinya tidak memengaruhi upah.');
    }

    public function test_persenan_dihitung_dari_total_cuci_dan_addon(): void
    {
        WageRate::create(['category' => 'mobil', 'service' => 'reguler', 'amount' => 16000]);
        $wages = app(WageService::class);
        $wages->setSharing(WageService::MODE_PERSEN, 40);

        // Mobil Rp 40.000 + semir ban Rp 10.000 = Rp 50.000 -> 40% = Rp 20.000.
        $this->assertSame(20000, $wages->jatahFor('mobil', 'reguler', 50000));
        // Pecahan rupiah dibuang, tidak dibulatkan ke atas.
        $wages->setSharing(WageService::MODE_PERSEN, 33);
        $this->assertSame(3333, $wages->jatahFor('mobil', 'reguler', 10100));
    }

    public function test_upah_dibagi_rata_dari_persenan(): void
    {
        $wages = app(WageService::class);
        $wages->setSharing(WageService::MODE_PERSEN, 40);
        $trx = $this->cucian(50000);

        $wages->attachWorkers($trx, $this->duaPekerja());

        $this->assertSame([10000, 10000],
            $trx->workers()->pluck('wage_share')->map(fn ($v) => (int) $v)->all());
    }

    public function test_ganti_cara_hitung_tidak_mengubah_transaksi_lama(): void
    {
        WageRate::create(['category' => 'mobil', 'service' => 'reguler', 'amount' => 16000]);
        $wages = app(WageService::class);
        $trx = $this->cucian(50000);
        $wages->attachWorkers($trx, $this->duaPekerja());   // nominal: 16.000 / 2

        $wages->setSharing(WageService::MODE_PERSEN, 60);

        $this->assertSame(16000, $wages->dailyTotal(now()->toDateString()),
            'Upah yang sudah tercatat dibekukan di transaksinya.');
    }

    public function test_hanya_owner_yang_boleh_mengubah(): void
    {
        $this->putJson('/api/wage-sharing', ['mode' => 'persen', 'percent' => 40], $this->header('kasir'))
            ->assertStatus(403);

        $this->putJson('/api/wage-sharing', ['mode' => 'persen', 'percent' => 40], $this->header('owner'))
            ->assertOk()->assertJsonPath('data.mode', 'persen')->assertJsonPath('data.percent', 40);

        // Kembali ke upah per mobil: angka persennya tetap tersimpan.
        $this->putJson('/api/wage-sharing', ['mode' => 'nominal'], $this->header('owner'))
            ->assertOk()->assertJsonPath('data.mode', 'nominal')->assertJsonPath('data.percent', 40);
    }

    public function test_persen_di_luar_batas_ditolak(): void
    {
        $this->putJson('/api/wage-sharing', ['mode' => 'persen', 'percent' => 150], $this->header('owner'))
            ->assertStatus(422);
        $this->putJson('/api/wage-sharing', ['mode' => 'persen'], $this->header('owner'))
            ->assertStatus(422);
        $this->putJson('/api/wage-sharing', ['mode' => 'lain'], $this->header('owner'))
            ->assertStatus(422);
    }
}

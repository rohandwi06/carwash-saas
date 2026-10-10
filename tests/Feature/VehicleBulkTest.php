<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Models\WashCategory;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner memilih banyak mobil di Pengaturan lalu menghapus atau memindah
 * jenisnya sekaligus — termasuk "pilih semua" lintas halaman.
 */
class VehicleBulkTest extends TestCase
{
    use RefreshDatabase;

    private array $jenis;

    protected function setUp(): void
    {
        parent::setUp();
        Vehicle::query()->delete();
        $this->jenis = WashCategory::urut()->pluck('slug')->all();
    }

    private function sebagai(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, 'Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function mobil(string $nama, bool $perluCek = false): Vehicle
    {
        return Vehicle::create(['name' => $nama, 'category' => $this->jenis[0], 'needs_review' => $perluCek]);
    }

    public function test_hapus_hanya_mobil_yang_dipilih(): void
    {
        $a = $this->mobil('Toyota Avanza');
        $b = $this->mobil('Toyota Calya');
        $c = $this->mobil('Honda Brio');

        $this->postJson('/api/vehicles/massal', ['aksi' => 'hapus', 'ids' => [$a->id, $b->id]], $this->sebagai('owner'))
            ->assertOk()->assertJsonPath('data.jumlah', 2);

        $this->assertSame([$c->id], Vehicle::pluck('id')->all());
    }

    public function test_pindah_jenis_dan_tanda_perlu_cek_lunas(): void
    {
        $a = $this->mobil('Toyota Avanza', true);
        $b = $this->mobil('Honda Brio');

        $this->postJson('/api/vehicles/massal', [
            'aksi' => 'pindah', 'ids' => [$a->id], 'category' => $this->jenis[1],
        ], $this->sebagai('owner'))->assertOk()->assertJsonPath('data.jumlah', 1);

        $this->assertSame($this->jenis[1], $a->fresh()->category);
        $this->assertFalse((bool) $a->fresh()->needs_review);
        $this->assertSame($this->jenis[0], $b->fresh()->category);
    }

    public function test_pilih_semua_mengikuti_kata_cari(): void
    {
        $this->mobil('Toyota Avanza');
        $this->mobil('Toyota Calya');
        $brio = $this->mobil('Honda Brio');

        $this->postJson('/api/vehicles/massal', ['aksi' => 'hapus', 'semua' => true, 'q' => 'toyota'], $this->sebagai('owner'))
            ->assertOk()->assertJsonPath('data.jumlah', 2);
        $this->assertSame([$brio->id], Vehicle::pluck('id')->all());

        $this->postJson('/api/vehicles/massal', ['aksi' => 'hapus', 'semua' => true], $this->sebagai('owner'))
            ->assertOk()->assertJsonPath('data.jumlah', 1);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_tanpa_pilihan_atau_jenis_ditolak(): void
    {
        $a = $this->mobil('Toyota Avanza');

        $this->postJson('/api/vehicles/massal', ['aksi' => 'hapus'], $this->sebagai('owner'))->assertStatus(422);
        $this->postJson('/api/vehicles/massal', ['aksi' => 'pindah', 'ids' => [$a->id]], $this->sebagai('owner'))->assertStatus(422);
        $this->postJson('/api/vehicles/massal', ['aksi' => 'pindah', 'ids' => [$a->id], 'category' => 'tidak-ada'], $this->sebagai('owner'))->assertStatus(422);

        $this->assertSame(1, Vehicle::count());
    }

    public function test_kasir_tidak_boleh(): void
    {
        $a = $this->mobil('Toyota Avanza');

        $this->postJson('/api/vehicles/massal', ['aksi' => 'hapus', 'ids' => [$a->id]], $this->sebagai('kasir'))->assertForbidden();

        $this->assertSame(1, Vehicle::count());
    }
}

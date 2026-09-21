<?php

namespace Tests\Feature;

use App\Services\AuthTokenService;
use App\Services\BusinessProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Identitas usaha harus datang dari data, bukan dari kode: aplikasi yang sama
 * dipasang di banyak cucian (docs/ARCHITECTURE.md). Kalau satu saja tempat
 * masih menulis nama tetap, cucian kedua mencetak resi bernama cucian pertama.
 */
class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, ucfirst($role).' Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_sebelum_diatur_nama_diambil_dari_app_name(): void
    {
        config(['app.name' => 'SINAR CARWASH']);

        $this->getJson('/api/business-profile', $this->header('kasir'))
            ->assertOk()
            ->assertJsonPath('data.name', 'SINAR CARWASH')
            ->assertJsonPath('data.tagline', 'Cuci Mobil & Motor')
            ->assertJsonPath('data.address', '');
    }

    public function test_nama_bawaan_framework_tidak_pernah_tampil(): void
    {
        config(['app.name' => 'Laravel']);

        $this->assertSame('CARWASH', app(BusinessProfileService::class)->name());
    }

    public function test_owner_mengubah_profil_dan_semua_role_membacanya(): void
    {
        $this->putJson('/api/business-profile', [
            'name'    => 'Budi Carwash',
            'tagline' => '',
            'address' => 'Jl. Merdeka 1',
            'phone'   => '0812',
        ], $this->header('owner'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi Carwash');

        // Keterangan yang sengaja dikosongkan tetap kosong, tidak kembali
        // ke "Cuci Mobil & Motor".
        $this->getJson('/api/business-profile', $this->header('kasir'))
            ->assertJsonPath('data.tagline', '')
            ->assertJsonPath('data.address', 'Jl. Merdeka 1')
            ->assertJsonPath('data.phone', '0812');
    }

    public function test_kasir_tidak_boleh_mengubah_profil(): void
    {
        $this->putJson('/api/business-profile', ['name' => 'Diambil alih'], $this->header('kasir'))
            ->assertForbidden();
    }

    public function test_nama_wajib_diisi(): void
    {
        $this->putJson('/api/business-profile', ['name' => '  '], $this->header('owner'))
            ->assertUnprocessable();
    }

    public function test_halaman_kasir_memakai_nama_usaha(): void
    {
        app(BusinessProfileService::class)->update(['name' => 'Budi Carwash']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Budi Carwash — Kasir</title>', false)
            ->assertSee('Budi <span class="kuning">Carwash</span>', false)
            ->assertDontSee('OTIN');
    }

    public function test_laporan_csv_memakai_nama_usaha(): void
    {
        app(BusinessProfileService::class)->update(['name' => 'Budi Carwash']);

        $res = $this->get('/api/reports/daily/csv?date=2026-09-21', $this->header('owner'))->assertOk();

        $this->assertStringContainsString('laporan-budi-carwash-2026-09-21.csv', $res->headers->get('content-disposition'));
        $this->assertStringContainsString('LAPORAN HARIAN BUDI CARWASH', $res->streamedContent());
        $this->assertStringNotContainsString('OTIN', $res->streamedContent());
    }

    public function test_zona_waktu_mengikuti_app_timezone(): void
    {
        // config/app.php membaca env('APP_TIMEZONE'); yang diuji di sini adalah
        // bahwa nilainya memang dari env, bukan tertulis mati Asia/Jakarta.
        $isi = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString("'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta')", $isi);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AuthTokenService;
use App\Services\LanggananService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kunci langganan cucian yang mendaftar sendiri (keputusan owner 2026-10-19):
 * boleh mengatur, belum boleh mencatat uang sebelum bayar.
 */
class LanggananTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function l(): LanggananService
    {
        return app(LanggananService::class);
    }

    public function test_cucian_lama_tanpa_setelan_tidak_pernah_dikunci(): void
    {
        $this->assertFalse($this->l()->status()['wajib']);
        $this->postJson('/api/expenses', [], $this->owner())->assertStatus(422); // sampai ke validasi, bukan 402
        $this->getJson('/api/langganan', $this->owner())
            ->assertOk()->assertJsonPath('data.aktif', true)->assertJsonPath('data.pesan', null);
    }

    public function test_belum_bayar_pencatatan_ditolak_pengaturan_tetap_boleh(): void
    {
        $this->l()->mulaiTerkunci();
        $h = $this->owner();

        foreach (['/api/transactions', '/api/fnb-sales', '/api/expenses', '/api/drafts', '/api/transactions/backdated'] as $url) {
            $this->postJson($url, [], $h)->assertStatus(402)
                ->assertJsonPath('message', LanggananService::PESAN_OWNER);
        }

        // Pesan netral: tanpa harga, tanpa tautan.
        $this->assertDoesNotMatchRegularExpression('/rp|http|bayar/i', LanggananService::PESAN_OWNER.LanggananService::PESAN_KASIR);

        // Mengatur tetap jalan.
        $this->putJson('/api/business-profile', ['name' => 'Cucian Baru'], $h)->assertOk();
        $this->getJson('/api/reports/daily', $h)->assertOk();
        $this->getJson('/api/langganan', $h)->assertOk()
            ->assertJsonPath('data.wajib', true)->assertJsonPath('data.aktif', false)
            ->assertJsonPath('data.pesan', LanggananService::PESAN_OWNER);
    }

    public function test_perpanjang_dari_hari_ini_lalu_disambung(): void
    {
        CarbonImmutable::setTestNow('2026-10-19 10:00:00');
        $this->l()->mulaiTerkunci();

        $this->assertSame('2026-11-17', $this->l()->perpanjang(30)); // 30 hari termasuk hari ini
        $this->assertTrue($this->l()->aktif());
        $this->assertSame('2026-12-17', $this->l()->perpanjang(30)); // bayar lebih awal tidak hangus

        CarbonImmutable::setTestNow('2026-12-18 08:00:00');
        $this->assertFalse($this->l()->aktif());                     // habis -> terkunci lagi
        $this->assertSame('2027-01-16', $this->l()->perpanjang(30)); // mulai lagi dari hari ini
        CarbonImmutable::setTestNow();
    }

    public function test_aktivasi_dari_pusat_wajib_bertanda_tangan_dan_tidak_dobel(): void
    {
        config(['carwash.langganan.kunci' => 'kunci-uji']);
        $this->l()->mulaiTerkunci();

        $kirim = function (array $isi, ?string $kunci = 'kunci-uji') {
            $badan = json_encode($isi);

            return $this->call('POST', '/api/langganan/perpanjang', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TANDA' => $kunci ? hash_hmac('sha256', $badan, $kunci) : '',
            ], $badan);
        };

        $isi = ['pesanan' => 'ORD-1', 'hari' => 30, 'waktu' => time()];
        $kirim($isi, 'kunci-salah')->assertStatus(403);
        $kirim(['pesanan' => 'ORD-0', 'hari' => 30, 'waktu' => time() - 3600])->assertStatus(422);
        $this->assertFalse($this->l()->aktif());

        $kirim($isi)->assertOk()->assertJsonPath('data.aktif', true)->assertJsonPath('data.ulang', false);
        $sampai = Setting::get(LanggananService::KEY);
        $kirim($isi)->assertOk()->assertJsonPath('data.ulang', true);
        $this->assertSame($sampai, Setting::get(LanggananService::KEY));

        $this->postJson('/api/expenses', [], $this->owner())->assertStatus(422); // terbuka
    }

    public function test_tanpa_kunci_di_env_rute_aktivasi_tidak_ada(): void
    {
        config(['carwash.langganan.kunci' => '']);
        $this->postJson('/api/langganan/perpanjang', ['pesanan' => 'x', 'hari' => 30, 'waktu' => time()])
            ->assertStatus(404);
    }
}

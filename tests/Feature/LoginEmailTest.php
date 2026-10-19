<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Login aplikasi dengan email owner + buku alamat email -> server
 * (permintaan owner 2026-10-19).
 */
class LoginEmailTest extends TestCase
{
    use RefreshDatabase;

    private array $kePusat = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'carwash.owner.username' => 'pemilik',
            'carwash.owner.password' => 'rahasia123',
            'carwash.direktori.url' => 'https://pusat.test/direktori',
        ]);
        Http::fake(function (HttpRequest $r) {
            $this->kePusat[] = $r->data();

            return Http::response(['ok' => true]);
        });
    }

    private function header(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function aturEmail(?string $email): array
    {
        return $this->putJson('/api/owner-account', [
            'current_password' => 'rahasia123',
            'username' => 'pemilik',
            'email' => $email,
        ], $this->header())->assertOk()->json('data');
    }

    public function test_email_owner_disimpan_dan_didaftarkan_ke_buku_alamat(): void
    {
        $r = $this->aturEmail('Dimas@Contoh.ID ');

        $this->assertSame('dimas@contoh.id', $r['email']);
        $this->assertTrue($r['direktori']['ok']);
        $this->assertCount(1, $this->kePusat);
        $this->assertSame('dimas@contoh.id', $this->kePusat[0]['email']);
        $this->assertStringStartsWith('http://localhost', $this->kePusat[0]['server'], 'Alamat cucian ini, dari permintaan owner.');

        // Email sama disimpan lagi: tidak didaftarkan ulang.
        $this->aturEmail('dimas@contoh.id');
        $this->assertCount(1, $this->kePusat);

        $this->assertSame('dimas@contoh.id', $this->getJson('/api/owner-account', $this->header())->json('data.email'));
    }

    public function test_owner_login_dengan_email_atau_username(): void
    {
        $this->aturEmail('dimas@contoh.id');

        $this->postJson('/api/login', ['username' => 'DIMAS@contoh.id', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.role', 'owner');
        $this->postJson('/api/login', ['username' => 'pemilik', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.role', 'owner');
        $this->postJson('/api/login', ['username' => 'dimas@contoh.id', 'password' => 'salah'])
            ->assertStatus(401);
    }

    public function test_tanpa_email_owner_login_email_ditolak_dan_kasir_tetap_username(): void
    {
        $this->postJson('/api/login', ['username' => 'dimas@contoh.id', 'password' => 'rahasia123'])->assertStatus(401);

        User::create(['name' => 'Dina', 'username' => 'dina', 'email' => 'dina@x.test', 'role' => 'kasir',
            'password' => Hash::make('kasir123')]);
        $this->postJson('/api/login', ['username' => 'dina', 'password' => 'kasir123'])
            ->assertOk()->assertJsonPath('data.role', 'kasir');
    }

    public function test_cek_direktori_hanya_membenarkan_email_owner(): void
    {
        $this->aturEmail('dimas@contoh.id');

        $this->getJson('/api/direktori/cek?email=Dimas@contoh.id')
            ->assertOk()->assertJsonPath('data.cocok', true);
        $lain = $this->getJson('/api/direktori/cek?email=orang@lain.id')->assertOk()->json('data');
        $this->assertFalse($lain['cocok']);
        $this->assertNull($lain['nama'], 'Nama usaha tidak dibocorkan untuk email yang salah.');
    }

    public function test_email_tidak_sah_ditolak(): void
    {
        $this->putJson('/api/owner-account', [
            'current_password' => 'rahasia123', 'username' => 'pemilik', 'email' => 'bukan-email',
        ], $this->header())->assertStatus(422);
        $this->assertCount(0, $this->kePusat);
    }
}

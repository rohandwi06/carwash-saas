<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Situs demo (APP_ENV=demo) memakai kode yang sama dengan instalasi cucian.
 * Yang dijaga di sini: perilaku khusus demo TIDAK PERNAH bocor ke instalasi
 * sungguhan — terutama demo:reset yang menghapus seluruh database.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_demo_reset_menolak_di_luar_demo_dan_data_utuh(): void
    {
        Transaction::create([
            'queue_no' => 1, 'vehicle_name' => 'Uji', 'category' => 'kecil', 'service' => 'reguler',
            'payment_method' => 'cash', 'total' => 35000, 'date' => now()->toDateString(),
        ]);

        foreach (['production', 'local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->artisan('demo:reset')->assertFailed();
        }

        $this->assertSame(1, Transaction::count());
    }

    public function test_akun_dikunci_hanya_di_demo(): void
    {
        config(['carwash.owner.username' => 'owner', 'carwash.owner.password' => 'rahasia-owner']);
        $kasir = User::create(['name' => 'Dina', 'username' => 'dina',
            'password' => Hash::make('kasir123'), 'role' => 'kasir']);

        $this->app['env'] = 'demo';
        $this->putJson('/api/owner-account', ['current_password' => 'rahasia-owner', 'username' => 'owner', 'password' => 'diganti123'], $this->owner())
            ->assertForbidden();
        $this->patchJson('/api/users/'.$kasir->id, ['name' => 'Dina', 'username' => 'dina', 'password' => 'diganti'], $this->owner())
            ->assertForbidden();
        $this->deleteJson('/api/users/'.$kasir->id, [], $this->owner())->assertForbidden();
        $this->assertTrue(Hash::check('kasir123', $kasir->fresh()->password));

        // Di instalasi sungguhan owner tetap bebas mengatur akunnya.
        $this->app['env'] = 'production';
        $this->patchJson('/api/users/'.$kasir->id, ['name' => 'Dina', 'username' => 'dina', 'password' => 'diganti'], $this->owner())
            ->assertOk();
    }

    /**
     * Masuk tanpa password HANYA di demo. Kalau jalan ini bocor ke instalasi
     * sungguhan, siapa pun di internet bisa membuka pembukuan cucian itu.
     */
    public function test_masuk_tanpa_password_hanya_di_demo(): void
    {
        User::create(['name' => 'Dina', 'username' => 'dina',
            'password' => Hash::make('kasir123'), 'role' => 'kasir']);

        foreach (['production', 'local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->postJson('/api/demo-login', ['role' => 'owner'])->assertNotFound();
            $this->postJson('/api/demo-login', ['role' => 'kasir'])->assertNotFound();
        }

        $this->app['env'] = 'demo';
        $owner = $this->postJson('/api/demo-login', ['role' => 'owner'])->assertOk()->json('data');
        $this->assertSame('owner', $owner['role']);
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$owner['token']])
            ->assertOk()->assertJsonPath('data.role', 'owner');

        $kasir = $this->postJson('/api/demo-login', ['role' => 'kasir'])->assertOk()->json('data');
        $this->assertSame(['kasir', 'Dina'], [$kasir['role'], $kasir['name']]);

        $this->postJson('/api/demo-login', ['role' => 'admin'])->assertStatus(422);
    }

    /** Halaman: penanda demo & tombol pindah peran hanya di demo; "Keluar" di tempat lain. */
    public function test_halaman_demo_tanpa_tombol_keluar(): void
    {
        $this->app['env'] = 'production';
        $this->get('/')->assertOk()->assertSee('window.DEMO = false', false)
            ->assertDontSee('gantiPeranDemo')->assertSee('keluarApp()');

        $this->app['env'] = 'demo';
        $this->get('/')->assertOk()->assertSee('window.DEMO = true', false)
            ->assertSee('gantiPeranDemo')->assertDontSee('keluarApp()');
    }

    public function test_info_login_hanya_tampil_di_demo(): void
    {
        config(['carwash.owner.username' => 'owner', 'carwash.owner.password' => 'rahasia-owner']);

        $this->app['env'] = 'production';
        $this->get('/')->assertOk()->assertDontSee('Versi demo')->assertDontSee('rahasia-owner')
            ->assertDontSee('isiAkunDemo');

        // Di demo: tiap akun dijelaskan dan bisa diketuk untuk mengisi kolom
        // login — password owner ada di data-p tombolnya, bukan cuma di teks.
        $this->app['env'] = 'demo';
        $this->get('/')->assertOk()->assertSee('Versi demo')->assertSee('rahasia-owner')
            ->assertSee('data-p="rahasia-owner"', false)
            ->assertSee('data-u="dina" data-p="kasir123"', false)
            ->assertSee('pemilik usaha')->assertSee('karyawan jaga');
    }
}

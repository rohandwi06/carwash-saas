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

    public function test_info_login_hanya_tampil_di_demo(): void
    {
        config(['carwash.owner.username' => 'owner', 'carwash.owner.password' => 'rahasia-owner']);

        $this->app['env'] = 'production';
        $this->get('/')->assertOk()->assertDontSee('Versi demo')->assertDontSee('rahasia-owner');

        $this->app['env'] = 'demo';
        $this->get('/')->assertOk()->assertSee('Versi demo')->assertSee('rahasia-owner');
    }
}

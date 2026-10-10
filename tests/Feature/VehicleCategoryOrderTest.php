<?php

namespace Tests\Feature;

use App\Models\WashCategory;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner menggeser urutan jenis kendaraan di Pengaturan. Urutan itu dipakai
 * layar kasir (/api/config) dan pembukuan lewat sort_order.
 */
class VehicleCategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    private function sebagai(string $role): array
    {
        $t = app(AuthTokenService::class)->issue($role, 'Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    public function test_owner_menggeser_urutan_dan_layar_kasir_ikut(): void
    {
        $ids = WashCategory::urut()->pluck('id')->all();
        $this->assertGreaterThan(1, count($ids));
        $balik = array_reverse($ids);

        $this->putJson('/api/wash-categories/urutan', ['ids' => $balik], $this->sebagai('owner'))->assertOk();

        $this->assertSame($balik, WashCategory::urut()->pluck('id')->all());

        $slug = WashCategory::urut()->pluck('slug')->all();
        $config = $this->getJson('/api/config', $this->sebagai('owner'))->assertOk();
        $this->assertSame($slug, array_keys($config->json('data.categories') ?? $config->json('categories')));
    }

    public function test_daftar_tidak_lengkap_ditolak(): void
    {
        $ids = WashCategory::urut()->pluck('id')->all();
        $sebelum = $ids;
        array_pop($ids);

        $this->putJson('/api/wash-categories/urutan', ['ids' => $ids], $this->sebagai('owner'))->assertStatus(422);

        $this->assertSame($sebelum, WashCategory::urut()->pluck('id')->all());
    }

    public function test_kasir_tidak_boleh_mengubah_urutan(): void
    {
        $ids = array_reverse(WashCategory::urut()->pluck('id')->all());

        $this->putJson('/api/wash-categories/urutan', ['ids' => $ids], $this->sebagai('kasir'))->assertForbidden();
    }
}

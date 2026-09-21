<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Models\WashCategory;
use App\Services\AuthTokenService;
use App\Services\VehicleSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug nyata: WashCategoryController::destroy() hanya mengecek Transaction,
 * tidak mengecek katalog Vehicle. Kategori bisa terhapus padahal masih
 * dipakai kendaraan di katalog, membuat frontend crash — "undefined is not
 * an object (evaluating 'CFG.categories[m.category].label')" — begitu kasir
 * mencari nama kendaraan itu, karena kategorinya sudah tidak ada di
 * /api/config.
 *
 * Ditemukan lewat 9 baris nyata (Alphard, Land Cruiser, dst.) yang masih
 * menunjuk kategori "besar" — kategori itu SUDAH ADA di migrasi awal
 * (create_wash_catalog_tables men-seed 4 kategori default termasuk
 * "besar", contohnya persis "Alphard · Hiace · Hilux DC"), lalu dihapus
 * owner dari Pengaturan sementara katalog kendaraan tetap menunjuk ke sana.
 */
class VehicleCategoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function ownerHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    /** "besar" sudah ada dari migrasi awal — di sini cukup diambil, bukan dibuat. */
    private function katBesar(): WashCategory
    {
        return WashCategory::where('slug', 'besar')->firstOrFail();
    }

    public function test_kategori_tidak_bisa_dihapus_kalau_masih_dipakai_kendaraan(): void
    {
        $kat = $this->katBesar();
        Vehicle::create(['name' => 'Alphard', 'category' => 'besar']);

        $this->deleteJson('/api/wash-categories/'.$kat->id, [], $this->ownerHeader())
            ->assertStatus(422)
            ->assertJsonFragment(['message' =>
                'Tidak bisa dihapus: masih dipakai 1 kendaraan di katalog '
                .'(mis. hasil pencarian nama mobil). Pindahkan dulu kendaraan itu ke '
                .'kategori lain di menu Pengaturan, atau hapus kendaraannya.']);

        $this->assertDatabaseHas('wash_categories', ['id' => $kat->id]);
    }

    public function test_kategori_boleh_dihapus_kalau_tidak_ada_kendaraan_terkait(): void
    {
        $kat = $this->katBesar();
        Vehicle::create(['name' => 'Avanza', 'category' => 'kecil']); // kategori lain, tidak relevan

        $this->deleteJson('/api/wash-categories/'.$kat->id, [], $this->ownerHeader())->assertOk();
        $this->assertDatabaseMissing('wash_categories', ['id' => $kat->id]);
    }

    /**
     * Lapis kedua: seandainya toh ada baris lama yang kategorinya sudah
     * tidak valid (persis skenario nyata — "besar" dihapus tapi katalog
     * masih menunjuk ke sana), server TIDAK BOLEH mengembalikannya lewat
     * pencarian — itulah yang membuat frontend crash.
     */
    public function test_pencarian_tidak_mengembalikan_kendaraan_berkategori_tidak_valid(): void
    {
        Vehicle::create(['name' => 'Toyota Alphard', 'category' => 'besar']);
        $this->katBesar()->delete(); // simulasikan keadaan nyata: kategorinya sudah terhapus

        $hasil = app(VehicleSearchService::class)->search('alphard');

        $this->assertCount(0, $hasil, 'Kendaraan berkategori tidak valid tidak boleh muncul di hasil cari.');
    }

    public function test_pencarian_tetap_mengembalikan_kendaraan_berkategori_valid(): void
    {
        WashCategory::firstOrCreate(['slug' => 'kecil'], ['label' => 'Mobil Kecil', 'sort_order' => 1]);
        Vehicle::create(['name' => 'Toyota Avanza', 'category' => 'kecil']);

        $hasil = app(VehicleSearchService::class)->search('avanza');

        $this->assertCount(1, $hasil);
        $this->assertSame('Toyota Avanza', $hasil->first()->name);
    }

    public function test_endpoint_search_tidak_crash_dengan_data_kotor(): void
    {
        $t = app(AuthTokenService::class)->issue('kasir', 'Kasir Uji');
        Vehicle::create(['name' => 'Toyota Hiace', 'category' => 'besar']);
        $this->katBesar()->delete(); // simulasikan keadaan nyata: kategorinya sudah terhapus

        $this->getJson('/api/vehicles/search?q=hiace', ['Authorization' => 'Bearer '.$t['token']])
            ->assertOk()
            ->assertJson(['data' => []]);
    }
}

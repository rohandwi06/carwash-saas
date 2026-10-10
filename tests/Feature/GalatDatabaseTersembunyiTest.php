<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Pesan galat database tidak boleh sampai ke pengunjung.
 *
 * bootstrap/app.php mengubah \RuntimeException pada api/* menjadi 422 berisi
 * pesannya, supaya kasir tahu aturan mana yang dilanggar. QueryException ikut
 * tertangkap karena turunan \RuntimeException — pesannya memuat SQL dan nama
 * tabel, dan terbaca tanpa login.
 */
class GalatDatabaseTersembunyiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seperti di hosting. Dengan APP_DEBUG=true Laravel memang sengaja
        // menampilkan semuanya.
        config(['app.debug' => false]);

        Route::get('/api/_uji/galat-database', fn () => DB::select('select * from tabel_yang_tidak_ada'));
        Route::get('/api/_uji/aturan-bisnis', fn () => throw new \RuntimeException('Stok tidak cukup.'));
    }

    public function test_galat_database_dibalas_500_tanpa_rincian(): void
    {
        $res = $this->getJson('/api/_uji/galat-database');

        $res->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('tabel_yang_tidak_ada', $res->getContent());
    }

    public function test_aturan_bisnis_tetap_422_dengan_pesannya(): void
    {
        $this->getJson('/api/_uji/aturan-bisnis')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Stok tidak cukup.']);
    }
}

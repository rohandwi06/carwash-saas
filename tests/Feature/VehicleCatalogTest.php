<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Siapa yang berhak menetapkan kategori sebuah mobil — dan karenanya harganya.
 *
 * Urutannya: owner (katalog) > AI > tidak ada.
 *
 * Dua transaksi nyata pada 30 Agustus 2026 membuktikan urutan itu dulu
 * terbalik: #666 "Toyota Raize" dan #670 "Honda City" tercatat sebagai mobil
 * kecil 35rb, padahal keduanya "sedang" 40rb di katalog. Kasir salah ketik
 * satu huruf, skor pencarian lokal jatuh di bawah ambang yakin, AI ikut
 * ditanya, dan jawaban AI yang dipakai — diam-diam, karena kartu AI tidak
 * pernah menyebut bahwa katalog berkata lain.
 */
class VehicleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function ownerHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function kasirHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('kasir', 'Kasir Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function palsukanGemini(string $nama, string $kategori): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'name'       => $nama,
                            'category'   => $kategori,
                            'reason'     => 'alasan uji',
                            'recognized' => true,
                        ]),
                    ]]],
                ]],
            ]),
        ]);
    }

    /** Persis kasus #666/#670: katalog bilang sedang, AI bilang kecil. */
    public function test_tebakan_ai_kalah_oleh_kategori_katalog(): void
    {
        Vehicle::create(['name' => 'Toyota Raize', 'category' => 'sedang']);
        $this->palsukanGemini('Toyota Raize', 'kecil');

        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/ai/classify-vehicle', ['query' => 'raise'])
            ->assertOk()
            ->assertJsonPath('data.category', 'sedang')
            ->assertJsonPath('data.price', 40000)          // bukan 35000
            ->assertJsonPath('data.dikoreksi_katalog', true)
            ->assertJsonPath('data.ai_category', 'kecil'); // tebakan AI tetap ditampilkan apa adanya
    }

    /** Kendaraan yang memang belum ada: tebakan AI tetap jalan keluarnya. */
    public function test_kendaraan_di_luar_katalog_tetap_memakai_tebakan_ai(): void
    {
        $this->palsukanGemini('Chevrolet Corvette', 'kecil');

        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/ai/classify-vehicle', ['query' => 'corvette'])
            ->assertOk()
            ->assertJsonPath('data.category', 'kecil')
            ->assertJsonPath('data.dikoreksi_katalog', false);
    }

    /** Ejaan katalog yang dipakai, supaya tidak lahir baris kembar. */
    public function test_nama_mengikuti_ejaan_katalog(): void
    {
        Vehicle::create(['name' => 'Honda City', 'category' => 'sedang']);
        $this->palsukanGemini('honda city', 'kecil');

        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/ai/classify-vehicle', ['query' => 'citi'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Honda City');
    }

    public function test_kendaraan_baru_dari_ai_ditandai_perlu_dicek(): void
    {
        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/vehicles', ['name' => 'Chevrolet Corvette', 'category' => 'kecil'])
            ->assertStatus(403); // kasir tidak berwenang menetapkan harga...

        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/ai/save-vehicle', ['name' => 'Chevrolet Corvette', 'category' => 'kecil'])
            ->assertStatus(201); // ...tapi tetap boleh melayani mobil di depannya

        $this->assertDatabaseHas('vehicles', [
            'name'         => 'Chevrolet Corvette',
            'category'     => 'kecil',
            'needs_review' => true,
        ]);
    }

    /** Kendaraan yang sudah dibenarkan owner tidak boleh ditarik mundur AI. */
    public function test_ai_tidak_mengubah_kendaraan_yang_sudah_ada(): void
    {
        Vehicle::create(['name' => 'Toyota Calya', 'category' => 'sedang']);

        $this->withHeaders($this->kasirHeader())
            ->postJson('/api/ai/save-vehicle', ['name' => 'Toyota Calya', 'category' => 'kecil'])
            ->assertStatus(201);

        $this->assertDatabaseHas('vehicles', [
            'name'         => 'Toyota Calya',
            'category'     => 'sedang',
            'needs_review' => false,
        ]);
    }

    /** Inti Langkah 2: Calya akhirnya bisa dipindahkan tanpa SQL manual. */
    public function test_owner_memindahkan_kendaraan_dan_tanda_perlu_dicek_lunas(): void
    {
        $v = Vehicle::create(['name' => 'Toyota Calya', 'category' => 'kecil', 'needs_review' => true]);

        Transaction::create([
            'queue_no' => 1, 'vehicle_name' => 'Toyota Calya', 'category' => 'kecil',
            'service' => 'reguler', 'payment_method' => 'cash', 'total' => 35000,
            'date' => now()->toDateString(),
        ]);

        $this->withHeaders($this->ownerHeader())
            ->patchJson('/api/vehicles/'.$v->id, ['category' => 'sedang'])
            ->assertOk()
            ->assertJsonPath('data.category', 'sedang')
            ->assertJsonPath('data.needs_review', false)
            // Transaksi lampau TIDAK ikut diubah — cuma dilaporkan jumlahnya.
            ->assertJsonPath('meta.transaksi_beda_kategori', 1);

        $this->assertDatabaseHas('transactions', ['vehicle_name' => 'Toyota Calya', 'total' => 35000]);
    }

    public function test_kasir_tidak_boleh_mengubah_katalog_kendaraan(): void
    {
        $v = Vehicle::create(['name' => 'Toyota Calya', 'category' => 'kecil']);

        $this->getJson('/api/vehicles', $this->kasirHeader())->assertStatus(403);
        $this->patchJson('/api/vehicles/'.$v->id, ['category' => 'sedang'], $this->kasirHeader())
            ->assertStatus(403);
        $this->deleteJson('/api/vehicles/'.$v->id, [], $this->kasirHeader())->assertStatus(403);

        $this->assertDatabaseHas('vehicles', ['name' => 'Toyota Calya', 'category' => 'kecil']);
    }

    public function test_daftar_katalog_menaikkan_yang_belum_dicek(): void
    {
        Vehicle::create(['name' => 'Aaa Duluan Abjadnya', 'category' => 'kecil']);
        Vehicle::create(['name' => 'Zzz Tebakan AI', 'category' => 'kecil', 'needs_review' => true]);

        $this->getJson('/api/vehicles', $this->ownerHeader())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Zzz Tebakan AI')
            ->assertJsonPath('meta.needs_review', 1);
    }

    public function test_kategori_harus_ada_di_daftar_jenis_kendaraan(): void
    {
        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/vehicles', ['name' => 'Mobil Karangan', 'category' => 'kategori-hantu'])
            ->assertStatus(422);
    }
}

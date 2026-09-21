<?php

namespace Tests\Feature;

use App\Models\WashCategory;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kuota gratis Gemini habis di server produksi pada 30 Agustus 2026.
 *
 * Sebabnya bukan trafik yang wajar: tiap pencarian yang meleset di katalog
 * lokal menembak API, TERMASUK salah eja yang sama diketik berkali-kali dalam
 * satu hari, dan termasuk kata yang tidak dikenali AI — jawaban "tidak
 * dikenali" tidak pernah disimpan, jadi kata yang sama membakar kuota lagi
 * setiap kali diketik.
 *
 * Tes ini menjaga DUA lapis penahan kuota tetap terpasang:
 *   1. ingatan (cache) di GeminiVehicleService;
 *   2. throttle di routes/api.php.
 *
 * Lapis kedua jadi penting sejak AI menyatu dengan kolom pencarian kasir:
 * permintaan tidak lagi lahir dari tombol yang ditekan, melainkan dari kasir
 * mengetik. Penahan utamanya ada di kasir.js (jeda 1,2 detik, minimal 4 huruf,
 * satu kata sekali per sesi) — tapi itu semua hidup di browser dan bisa
 * dilewati siapa pun yang memegang token, jadi servernya harus punya batas
 * sendiri.
 */
class AiVehicleCacheTest extends TestCase
{
    use RefreshDatabase;

    private function ownerHeader(): array
    {
        $t = app(AuthTokenService::class)->issue('owner', 'Owner Uji');

        return ['Authorization' => 'Bearer '.$t['token']];
    }

    private function palsukanGemini(string $nama, string $kategori, bool $dikenali = true): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'name'       => $nama,
                            'category'   => $kategori,
                            'reason'     => 'alasan uji',
                            'recognized' => $dikenali,
                        ]),
                    ]]],
                ]],
            ]),
        ]);
    }

    public function test_pertanyaan_sama_hanya_menembak_gemini_sekali(): void
    {
        $kat = WashCategory::urut()->firstOrFail();
        $this->palsukanGemini('Toyota Avanza', $kat->slug);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->ownerHeader())
                ->postJson('/api/ai/classify-vehicle', ['query' => 'avanza'])
                ->assertOk();
        }

        Http::assertSentCount(1);
    }

    public function test_ejaan_berbeda_tetap_satu_ingatan(): void
    {
        $kat = WashCategory::urut()->firstOrFail();
        $this->palsukanGemini('Toyota Avanza', $kat->slug);

        foreach (['avanza', '  AvAnZa  ', 'Avanza'] as $ketikan) {
            $this->withHeaders($this->ownerHeader())
                ->postJson('/api/ai/classify-vehicle', ['query' => $ketikan])
                ->assertOk();
        }

        Http::assertSentCount(1);
    }

    /** Yang paling boros dulu: kata tak dikenali dulu TIDAK pernah disimpan. */
    public function test_jawaban_tidak_dikenali_ikut_diingat(): void
    {
        $this->palsukanGemini('', '', false);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->ownerHeader())
                ->postJson('/api/ai/classify-vehicle', ['query' => 'qwertyuiop'])
                ->assertOk()
                ->assertJsonPath('data.recognized', false);
        }

        Http::assertSentCount(1);
    }

    public function test_pertanyaan_berbeda_tetap_menembak_gemini_lagi(): void
    {
        $kat = WashCategory::urut()->firstOrFail();
        $this->palsukanGemini('Toyota Avanza', $kat->slug);

        foreach (['avanza', 'xenia'] as $ketikan) {
            $this->withHeaders($this->ownerHeader())
                ->postJson('/api/ai/classify-vehicle', ['query' => $ketikan])
                ->assertOk();
        }

        Http::assertSentCount(2);
    }

    /**
     * Kalau owner mengubah daftar kategori, jawaban lama tidak boleh dipakai
     * lagi — jawaban itu dulu dipilih dari daftar pilihan yang sudah berbeda.
     */
    public function test_ingatan_hangus_saat_daftar_kategori_berubah(): void
    {
        $kat = WashCategory::urut()->firstOrFail();
        $this->palsukanGemini('Toyota Avanza', $kat->slug);

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/ai/classify-vehicle', ['query' => 'avanza'])
            ->assertOk();

        WashCategory::create([
            'slug'  => 'kategori-baru-uji',
            'label' => 'Kategori Baru Uji',
            'order' => 99,
        ]);

        $this->withHeaders($this->ownerHeader())
            ->postJson('/api/ai/classify-vehicle', ['query' => 'avanza'])
            ->assertOk();

        Http::assertSentCount(2);
    }

    /**
     * Sengaja memakai kata yang SELALU BERBEDA: cache tidak bisa menolong di
     * sini, persis seperti kasir yang mengetik nama-nama baru. Yang menahan
     * hanya throttle — dan tanpa throttle, tes ini akan menembak Gemini 21 kali.
     */
    public function test_permintaan_ai_dibatasi_agar_kuota_tidak_terkuras(): void
    {
        $kat = WashCategory::urut()->firstOrFail();
        $this->palsukanGemini('Toyota Avanza', $kat->slug);
        $header = $this->ownerHeader();

        for ($i = 0; $i < 20; $i++) {
            $this->withHeaders($header)
                ->postJson('/api/ai/classify-vehicle', ['query' => 'mobil-uji-'.$i])
                ->assertOk();
        }

        // Ketukan ke-21 dalam semenit ditolak SEBELUM sempat menembak Gemini.
        $this->withHeaders($header)
            ->postJson('/api/ai/classify-vehicle', ['query' => 'mobil-uji-21'])
            ->assertStatus(429);

        Http::assertSentCount(20);
    }
}

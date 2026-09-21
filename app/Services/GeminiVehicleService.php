<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tanya Gemini: kendaraan bernama X itu masuk kategori apa?
 * Dipanggil HANYA ketika fuzzy search lokal tidak menemukan hasil.
 * API key hidup di server (.env), tidak pernah dikirim ke browser.
 */
class GeminiVehicleService
{
    /** Jawaban disimpan sebulan: nama & kategori kendaraan praktis tidak berubah. */
    private const CACHE_HARI = 30;

    /**
     * Bungkus classify() dengan ingatan.
     *
     * Tanpa ini, tiap pencarian yang meleset di database lokal menembak API —
     * termasuk salah eja yang sama diketik berulang kali sepanjang hari, dan
     * termasuk kata yang JUSTRU tidak dikenali AI (jawabannya tidak pernah
     * disimpan, jadi kata itu membakar kuota lagi besok, lusa, seterusnya).
     * Kuota gratis Gemini habis karena ini pada 30 Agustus 2026.
     *
     * Kunci cache memuat sidik jari daftar kategori: begitu owner menambah atau
     * menghapus kategori kendaraan, seluruh jawaban lama otomatis tidak terpakai
     * lagi — sebab jawaban itu dulu dipilih dari daftar yang sudah berbeda.
     */
    public function classify(string $query): array
    {
        $rows = \App\Models\WashCategory::urut()->get();

        // Normalkan: "  AvAnza  " dan "avanza" satu ingatan yang sama.
        $normal = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
        $sidik  = md5($rows->pluck('slug')->implode(','));

        return Cache::remember(
            "ai-vehicle:{$sidik}:".md5($normal),
            now()->addDays(self::CACHE_HARI),
            fn () => $this->tanyaGemini($query, $rows),
        );
    }

    private function tanyaGemini(string $query, $rows): array
    {
        $key = config('carwash.gemini.key');

        if (! $key) {
            throw new RuntimeException('GEMINI_API_KEY belum diatur di .env');
        }

        $daftar = $rows->map(function ($c) {
            $contoh = $c->examples ? " (contoh: {$c->examples})" : '';

            return "   - \"{$c->slug}\" = {$c->label}{$contoh}";
        })->implode("\n");

        $pilihan = $rows->pluck('slug')->implode('|');

        $prompt = <<<PROMPT
Kamu adalah asisten kasir cuci kendaraan di Indonesia.
Kasir mengetik nama kendaraan: "{$query}"

Tugasmu:
1. Tebak kendaraan apa yang dimaksud (perbaiki salah eja, lengkapi merek).
2. Klasifikasikan ke SATU kategori berikut:
{$daftar}

Jawab HANYA dengan JSON valid, tanpa teks lain:
{"name": "Nama Resmi Kendaraan", "category": "{$pilihan}", "reason": "maksimal 8 kata", "recognized": true}

Jika input bukan nama kendaraan atau kamu tidak yakin kendaraan apa itu, set "recognized": false.
PROMPT;

        $model = config('carwash.gemini.model');

        try {
            $response = Http::timeout(25)
                ->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4']) // hindari macet resolusi IPv6 di Windows
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature'      => 0.1,
                        // thinkingBudget:0 MEMATIKAN mode "berpikir" model.
                        // Tanpa ini, model generasi terbaru (mis. gemini-3.5-flash)
                        // membocorkan proses berpikirnya ke teks jawaban — walau
                        // responseMimeType sudah JSON, hasilnya jadi:
                        //   {"ok": true}
                        //   Wait, let me reconsider...
                        //   {"ok": true}
                        // json_decode() gagal pada teks begitu dan classify()
                        // selalu jatuh ke 'recognized: false'. Ditemukan & diuji
                        // langsung ke API sungguhan, bukan tebakan dari dokumentasi.
                        'thinkingConfig'   => ['thinkingBudget' => 0],
                    ],
                ]);
        } catch (ConnectionException $e) {
            // JANGAN pernah meneruskan pesan cURL asli: berisi URL lengkap + API key.
            throw new RuntimeException(
                'Tidak bisa menghubungi server Gemini (timeout). Periksa koneksi internet server.'
            );
        }

        if ($response->failed()) {
            $hint = match (true) {
                // Bukan "pastikan pakai key AIza..." — format key Google Gemini
                // sudah berubah (sekarang ada yang berawalan "AQ."), jadi awalan
                // key tidak lagi bisa dipakai sebagai ciri sah/tidaknya.
                $response->status() === 400 || $response->status() === 403 => 'API key tidak valid — buat key baru di aistudio.google.com',
                // 401 ketemu nyata saat key yang salah JENIS kredensial dipakai
                // (Google membalasnya "Expected OAuth 2 access token..." — bukan
                // sekadar key salah/kadaluwarsa, tapi bukan API key sama sekali).
                $response->status() === 401 => 'Key ini bukan API key yang sah — pastikan disalin dari tombol "Get API key" di aistudio.google.com, bukan dari tempat lain',
                $response->status() === 404 => 'Nama model tidak dikenali atau sudah tidak tersedia — cek GEMINI_MODEL di .env',
                $response->status() === 429 => 'Kuota Gemini habis — coba lagi nanti',
                default => 'Kode: '.$response->status(),
            };

            throw new RuntimeException('Gemini menolak permintaan. '.$hint);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        $data = json_decode((string) $text, true);

        // Validasi ketat: jawaban AI tidak pernah dipercaya mentah-mentah
        $validCategories = $rows->pluck('slug')->all();

        if (
            ! is_array($data)
            || empty($data['recognized'])
            || empty($data['name'])
            || ! in_array($data['category'] ?? '', $validCategories, true)
        ) {
            return ['recognized' => false];
        }

        return [
            'recognized' => true,
            'name'       => mb_substr(trim($data['name']), 0, 100),
            'category'   => $data['category'],
            // Dipangkas keras: kartu AI dibaca kasir sambil pelanggan menunggu,
            // dan model suka menjelaskan panjang lebar ("Memperbaiki salah eja
            // dari 'masda' menjadi merek mobil 'Mazda' yang umumnya
            // dikategorikan sebagai mobil ukuran sedang.") walau diminta singkat.
            'reason'     => mb_substr(trim($data['reason'] ?? ''), 0, 120),
        ];
    }
}

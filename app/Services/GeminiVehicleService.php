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
     *
     * Sidik jarinya juga memuat koreksi owner (VehicleMemoryService): begitu
     * owner membetulkan jenis sebuah mobil, AI ditanya ulang dengan contoh
     * yang baru, bukan dijawab dari ingatan lama yang belum tahu koreksinya.
     */
    public function classify(string $query): array
    {
        $rows = \App\Models\WashCategory::urut()->get();
        $contoh = app(VehicleMemoryService::class)->contohKoreksi($rows->pluck('slug')->all());

        // Normalkan: "  AvAnza  " dan "avanza" satu ingatan yang sama.
        $normal = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
        $sidik  = md5($rows->pluck('slug')->implode(',').'|'
            .$contoh->map(fn ($v) => $v->name.'='.$v->category)->implode(','));

        return Cache::remember(
            "ai-vehicle:{$sidik}:".md5($normal),
            now()->addDays(self::CACHE_HARI),
            fn () => $this->tanyaGemini($query, $rows, $contoh),
        );
    }

    private function tanyaGemini(string $query, $rows, $contoh = null): array
    {
        [$daftar, $pilihan, $koreksi] = $this->bahanPrompt($rows, $contoh);

        $prompt = <<<PROMPT
Kamu adalah asisten kasir cuci kendaraan di Indonesia.
Kasir mengetik nama kendaraan: "{$query}"

Tugasmu:
1. Tebak kendaraan apa yang dimaksud (perbaiki salah eja, lengkapi merek).
2. Klasifikasikan ke SATU kategori berikut:
{$daftar}
{$koreksi}
Jawab HANYA dengan JSON valid, tanpa teks lain:
{"name": "Nama Resmi Kendaraan", "category": "{$pilihan}", "reason": "maksimal 8 kata", "recognized": true}

Jika input bukan nama kendaraan atau kamu tidak yakin kendaraan apa itu, set "recognized": false.
PROMPT;

        $data = $this->kirim([['text' => $prompt]]);

        return $this->sahkan($data, $rows);
    }

    /**
     * Kenali kendaraan dari FOTO (permintaan owner 16/10): nama, jenis, dan
     * plat nomor bila terbaca. Jawabannya tidak disimpan di cache — setiap
     * foto berbeda — jadi throttle di routes/api.php yang menjaga kuota.
     *
     * @return array{recognized:bool, name?:string, category?:string, reason?:string, plate?:string}
     */
    public function classifyPhoto(string $base64, string $mime): array
    {
        $rows = \App\Models\WashCategory::urut()->get();
        $contoh = app(VehicleMemoryService::class)->contohKoreksi($rows->pluck('slug')->all());
        [$daftar, $pilihan, $koreksi] = $this->bahanPrompt($rows, $contoh);

        $prompt = <<<PROMPT
Kamu adalah asisten kasir cuci kendaraan di Indonesia.
Foto ini dipotret kasir dari kendaraan yang akan dicuci.

Tugasmu:
1. Kenali kendaraan di foto: merek dan modelnya (mis. "Toyota Avanza").
2. Klasifikasikan ke SATU kategori berikut:
{$daftar}
{$koreksi}
3. Baca plat nomornya bila terlihat jelas (format Indonesia, mis. "B 1234 XYZ").
   Kosongkan bila tidak terbaca — jangan menebak.

Jawab HANYA dengan JSON valid, tanpa teks lain:
{"name": "Nama Resmi Kendaraan", "category": "{$pilihan}", "plate": "", "reason": "maksimal 8 kata", "recognized": true}

Jika foto bukan kendaraan atau kamu tidak yakin kendaraan apa itu, set "recognized": false.
PROMPT;

        $data = $this->kirim([
            ['text' => $prompt],
            ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
        ]);
        $hasil = $this->sahkan($data, $rows);
        if ($hasil['recognized']) {
            $hasil['plate'] = self::platSah((string) ($data['plate'] ?? ''));
        }

        return $hasil;
    }

    /**
     * Plat hasil bacaan AI hanya dipakai bila bentuknya plat Indonesia
     * (1–2 huruf, 1–4 angka, 0–3 huruf). Selain itu dikosongkan: plat salah
     * baca yang diterima mentah-mentah lebih merepotkan daripada kolom kosong.
     */
    public static function platSah(string $plat): string
    {
        $rapat = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plat));
        if (! preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $rapat, $m)) {
            return '';
        }

        return trim($m[1].' '.$m[2].' '.$m[3]);
    }

    /**
     * Owner memindahkan [$acuan] dari jenis [$dari] ke [$ke]. AI menebak
     * ALASANNYA dari ciri fisik (bodi, panjang, ukuran, kapasitas), lalu
     * memilih — dari [$calon] yang masih di [$dari] — mana yang menurut alasan
     * yang sama juga seharusnya [$ke]. Permintaan owner 17/10.
     *
     * Hanya USULAN: owner yang memutuskan (KendaraanSejenisService). Id yang
     * tidak ada di daftar calon dibuang; AI tidak bisa memindahkan mobil yang
     * tidak ditanyakan.
     *
     * @param  array<int,string>  $calon  id => nama
     * @return array{aturan:string, usulan:array<int,string>}  usulan: id => alasan singkat
     */
    public function usulSejenis(string $acuan, string $dari, string $ke, array $calon): array
    {
        $rows = \App\Models\WashCategory::urut()->get();
        [$daftar] = $this->bahanPrompt($rows, null);
        $label = $rows->pluck('label', 'slug');
        $bersih = fn (string $s) => trim(str_replace(['"', "\n", "\r"], ' ', $s));

        $baris = collect($calon)->map(fn ($nama, $id) => "   - [{$id}] ".$bersih($nama))->implode("\n");
        $acuan = $bersih($acuan);
        $dariL = $label[$dari] ?? $dari;
        $keL   = $label[$ke] ?? $ke;

        $prompt = <<<PROMPT
Kamu membantu pemilik cucian kendaraan di Indonesia merapikan daftar jenis kendaraannya.
Jenis kendaraan di cucian ini:
{$daftar}

Pemilik baru saja memindahkan "{$acuan}" dari "{$dariL}" ke "{$keL}".

Tugasmu:
1. Tebak ALASAN pemindahan itu dari ciri fisik kendaraan: jenis bodi, panjang, ukuran, atau kapasitas penumpang. Maksimal 15 kata.
2. Dari daftar berikut (semuanya masih "{$dariL}"), pilih yang menurut alasan YANG SAMA seharusnya juga "{$keL}". Pilih hanya yang kamu yakin; jangan pilih kalau ragu.
{$baris}

Jawab HANYA dengan JSON valid, tanpa teks lain:
{"aturan": "alasan pemindahan", "pindah": [{"id": 0, "alasan": "maksimal 8 kata"}]}
PROMPT;

        $data = $this->kirim([['text' => $prompt]]);

        $usulan = [];
        foreach ((array) ($data['pindah'] ?? []) as $u) {
            $id = (int) ($u['id'] ?? 0);
            if (isset($calon[$id]) && ! isset($usulan[$id])) {
                $usulan[$id] = mb_substr(trim((string) ($u['alasan'] ?? '')), 0, 80);
            }
        }

        return [
            'aturan' => mb_substr(trim((string) ($data['aturan'] ?? '')), 0, 160),
            'usulan' => $usulan,
        ];
    }

    /** Daftar kategori, pilihan slug, dan contoh koreksi owner untuk prompt. */
    private function bahanPrompt($rows, $contoh): array
    {
        $daftar = $rows->map(function ($c) {
            $contoh = $c->examples ? " (contoh: {$c->examples})" : '';

            return "   - \"{$c->slug}\" = {$c->label}{$contoh}";
        })->implode("\n");

        $pilihan = $rows->pluck('slug')->implode('|');

        // Koreksi owner sebagai contoh: penggolongan tiap cucian berbeda
        // (Raize "mobil kecil" di satu tempat, "mobil" di tempat lain), dan
        // inilah satu-satunya cara AI tahu kebiasaan cucian INI. Nama dibersihkan
        // dari tanda kutip & baris baru supaya tidak merusak susunan prompt.
        $koreksi = '';
        if ($contoh && $contoh->isNotEmpty()) {
            $koreksi = "\nPenggolongan di cucian ini bisa berbeda dari kebiasaan umum. Ini koreksi dari pemiliknya;\n"
                ."ikuti polanya untuk kendaraan yang mirip:\n"
                .$contoh->map(fn ($v) => '   - '.trim(str_replace(['"', "\n", "\r"], ' ', $v->name))
                    .' = "'.$v->category.'"')->implode("\n")."\n";
        }

        return [$daftar, $pilihan, $koreksi];
    }

    /** Kirim ke Gemini dan kembalikan JSON jawabannya (null bila bukan JSON). */
    private function kirim(array $parts): ?array
    {
        $key = config('carwash.gemini.key');

        if (! $key) {
            throw new RuntimeException('GEMINI_API_KEY belum diatur di .env');
        }

        $model = config('carwash.gemini.model');

        try {
            $response = Http::timeout(25)
                ->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4']) // hindari macet resolusi IPv6 di Windows
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                    'contents' => [
                        ['parts' => $parts],
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

        return is_array($data) ? $data : null;
    }

    /** Jawaban AI tidak pernah dipercaya mentah-mentah. */
    private function sahkan(?array $data, $rows): array
    {
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

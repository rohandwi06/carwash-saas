<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\GeminiVehicleService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiVehicleController extends Controller
{
    public function __construct(
        private GeminiVehicleService $gemini,
        private PricingService $pricing,
    ) {}

    /**
     * POST /api/ai/classify-vehicle {query}
     * Tanya Gemini kendaraan apa & kategorinya. TIDAK menyimpan apa pun —
     * hasilnya usulan yang harus dikonfirmasi kasir dulu.
     *
     * KATALOG MENANG ATAS AI. Kalau kendaraan yang ditebak AI sudah ada di
     * katalog, yang dipakai kategori dari katalog — tebakan AI cuma
     * ditampilkan sebagai catatan.
     *
     * Ini bukan kehati-hatian teoretis. Dua transaksi nyata pada 30 Agustus
     * 2026 (#666 Toyota Raize, #670 Honda City) tercatat "mobil kecil" 35rb
     * padahal keduanya "mobil" 40rb di katalog: kasir salah ketik satu huruf
     * ("citi", "raise"), skor pencarian lokal jatuh di bawah ambang yakin,
     * AI ikut ditanya, dan jawabannya yang dipakai. Selisihnya 5rb per
     * cucian, dan tidak ada yang pernah melihatnya karena kartu AI tidak
     * menyebut kategori mana yang sedang dipakai.
     */
    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:60']]);

        $result = $this->gemini->classify($data['query']);

        if (! $result['recognized']) {
            return response()->json(['data' => ['recognized' => false]]);
        }

        $daftar   = $this->pricing->categories();
        $existing = Vehicle::whereRaw('LOWER(name) = ?', [mb_strtolower($result['name'])])->first();

        // Baris katalog yang kategorinya sudah dihapus owner tidak bisa
        // dipakai (harganya tidak ada lagi) — untuk baris seperti itu tebakan
        // AI tetap jadi jalan keluar, bukan buntu.
        $slug = ($existing && isset($daftar[$existing->category]))
            ? $existing->category
            : $result['category'];

        $category = $daftar[$slug] ?? null;

        if (! $category) {
            return response()->json(['data' => ['recognized' => false]]);
        }

        return response()->json(['data' => [
            'recognized' => true,
            // Ejaan katalog yang menang juga: supaya "honda city" dari AI
            // tidak melahirkan baris kembar di sebelah "Honda City".
            'name'              => $existing->name ?? $result['name'],
            'category'          => $slug,
            'category_label'    => $category['label'],
            'price'             => $category['price'],
            'reason'            => $result['reason'],
            'already_exists'    => (bool) $existing,
            // Dipakai layar kasir untuk mengatakan terus terang bahwa tebakan
            // AI dikalahkan katalog — beserta angka yang tidak jadi dipakai.
            'ai_category'       => $result['category'],
            'ai_category_label' => $daftar[$result['category']]['label'] ?? $result['category'],
            'ai_price'          => $daftar[$result['category']]['price'] ?? null,
            'dikoreksi_katalog' => $slug !== $result['category'],
        ]]);
    }

    /**
     * POST /api/ai/save-vehicle {name, category}
     * Simpan usulan Gemini ke database SETELAH kasir menekan konfirmasi.
     *
     * Ditandai needs_review: kasir boleh memakainya sekarang juga, tapi
     * kategorinya baru jadi aturan resmi setelah owner membenarkannya di
     * Pengaturan. Kasir tidak berwenang menetapkan harga.
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::exists('wash_categories', 'slug')],
        ]);

        $vehicle = Vehicle::firstOrCreate(
            ['name' => $data['name']],
            ['category' => $data['category'], 'needs_review' => true],
        );

        return response()->json(['data' => $vehicle], 201);
    }
}

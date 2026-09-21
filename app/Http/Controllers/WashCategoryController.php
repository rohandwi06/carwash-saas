<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kelola JENIS KENDARAAN (owner-only).
 * Menambah kategori sekaligus menyiapkan tarif upah dan harga untuk
 * layanan yang dipilih — supaya tidak ada kategori setengah jadi.
 */
class WashCategoryController extends Controller
{
    /** GET /api/wash-categories */
    public function index(): JsonResponse
    {
        $harga = WashPrice::all()->groupBy('category_slug');
        $upah  = WageRate::all()
            ->groupBy('category')
            ->map(fn ($rows) => $rows->pluck('amount', 'service')->map(fn ($v) => (int) $v)->all());
        $pakai = Transaction::selectRaw('category, COUNT(*) as n')->groupBy('category')->pluck('n', 'category');

        return response()->json([
            'data' => WashCategory::urut()->get()->map(function (WashCategory $c) use ($harga, $upah, $pakai) {
                $wages = $upah[$c->slug] ?? [];

                return [
                    'id'         => $c->id,
                    'slug'       => $c->slug,
                    'label'      => $c->label,
                    'shape'      => $c->shape,
                    'examples'   => $c->examples,
                    // wage dipertahankan untuk kompatibilitas tampilan lama; nilai utama sekarang wages.
                    'wage'       => (int) ($wages['reguler'] ?? 0),
                    'wages'      => $wages,
                    'prices'     => $harga->get($c->slug, collect())->pluck('price', 'service_slug')
                        ->map(fn ($p) => (int) $p)->all(),
                    'used_count' => (int) ($pakai[$c->slug] ?? 0),
                ];
            }),
        ]);
    }

    /** POST /api/wash-categories */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validasi($request);

        $slug = $this->slugUnik($data['label']);

        DB::transaction(function () use ($data, $slug) {
            // 'shape' & 'examples' tidak diisi lewat form (owner tidak mengatur
            // gambar/contoh) — biarkan pakai default kolom ('hatch', kosong).
            WashCategory::create([
                'slug'       => $slug,
                'label'      => $data['label'],
                'sort_order' => (int) WashCategory::max('sort_order') + 1,
            ]);

            $this->simpanHarga($slug, $data['prices']);
            $this->simpanUpah($slug, $data['prices'], $data['wages']);
        });

        return response()->json(['data' => ['slug' => $slug]], 201);
    }

    /** PATCH /api/wash-categories/{washCategory} — slug TIDAK diubah (dipakai riwayat) */
    public function update(Request $request, WashCategory $washCategory): JsonResponse
    {
        $data = $this->validasi($request);

        DB::transaction(function () use ($data, $washCategory) {
            // Idem: 'shape' & 'examples' bukan bagian form ini lagi — nilai
            // yang sudah ada di database (kalau ada, dari data lama) dibiarkan.
            $washCategory->update(['label' => $data['label']]);

            $this->simpanHarga($washCategory->slug, $data['prices']);
            $this->simpanUpah($washCategory->slug, $data['prices'], $data['wages']);
        });

        return response()->json(['data' => ['slug' => $washCategory->slug]]);
    }

    /** DELETE /api/wash-categories/{washCategory} */
    public function destroy(WashCategory $washCategory): JsonResponse
    {
        $dipakai = Transaction::where('category', $washCategory->slug)->count();

        if ($dipakai > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus: sudah dipakai {$dipakai} transaksi. "
                    ."Menghapusnya akan merusak laporan lama.",
            ], 422);
        }

        // Katalog kendaraan (Vehicle) juga menyimpan slug kategori ini —
        // beda dari Transaction, di sini TIDAK ada baris lama yang perlu
        // dijaga, jadi bisa dicegah total daripada cuma diperingatkan.
        // Tanpa pengecekan ini, mencari nama kendaraan yang kategorinya
        // sudah terhapus membuat frontend crash: CFG.categories[...] jadi
        // undefined karena kategorinya sudah tidak ada di /api/config.
        $terpakai = Vehicle::where('category', $washCategory->slug)->count();

        if ($terpakai > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus: masih dipakai {$terpakai} kendaraan di katalog "
                    .'(mis. hasil pencarian nama mobil). Pindahkan dulu kendaraan itu ke '
                    .'kategori lain di menu Pengaturan, atau hapus kendaraannya.',
            ], 422);
        }

        if (WashCategory::count() <= 1) {
            return response()->json(['message' => 'Minimal harus ada satu jenis kendaraan.'], 422);
        }

        DB::transaction(function () use ($washCategory) {
            WashPrice::where('category_slug', $washCategory->slug)->delete();
            WageRate::where('category', $washCategory->slug)->delete();
            $washCategory->delete();
        });

        return response()->json(['data' => null]);
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'label'    => ['required', 'string', 'max:40'],
            // prices: {service_slug: harga}. Layanan yang tidak disebut = tidak tersedia.
            'prices'   => ['required', 'array', 'min:1'],
            'prices.*' => ['required', 'integer', 'min:0', 'max:5000000'],
            // wages: {service_slug: upah}. Hanya layanan yang tersedia yang disimpan.
            'wages'    => ['required', 'array', 'min:1'],
            'wages.*'  => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
    }

    /** Tulis ulang baris harga kategori: yang tidak dikirim berarti dihapus. */
    private function simpanHarga(string $slug, array $prices): void
    {
        $valid = WashService::pluck('slug')->all();

        WashPrice::where('category_slug', $slug)
            ->whereNotIn('service_slug', array_keys($prices))->delete();

        foreach ($prices as $service => $harga) {
            if (! in_array($service, $valid, true)) {
                continue; // layanan tidak dikenal — abaikan, jangan bikin baris sampah
            }
            WashPrice::updateOrCreate(
                ['category_slug' => $slug, 'service_slug' => $service],
                ['price' => (int) $harga],
            );
        }
    }

    /** Tulis ulang upah kategori mengikuti layanan yang tersedia. */
    private function simpanUpah(string $slug, array $prices, array $wages): void
    {
        WageRate::where('category', $slug)
            ->whereNotIn('service', array_keys($prices))
            ->delete();

        foreach (array_keys($prices) as $service) {
            WageRate::updateOrCreate(
                ['category' => $slug, 'service' => $service],
                ['amount' => (int) ($wages[$service] ?? 0)],
            );
        }
    }

    private function slugUnik(string $label): string
    {
        $dasar = Str::slug($label) ?: 'jenis';
        $slug  = $dasar;
        $i     = 2;

        while (WashCategory::where('slug', $slug)->exists()) {
            $slug = $dasar.'-'.$i++;
        }

        return $slug;
    }
}

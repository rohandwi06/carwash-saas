<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\WageRate;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kelola JENIS LAYANAN CUCI (owner-only).
 * Layanan baru otomatis diberi harga awal untuk semua kategori supaya
 * langsung bisa dipakai; owner tinggal menyesuaikan angkanya.
 */
class WashServiceController extends Controller
{
    /** GET /api/wash-services */
    public function index(): JsonResponse
    {
        $pakai = Transaction::selectRaw('service, COUNT(*) as n')->groupBy('service')->pluck('n', 'service');

        return response()->json([
            'data' => WashService::urut()->get()->map(fn (WashService $s) => [
                'id'         => $s->id,
                'slug'       => $s->slug,
                'label'      => $s->label,
                'used_count' => (int) ($pakai[$s->slug] ?? 0),
            ]),
        ]);
    }

    /** POST /api/wash-services */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'price' => ['required', 'integer', 'min:0', 'max:5000000'], // harga awal untuk semua kategori
        ]);

        $slug = $this->slugUnik($data['label']);

        DB::transaction(function () use ($data, $slug) {
            WashService::create([
                'slug'       => $slug,
                'label'      => $data['label'],
                'sort_order' => (int) WashService::max('sort_order') + 1,
            ]);

            foreach (WashCategory::pluck('slug') as $kategori) {
                WashPrice::updateOrCreate(
                    ['category_slug' => $kategori, 'service_slug' => $slug],
                    ['price' => $data['price']],
                );

                WageRate::updateOrCreate(
                    ['category' => $kategori, 'service' => $slug],
                    ['amount' => 0],
                );
            }
        });

        return response()->json(['data' => ['slug' => $slug]], 201);
    }

    /** PATCH /api/wash-services/{washService} — ganti nama saja (slug tetap) */
    public function update(Request $request, WashService $washService): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
        ]);

        $washService->update($data);

        return response()->json(['data' => ['slug' => $washService->slug]]);
    }

    /** DELETE /api/wash-services/{washService} */
    public function destroy(WashService $washService): JsonResponse
    {
        $dipakai = Transaction::where('service', $washService->slug)->count();

        if ($dipakai > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus: sudah dipakai {$dipakai} transaksi. "
                    ."Menghapusnya akan merusak laporan lama.",
            ], 422);
        }

        if (WashService::count() <= 1) {
            return response()->json(['message' => 'Minimal harus ada satu jenis layanan.'], 422);
        }

        DB::transaction(function () use ($washService) {
            WashPrice::where('service_slug', $washService->slug)->delete();
            WageRate::where('service', $washService->slug)->delete();
            $washService->delete();
        });

        return response()->json(['data' => null]);
    }

    private function slugUnik(string $label): string
    {
        $dasar = Str::slug($label) ?: 'layanan';
        $slug  = $dasar;
        $i     = 2;

        while (WashService::where('slug', $slug)->exists()) {
            $slug = $dasar.'-'.$i++;
        }

        return $slug;
    }
}

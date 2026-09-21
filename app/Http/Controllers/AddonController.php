<?php

namespace App\Http\Controllers;

use App\Models\Addon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Layanan tambahan (semir ban, poles, anti jamur kaca, ...).
 * index() boleh diakses kasir; tambah/ubah/hapus khusus owner.
 */
class AddonController extends Controller
{
    /** GET /api/addons (semua) | ?active=1 (khusus yang aktif, untuk layar kasir) */
    public function index(Request $request): JsonResponse
    {
        $query = Addon::urut();

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    /** POST /api/addons */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:60'],
            'price' => ['required', 'integer', 'min:0', 'max:5000000'],
        ]);

        $addon = Addon::create($data + [
            'is_active'  => true,
            'sort_order' => (int) Addon::max('sort_order') + 1,
        ]);

        return response()->json(['data' => $addon], 201);
    }

    /** PATCH /api/addons/{addon} — ubah nama / harga / aktif-nonaktif */
    public function update(Request $request, Addon $addon): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:60'],
            'price'     => ['sometimes', 'integer', 'min:0', 'max:5000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $addon->update($data);

        return response()->json(['data' => $addon]);
    }

    /**
     * DELETE /api/addons/{addon}
     * Aman dihapus kapan saja: riwayat transaksi menyimpan salinan
     * nama & harga di pivot, jadi laporan lama tidak berubah.
     */
    public function destroy(Addon $addon): JsonResponse
    {
        $addon->delete();

        return response()->json(['data' => null]);
    }
}

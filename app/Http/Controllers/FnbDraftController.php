<?php

namespace App\Http\Controllers;

use App\Models\FnbDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Draft makanan & minuman: pesanan dicatat dulu, penjualannya disimpan
 * belakangan saat pelanggan membayar. Draft bukan penjualan — tidak punya
 * total, tidak memotong stok, dan tidak masuk rekap uang mana pun.
 *
 * Kembaran TransactionDraftController untuk sisi F&B.
 */
class FnbDraftController extends Controller
{
    /** GET /api/fnb-drafts — draft yang masih menggantung (yang lama ikut, agar tidak terlupa). */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => FnbDraft::orderBy('created_at')->get(),
        ]);
    }

    /** POST /api/fnb-drafts */
    public function store(Request $request): JsonResponse
    {
        $draft = FnbDraft::create($this->validated($request) + [
            'date'       => now()->toDateString(),
            'created_by' => $request->attributes->get('auth_name'),
        ]);

        return response()->json(['data' => $draft], 201);
    }

    /** PATCH /api/fnb-drafts/{fnbDraft} */
    public function update(Request $request, FnbDraft $fnbDraft): JsonResponse
    {
        $fnbDraft->update($this->validated($request, sometimes: true));

        return response()->json(['data' => $fnbDraft]);
    }

    /** DELETE /api/fnb-drafts/{fnbDraft} */
    public function destroy(FnbDraft $fnbDraft): JsonResponse
    {
        $fnbDraft->delete();

        return response()->json(['data' => null]);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $wajib = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'label'              => ['nullable', 'string', 'max:60'],
            'note'               => ['nullable', 'string', 'max:160'],
            // Produk divalidasi ada, tapi stoknya TIDAK dicek di sini: draft
            // belum memotong stok, dan pengecekan yang sebenarnya berlaku
            // adalah yang di FnbService::create() saat pesanan jadi penjualan.
            'items'              => [$wajib, 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty'        => ['required', 'integer', 'min:1', 'max:100'],
            'tip'                => ['sometimes', 'integer', 'min:0', 'max:10000000'],
        ]);
    }
}

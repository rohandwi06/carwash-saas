<?php

namespace App\Http\Controllers;

use App\Models\TransactionDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Draft cucian: kendaraan dicatat dulu saat masuk, transaksinya disimpan
 * belakangan saat pelanggan membayar. Draft bukan transaksi — tidak punya
 * total dan tidak masuk rekap uang mana pun.
 */
class TransactionDraftController extends Controller
{
    /** GET /api/drafts — draft hari ini (yang lama ikut ditampilkan agar tidak terlupa). */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TransactionDraft::orderBy('created_at')->get(),
        ]);
    }

    /** POST /api/drafts */
    public function store(Request $request): JsonResponse
    {
        $draft = TransactionDraft::create($this->validated($request) + [
            'date'       => now()->toDateString(),
            'created_by' => $request->attributes->get('auth_name'),
        ]);

        return response()->json(['data' => $draft], 201);
    }

    /** PATCH /api/drafts/{draft} */
    public function update(Request $request, TransactionDraft $draft): JsonResponse
    {
        $draft->update($this->validated($request, sometimes: true));

        return response()->json(['data' => $draft]);
    }

    /** DELETE /api/drafts/{draft} */
    public function destroy(TransactionDraft $draft): JsonResponse
    {
        $draft->delete();

        return response()->json(['data' => null]);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $wajib = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'vehicle_name' => [$wajib, 'string', 'max:100'],
            'category'     => [$wajib, Rule::exists('wash_categories', 'slug')],
            'service'      => ['sometimes', Rule::exists('wash_services', 'slug')],
            'plate'        => ['nullable', 'string', 'max:20'],
            'note'         => ['nullable', 'string', 'max:160'],
            'worker_ids'   => ['sometimes', 'array'],
            'worker_ids.*' => ['integer', 'exists:workers,id'],
            'addon_ids'    => ['sometimes', 'array'],
            'addon_ids.*'  => ['integer', 'exists:addons,id'],
            // Makanan/minuman yang dipesan bareng cucian. Produk divalidasi
            // ada, tapi stoknya TIDAK dicek di sini: draft belum memotong
            // stok, dan pengecekan yang berlaku adalah yang di
            // FnbService::create() saat draft benar-benar jadi transaksi.
            'fnb_items'              => ['sometimes', 'array'],
            'fnb_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'fnb_items.*.qty'        => ['required', 'integer', 'min:1', 'max:100'],
            'tip'                    => ['sometimes', 'integer', 'min:0', 'max:10000000'],
        ]);
    }
}

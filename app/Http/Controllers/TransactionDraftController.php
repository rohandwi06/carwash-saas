<?php

namespace App\Http\Controllers;

use App\Models\TransactionDraft;
use App\Services\FotoKendaraanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /** POST /api/drafts/{draft}/photos {image: data URL} — foto kondisi sebelum dibayar */
    public function storePhoto(Request $request, TransactionDraft $draft, FotoKendaraanService $foto): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:6000000']]);
        $nama = $foto->simpanDraft($draft, $data['image']);

        return response()->json(['data' => ['name' => $nama, 'photos' => FotoKendaraanService::fotoDraft($draft->id)]], 201);
    }

    /** GET /api/drafts/{draft}/photos/{nama} — gambarnya sendiri */
    public function showPhoto(TransactionDraft $draft, string $nama, FotoKendaraanService $foto): Response
    {
        $isi = $foto->isiDraft($draft->id, $nama);
        abort_if($isi === null, 404, 'Foto draft sudah tidak ada.');

        return response($isi['byte'], 200, ['Content-Type' => $isi['mime'], 'Cache-Control' => 'private, max-age=86400']);
    }

    /** DELETE /api/drafts/{draft}/photos — kosongkan; layar mengunggah ulang yang masih dipakai */
    public function clearPhotos(TransactionDraft $draft): JsonResponse
    {
        FotoKendaraanService::hapusDraft($draft->id);

        return response()->json(['data' => []]);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $wajib = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'vehicle_name' => [$wajib, 'string', 'max:100'],
            'category'     => [$wajib, Rule::exists('wash_categories', 'slug')],
            'service'      => ['sometimes', Rule::exists('wash_services', 'slug')],
            'plate'        => ['nullable', 'string', 'max:20'],
            'customer_name'  => ['nullable', 'string', 'max:60'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
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

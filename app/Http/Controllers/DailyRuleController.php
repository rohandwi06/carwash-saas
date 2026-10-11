<?php

namespace App\Http\Controllers;

use App\Models\DailyRule;
use App\Services\DailyRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aturan otomatis harian untuk potongan upah & kompensasi.
 *
 * SEMUA endpoint di sini owner-only — lihat routes/api.php: aturan ini
 * menentukan uang yang diterima pekerja dan mengubah laba bersih, setiap hari,
 * tanpa ada yang menekan tombol lagi.
 */
class DailyRuleController extends Controller
{
    public function __construct(private DailyRuleService $aturan) {}

    /** GET /api/daily-rules — semua aturan, yang aktif di atas. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DailyRule::with('worker:id,name')
                ->orderByDesc('is_active')->orderBy('id')->get(),
        ]);
    }

    /**
     * POST /api/daily-rules  {"type":"kompensasi", "worker_id":null,
     *                         "amount":10000, "reason":"Uang makan"}
     * worker_id kosong = semua pekerja yang kerja hari itu.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'      => ['required', Rule::in([DailyRule::POTONGAN, DailyRule::KOMPENSASI])],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'amount'    => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason'    => ['required', 'string', 'max:160'],
        ]);

        return response()->json([
            'data' => $this->aturan->buat($data, $request->attributes->get('auth_name')),
        ], 201);
    }

    /**
     * PATCH /api/daily-rules/{dailyRule} — ubah isi, nyalakan, atau matikan.
     * Jenisnya (potongan/kompensasi) tidak bisa diganti: catatan yang sudah
     * dibuat tinggal di tabel jenis lamanya.
     */
    public function update(Request $request, DailyRule $dailyRule): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['sometimes', 'nullable', 'integer', 'exists:workers,id'],
            'amount'    => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'reason'    => ['sometimes', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->aturan->ubah($dailyRule, $data)]);
    }

    /** DELETE /api/daily-rules/{dailyRule} */
    public function destroy(DailyRule $dailyRule): JsonResponse
    {
        $this->aturan->hapus($dailyRule);

        return response()->json(['data' => null]);
    }
}

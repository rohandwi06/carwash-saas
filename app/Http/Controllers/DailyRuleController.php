<?php

namespace App\Http\Controllers;

use App\Models\DailyRule;
use App\Services\DailyRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'data' => DailyRule::orderByDesc('is_active')->orderBy('id')->get()
                ->map(fn (DailyRule $a) => $this->aturan->lengkap($a)),
        ]);
    }

    /**
     * POST /api/daily-rules  {"type":"kompensasi", "all_workers":false,
     *                         "worker_ids":[3,5,8], "amount":10000,
     *                         "reason":"Uang makan"}
     * all_workers true = semua pekerja yang kerja hari itu (worker_ids
     * diabaikan); false = hanya pekerja di worker_ids, boleh beberapa sekaligus.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'   => ['required', Rule::in([DailyRule::POTONGAN, DailyRule::KOMPENSASI])],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:160'],
        ] + $this->aturanPenerima('required'));
        $this->pastikanAdaPenerima($data);

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
            'amount'    => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'reason'    => ['sometimes', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->aturanPenerima('sometimes'));
        $this->pastikanAdaPenerima($data);

        return response()->json(['data' => $this->aturan->ubah($dailyRule, $data)]);
    }

    /** Aturan validasi "untuk siapa" — sama untuk membuat dan mengubah. */
    private function aturanPenerima(string $wajib): array
    {
        return [
            'all_workers'  => [$wajib, 'boolean'],
            'worker_ids'   => ['sometimes', 'array', 'max:200'],
            'worker_ids.*' => ['integer', 'distinct', 'exists:workers,id'],
        ];
    }

    /**
     * Bukan untuk semua pekerja berarti harus menyebut siapa. Tanpa ini aturan
     * tersimpan tapi tidak pernah mengenai siapa pun, dan owner mengira ia jalan.
     * worker_ids yang datang tanpa all_workers juga ditolak: service mengganti
     * penerima hanya bila keduanya dikirim berpasangan.
     */
    private function pastikanAdaPenerima(array $data): void
    {
        if (! array_key_exists('all_workers', $data)) {
            if (array_key_exists('worker_ids', $data)) {
                throw ValidationException::withMessages(['all_workers' => 'Sebutkan juga apakah aturannya untuk semua pekerja.']);
            }

            return;
        }

        if (! $data['all_workers'] && empty($data['worker_ids'])) {
            throw ValidationException::withMessages(['worker_ids' => 'Pilih pekerjanya, atau pilih Semua pekerja.']);
        }
    }

    /** DELETE /api/daily-rules/{dailyRule} */
    public function destroy(DailyRule $dailyRule): JsonResponse
    {
        $this->aturan->hapus($dailyRule);

        return response()->json(['data' => null]);
    }
}

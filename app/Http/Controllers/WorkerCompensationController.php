<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\WorkerCompensation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kompensasi karyawan ("sangu" dari owner: uang makan, uang bensin).
 *
 * SEMUA endpoint di sini owner-only — lihat routes/api.php. Memotong laba
 * bersih di barisnya sendiri; tidak menyentuh upah, omzet, setoran cash,
 * maupun buku kas (uangnya dari owner, bukan dari laci).
 */
class WorkerCompensationController extends Controller
{
    /**
     * GET /api/worker-compensations?date=2026-10-11
     * GET /api/worker-compensations?dates[]=2026-10-09&dates[]=2026-10-11
     * GET /api/worker-compensations?from=..&to=..
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'    => ['sometimes', 'date_format:Y-m-d'],
            'from'    => ['sometimes', 'date_format:Y-m-d'],
            'to'      => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dates'   => ['sometimes', 'array', 'max:100'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);

        $query = WorkerCompensation::query()->orderByDesc('date')->orderByDesc('id');

        if (! empty($data['dates'])) {
            // whereDate per tanggal, bukan whereIn — alasan yang sama dengan
            // WageAdjustmentController::index().
            $query->where(function ($q) use ($data) {
                foreach ($data['dates'] as $t) {
                    $q->orWhereDate('date', $t);
                }
            });
        } elseif (isset($data['from'], $data['to'])) {
            $query->whereDate('date', '>=', $data['from'])->whereDate('date', '<=', $data['to']);
        } else {
            $query->whereDate('date', $data['date'] ?? now()->toDateString());
        }

        $rows = $query->get();

        return response()->json([
            'data'    => $rows,
            'summary' => ['total' => (int) $rows->sum('amount')],
        ]);
    }

    /**
     * POST /api/worker-compensations  {"worker_ids":[3,5], "amount":15000,
     *                                  "reason":"Uang makan",
     *                                  "dates":["2026-10-11", ...]}
     *
     * 'amount' berlaku untuk SETIAP pekerja pada SETIAP tanggal — "uang makan
     * Rp 15.000" untuk 3 orang selama 2 hari = 6 catatan, Rp 90.000. Tanpa
     * 'dates' = hari ini. Satu transaksi: kalau satu gagal, tidak ada yang
     * tercatat.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'worker_ids.*' => ['integer', 'distinct', 'exists:workers,id'],
            'amount'       => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason'       => ['nullable', 'string', 'max:160'],
            'dates'        => ['sometimes', 'array', 'min:1', 'max:62'],
            'dates.*'      => ['date_format:Y-m-d', 'distinct'],
        ]);

        $pekerja = Worker::whereIn('id', $data['worker_ids'])->orderBy('id')->get();
        $tanggal = collect($data['dates'] ?? [now()->toDateString()])->sort()->values();
        $oleh    = $request->attributes->get('auth_name');

        $rows = DB::transaction(fn () => $tanggal->flatMap(
            fn (string $t) => $pekerja->map(fn (Worker $w) => WorkerCompensation::create([
                'worker_id'   => $w->id,
                'worker_name' => $w->name,   // disalin: lihat catatan di migrasi
                'amount'      => $data['amount'],
                'reason'      => $data['reason'] ?? null,
                'date'        => $t,
                'created_by'  => $oleh,
            ]))
        )->values());

        return response()->json(['data' => $rows], 201);
    }

    /** DELETE /api/worker-compensations/{workerCompensation} — koreksi salah input. */
    public function destroy(WorkerCompensation $workerCompensation): JsonResponse
    {
        $workerCompensation->delete();

        return response()->json(['data' => null]);
    }
}

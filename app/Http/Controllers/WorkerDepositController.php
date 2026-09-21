<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\WorkerDeposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deposit (setoran) pekerja ke kas.
 *
 * SEMUA endpoint di sini owner-only — lihat routes/api.php. Kasir tidak boleh
 * menginput maupun melihat, karena setoran adalah urusan uang antara pekerja
 * dan pemilik, bukan bagian dari transaksi cucian.
 *
 * Angka setoran SENGAJA tidak dicampur ke laba/rekap harian: uang ini bukan
 * pendapatan dari cucian. Kalau ikut ditambahkan, laba harian jadi
 * menggelembung dan pembukuan tidak lagi mencerminkan hasil usaha.
 */
class WorkerDepositController extends Controller
{
    /**
     * GET /api/worker-deposits                     -> semua, terbaru dulu
     * GET /api/worker-deposits?date=2026-07-27     -> satu tanggal
     * GET /api/worker-deposits?from=..&to=..       -> rentang tanggal
     * GET /api/worker-deposits?worker_id=3         -> satu pekerja
     *
     * Selalu menyertakan 'summary' (total & per pekerja) supaya layar tidak
     * perlu menjumlahkan sendiri — angka uang cukup dihitung di satu tempat.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'      => ['sometimes', 'date_format:Y-m-d'],
            'from'      => ['sometimes', 'date_format:Y-m-d'],
            'to'        => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
        ]);

        $query = WorkerDeposit::query()->orderByDesc('date')->orderByDesc('id');

        if (isset($data['from'], $data['to'])) {
            $query->whereBetween('date', [$data['from'], $data['to']]);
        } elseif (isset($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        if (isset($data['worker_id'])) {
            $query->where('worker_id', $data['worker_id']);
        }

        $deposits = $query->get();

        return response()->json([
            'data'    => $deposits,
            'summary' => [
                'total'      => (int) $deposits->sum('amount'),
                'per_worker' => $deposits
                    ->groupBy('worker_name')
                    ->map(fn ($rows, $nama) => [
                        'worker_name' => (string) $nama,
                        'total'       => (int) $rows->sum('amount'),
                        'count'       => $rows->count(),
                    ])
                    ->sortByDesc('total')
                    ->values(),
            ],
        ]);
    }

    /** POST /api/worker-deposits — hanya owner yang boleh menginput. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'amount'    => ['required', 'integer', 'min:1', 'max:100000000'],
            'note'      => ['nullable', 'string', 'max:160'],
            'date'      => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $worker = Worker::findOrFail($data['worker_id']);

        $deposit = WorkerDeposit::create([
            'worker_id'   => $worker->id,
            'worker_name' => $worker->name,     // disalin: lihat catatan di migrasi
            'amount'      => $data['amount'],
            'note'        => $data['note'] ?? null,
            'date'        => $data['date'] ?? now()->toDateString(),
            'created_by'  => $request->attributes->get('auth_name'),
        ]);

        return response()->json(['data' => $deposit], 201);
    }

    /** DELETE /api/worker-deposits/{workerDeposit} — koreksi salah input. */
    public function destroy(WorkerDeposit $workerDeposit): JsonResponse
    {
        $workerDeposit->delete();

        return response()->json(['data' => null]);
    }
}

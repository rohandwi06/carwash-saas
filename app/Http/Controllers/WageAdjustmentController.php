<?php

namespace App\Http\Controllers;

use App\Models\WageAdjustment;
use App\Models\Worker;
use App\Services\CashBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Penyesuaian upah pekerja: potongan (hukuman) & penimpaan angka upah.
 *
 * SEMUA endpoint di sini owner-only — lihat routes/api.php. Kasir tidak boleh
 * menyentuh, karena ini menentukan berapa uang yang diterima orang lain dan
 * langsung mengubah laba bersih yang dilaporkan.
 *
 * Uang yang tidak jadi dibayarkan otomatis menaikkan laba: laporan memakai
 * upah SETELAH penyesuaian (WageService), dan laba dihitung
 * "... - upah - pengeluaran". Tidak ada pos terpisah untuk potongan.
 */
class WageAdjustmentController extends Controller
{
    /**
     * GET /api/wage-adjustments?date=2026-08-20
     * GET /api/wage-adjustments?from=..&to=..
     * GET /api/wage-adjustments?worker_id=3
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'      => ['sometimes', 'date_format:Y-m-d'],
            'from'      => ['sometimes', 'date_format:Y-m-d'],
            'to'        => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
        ]);

        $query = WageAdjustment::query()->orderByDesc('date')->orderByDesc('id');

        if (isset($data['from'], $data['to'])) {
            $query->whereBetween('date', [$data['from'], $data['to']]);
        } elseif (isset($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        if (isset($data['worker_id'])) {
            $query->where('worker_id', $data['worker_id']);
        }

        $rows = $query->get();

        return response()->json([
            'data'    => $rows,
            'summary' => [
                'total_potongan' => (int) $rows->where('type', WageAdjustment::POTONGAN)->sum('amount'),
                'jumlah_timpa'   => $rows->where('type', WageAdjustment::TIMPA)->count(),
            ],
        ]);
    }

    /** POST /api/wage-adjustments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'type'      => ['required', Rule::in([WageAdjustment::POTONGAN, WageAdjustment::TIMPA])],
            // 'timpa' boleh 0 (upah hari itu dinolkan); 'potongan' minimal 1,
            // karena potongan Rp 0 tidak mengubah apa pun selain menambah
            // baris yang membingungkan saat dibaca ulang.
            'amount'    => ['required', 'integer', 'min:0', 'max:100000000'],
            'reason'    => ['nullable', 'string', 'max:160'],
            'date'      => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if ($data['type'] === WageAdjustment::POTONGAN && $data['amount'] < 1) {
            return response()->json([
                'message' => 'Nominal potongan harus lebih dari 0.',
            ], 422);
        }

        $worker  = Worker::findOrFail($data['worker_id']);
        $tanggal = $data['date'] ?? now()->toDateString();

        // Buku kas HANYA dibubuhkan untuk hari ini — memanggil current() untuk
        // tanggal lampau akan membuka kembali buku yang sudah ditutup &
        // disetor. Pola yang sama dipakai ExpenseController::store().
        $bookId = $tanggal === now()->toDateString()
            ? app(CashBookService::class)->current(by: $request->attributes->get('auth_name'))->id
            : null;

        $adj = WageAdjustment::create([
            'worker_id'   => $worker->id,
            'worker_name' => $worker->name,   // disalin: lihat catatan di migrasi
            'type'        => $data['type'],
            'amount'      => $data['amount'],
            'reason'      => $data['reason'] ?? null,
            'date'        => $tanggal,
            'book_id'     => $bookId,
            'created_by'  => $request->attributes->get('auth_name'),
        ]);

        return response()->json(['data' => $adj], 201);
    }

    /** DELETE /api/wage-adjustments/{wageAdjustment} — koreksi salah input. */
    public function destroy(WageAdjustment $wageAdjustment): JsonResponse
    {
        $wageAdjustment->delete();

        return response()->json(['data' => null]);
    }
}

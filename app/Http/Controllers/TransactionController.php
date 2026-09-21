<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $service) {}

    /** GET /api/transactions?date=2026-07-11 */
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            'data' => Transaction::with('workers:id,name', 'addons', 'fnbSales.items')
                ->whereDate('date', $date)
                ->orderByDesc('queue_no')
                ->get(),
        ]);
    }

    /** POST /api/transactions */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->service->create(
            $request->validated() + ['created_by' => $request->attributes->get('auth_name')],
        );

        return response()->json(['data' => $transaction], 201);
    }

    /**
     * PATCH /api/transactions/{transaction} — koreksi isi transaksi, owner saja.
     *
     * Bukan pengganti void: void membatalkan transaksi yang tidak jadi,
     * koreksi membetulkan transaksi yang tetap terjadi tapi salah dicatat
     * (mis. mobil kecil telanjur dipilih 'mobil'). Total & upah dihitung ulang
     * server; jejaknya disimpan di kolom edited_* — lihat TransactionService::update.
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        return response()->json([
            'data' => $this->service->update(
                $transaction,
                $request->validated(),
                $request->attributes->get('auth_name'),
            ),
        ]);
    }

    /**
     * POST /api/transactions/{transaction}/void {reason}
     * Koreksi kesalahan input: transaksi ditandai batal, bukan dihapus.
     *
     * Owner membatalkan langsung. Kasir hanya MENGAJUKAN — transaksinya tetap
     * dihitung di rekap sampai owner menyetujui, supaya angka harian tidak
     * bisa diubah sepihak.
     */
    public function void(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $oleh    = $request->attributes->get('auth_name');
        $isOwner = $request->attributes->get('auth_role') === 'owner';

        return response()->json([
            'data' => $isOwner
                ? $this->service->void($transaction, $data['reason'], $oleh)
                : $this->service->requestVoid($transaction, $data['reason'], $oleh),
        ]);
    }

    /** GET /api/transactions/void-requests — antrean persetujuan owner. */
    public function voidRequests(): JsonResponse
    {
        return response()->json([
            'data' => Transaction::with('workers:id,name', 'addons')
                ->pendingVoid()
                ->orderBy('void_requested_at')
                ->get(),
        ]);
    }

    /** POST /api/transactions/{transaction}/void/approve — khusus owner. */
    public function approveVoid(Request $request, Transaction $transaction): JsonResponse
    {
        return response()->json([
            'data' => $this->service->approveVoid($transaction, $request->attributes->get('auth_name')),
        ]);
    }

    /** POST /api/transactions/{transaction}/void/reject {reason} — khusus owner. */
    public function rejectVoid(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->service->rejectVoid($transaction, $data['reason'], $request->attributes->get('auth_name')),
        ]);
    }
}

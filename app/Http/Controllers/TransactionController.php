<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * POST /api/transactions/backdated {date, book_number?, worker_ids?, rows:[...], fnb:[...], expenses:[...]} — owner saja.
     * Cucian, makanan/minuman, dan pengeluaran tanggal lampau, banyak baris sekaligus. Aturannya di
     * TransactionService::createBackdated().
     */
    public function storeBackdated(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'                  => ['required', 'date_format:Y-m-d', 'before:today'],
            // Nomor buku kas tanggal itu (Buku 1, Buku 2, ...). Kosong = tanpa buku.
            'book_number'           => ['nullable', 'integer', 'min:1', 'max:20'],
            'worker_ids'            => ['sometimes', 'array'],
            'worker_ids.*'          => ['integer', 'exists:workers,id'],
            // Boleh kosong bila hanya menyusulkan pengeluaran; minimal salah
            // satu harus terisi (diperiksa di service).
            'rows'                  => ['nullable', 'array', 'max:100'],
            'rows.*.vehicle_name'   => ['required', 'string', 'max:100'],
            'rows.*.category'       => ['required', Rule::exists('wash_categories', 'slug')],
            'rows.*.service'        => ['sometimes', Rule::exists('wash_services', 'slug')],
            'rows.*.payment_method' => ['required', Rule::in(['cash', 'tf'])],
            'rows.*.plate'          => ['nullable', 'string', 'max:20'],
            'rows.*.tip'            => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'rows.*.time'           => ['nullable', 'date_format:H:i'],
            'fnb'                    => ['nullable', 'array', 'max:50'],
            'fnb.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'fnb.*.qty'              => ['required', 'integer', 'min:1', 'max:1000'],
            'fnb.*.payment_method'   => ['required', Rule::in(['cash', 'tf'])],
            'expenses'               => ['nullable', 'array', 'max:50'],
            'expenses.*.description' => ['required', 'string', 'max:160'],
            'expenses.*.amount'      => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $trx = $this->service->createBackdated(
            $data['date'],
            $data['rows'] ?? [],
            $data['worker_ids'] ?? [],
            $request->attributes->get('auth_name'),
            isset($data['book_number']) ? (int) $data['book_number'] : null,
            $data['expenses'] ?? [],
            $data['fnb'] ?? [],
        );

        return response()->json(['data' => $trx], 201);
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

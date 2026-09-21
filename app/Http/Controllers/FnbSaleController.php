<?php

namespace App\Http\Controllers;

use App\Models\FnbSale;
use App\Services\FnbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FnbSaleController extends Controller
{
    public function __construct(private FnbService $service) {}

    /** GET /api/fnb-sales?date=2026-07-11 */
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            // Transaksi cucinya ikut dibawa (plat & nama kendaraan saja):
            // kasir perlu diperingatkan sebelum membatalkan pesanan yang
            // menempel pada sebuah cucian — lihat voidFnb() di kasir.js.
            'data' => FnbSale::with(['items', 'transaction:id,plate,vehicle_name,total,voided_at'])
                ->whereDate('date', $date)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * POST /api/fnb-sales/{fnbSale}/void {reason}
     * Koreksi kesalahan input: penjualan ditandai batal, bukan dihapus.
     *
     * Owner membatalkan langsung. Kasir hanya MENGAJUKAN — penjualannya tetap
     * dihitung di rekap sampai owner menyetujui, supaya angka harian tidak
     * bisa diubah sepihak. Sejalan dengan pembatalan transaksi cuci.
     */
    public function void(Request $request, FnbSale $fnbSale): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $oleh    = $request->attributes->get('auth_name');
        $isOwner = $request->attributes->get('auth_role') === 'owner';

        return response()->json([
            'data' => $isOwner
                ? $this->service->void($fnbSale, $data['reason'], $oleh)
                : $this->service->requestVoid($fnbSale, $data['reason'], $oleh),
        ]);
    }

    /** GET /api/fnb-sales/void-requests — antrean persetujuan owner. */
    public function voidRequests(): JsonResponse
    {
        return response()->json([
            'data' => FnbSale::with(['items', 'transaction:id,plate,vehicle_name,total,voided_at'])
                ->pendingVoid()
                ->orderBy('void_requested_at')
                ->get(),
        ]);
    }

    /** POST /api/fnb-sales/{fnbSale}/void/approve — khusus owner. */
    public function approveVoid(Request $request, FnbSale $fnbSale): JsonResponse
    {
        return response()->json([
            'data' => $this->service->approveVoid($fnbSale, $request->attributes->get('auth_name')),
        ]);
    }

    /** POST /api/fnb-sales/{fnbSale}/void/reject {reason} — khusus owner. */
    public function rejectVoid(Request $request, FnbSale $fnbSale): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->service->rejectVoid($fnbSale, $data['reason'], $request->attributes->get('auth_name')),
        ]);
    }

    /** POST /api/fnb-sales */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method'     => ['required', Rule::in(['cash', 'tf'])],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty'        => ['required', 'integer', 'min:1', 'max:100'],
            'tip'                => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // Diisi bila penjualan ini berasal dari draft — draftnya ikut
            // terhapus di FnbService::create() begitu penjualan tersimpan.
            'draft_id'           => ['nullable', 'integer', 'exists:fnb_drafts,id'],
        ]);

        return response()->json([
            'data' => $this->service->create($data + ['created_by' => $request->attributes->get('auth_name')]),
        ], 201);
    }
}

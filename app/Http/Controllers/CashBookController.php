<?php

namespace App\Http\Controllers;

use App\Models\CashBook;
use App\Services\CashBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Siklus hidup buku kas: ajukan setoran (kasir), setujui/tolak (owner).
 * Rincian angka tiap buku ada di GET /api/reports/daily (kunci 'books') —
 * di sini hanya aksi yang MENGUBAH statusnya.
 */
class CashBookController extends Controller
{
    public function __construct(private CashBookService $service) {}

    /**
     * GET /api/cash-books/pending — owner-only. Semua buku yang sedang
     * menunggu persetujuan, TIDAK dibatasi tanggal hari ini saja: sama
     * seperti daftar pengajuan pembatalan transaksi, owner perlu tetap
     * melihatnya walau sedang membuka laporan hari lain atau baru masuk
     * setelah beberapa hari.
     */
    public function pending(): JsonResponse
    {
        return response()->json([
            'data' => CashBook::where('status', 'pending')
                ->orderBy('requested_at')
                ->get(),
        ]);
    }

    /**
     * PUT /api/cash-books/{cashBook}/opening-balance {amount}
     * Kasir/owner mencatat saldo kas kecil buku ini — biasanya sekali tiap
     * pagi sebelum transaksi pertama. Tidak owner-only: ini kegiatan rutin
     * kasir, sama seperti mencatat pengeluaran.
     */
    public function setOpeningBalance(Request $request, CashBook $cashBook): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        return response()->json([
            'data' => $this->service->setOpeningBalance($cashBook, $data['amount']),
        ]);
    }

    /** POST /api/cash-books/{cashBook}/request-deposit — kasir menutup buku ini. */
    public function requestDeposit(Request $request, CashBook $cashBook): JsonResponse
    {
        $by = $request->attributes->get('auth_name');

        return response()->json([
            'data' => $this->service->requestDeposit($cashBook, $by),
        ]);
    }

    /** POST /api/cash-books/{cashBook}/approve — owner menerima setoran. */
    public function approve(Request $request, CashBook $cashBook): JsonResponse
    {
        $by = $request->attributes->get('auth_name');

        return response()->json([
            'data' => $this->service->approveDeposit($cashBook, $by),
        ]);
    }

    /** POST /api/cash-books/{cashBook}/reject {reason} — owner menolak setoran. */
    public function reject(Request $request, CashBook $cashBook): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $by = $request->attributes->get('auth_name');

        return response()->json([
            'data' => $this->service->rejectDeposit($cashBook, $data['reason'], $by),
        ]);
    }
}

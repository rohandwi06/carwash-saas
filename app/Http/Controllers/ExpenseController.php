<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\CashBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * GET /api/expenses?date=2026-07-27
     * GET /api/expenses?from=2026-07-01&to=2026-07-31
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = Expense::query()->orderByDesc('id');

        if (isset($data['from'], $data['to'])) {
            $query->whereBetween('date', [$data['from'], $data['to']]);
        } else {
            $query->whereDate('date', $data['date'] ?? now()->toDateString());
        }

        return response()->json(['data' => $query->get()]);
    }

    /** POST /api/expenses — kasir juga boleh mencatat (belanja sabun dll). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'],
            'amount'      => ['required', 'integer', 'min:1', 'max:100000000'],
            'date'        => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $tanggal = $data['date'] ?? now()->toDateString();

        // Buku kas HANYA dibubuhkan untuk pengeluaran hari ini. Layar
        // Pengeluaran mengizinkan mencatat untuk tanggal lampau lewat
        // kalender (mis. nota yang baru ketemu); memanggil current() untuk
        // tanggal lampau akan diam-diam MEMBUKA KEMBALI buku hari yang sudah
        // lama ditutup & disetor — bukan cuma menandai, itu perubahan
        // keadaan yang tidak diminta siapa pun.
        $bookId = $tanggal === now()->toDateString()
            ? app(CashBookService::class)->current(by: $request->attributes->get('auth_name'))->id
            : null;

        $expense = Expense::create($data + [
            'date'       => $tanggal,
            'book_id'    => $bookId,
            'created_by' => $request->attributes->get('auth_name'),
        ]);

        return response()->json(['data' => $expense], 201);
    }

    /** PATCH /api/expenses/{expense} — khusus owner (lihat routes/api.php). */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:160'],
            'amount'      => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'date'        => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $expense->update($data);

        return response()->json(['data' => $expense]);
    }

    /** DELETE /api/expenses/{expense} — khusus owner. */
    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['data' => null]);
    }
}

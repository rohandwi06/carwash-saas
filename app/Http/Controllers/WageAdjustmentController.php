<?php

namespace App\Http\Controllers;

use App\Models\WageAdjustment;
use App\Models\Worker;
use App\Services\CashBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * GET /api/wage-adjustments?dates[]=2026-09-09&dates[]=2026-09-14
     *     -> tanggal-tanggal pilihan di kalender Upah & potongan, boleh loncat-loncat
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'      => ['sometimes', 'date_format:Y-m-d'],
            'from'      => ['sometimes', 'date_format:Y-m-d'],
            'to'        => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
            'dates'     => ['sometimes', 'array', 'max:100'],
            'dates.*'   => ['date_format:Y-m-d'],
        ]);

        $query = WageAdjustment::query()->orderByDesc('date')->orderByDesc('id');

        if (! empty($data['dates'])) {
            // whereDate per tanggal, bukan whereIn: kolom 'date' di SQLite
            // (database tes) tersimpan lengkap dengan jam, jadi whereIn
            // terhadap 'Y-m-d' tidak pernah cocok di sana.
            $query->where(function ($q) use ($data) {
                foreach ($data['dates'] as $t) {
                    $q->orWhereDate('date', $t);
                }
            });
        } elseif (isset($data['from'], $data['to'])) {
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

    /** POST /api/wage-adjustments — satu tanggal (dipakai aplikasi Android). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->aturanCatat() + [
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if ($tolak = $this->tolakPotonganNol($data)) {
            return $tolak;
        }

        $worker = Worker::findOrFail($data['worker_id']);
        $adj    = $this->catat($request, $worker, $data, $data['date'] ?? now()->toDateString());

        return response()->json(['data' => $adj], 201);
    }

    /**
     * POST /api/wage-adjustments/bulk  {"worker_id":3, "type":"potongan",
     *                                   "amount":5000, "dates":["2026-09-09", ...]}
     *
     * Catat sekaligus dari kalender potongan: angka yang sama untuk SETIAP
     * tanggal (per tanggal, bukan total yang dibagi). Satu transaksi — kalau
     * satu tanggal gagal, tidak ada yang tercatat, supaya owner tidak perlu
     * menebak tanggal mana yang sudah masuk.
     */
    public function storeMany(Request $request): JsonResponse
    {
        $data = $request->validate($this->aturanCatat() + [
            'dates'   => ['required', 'array', 'min:1', 'max:62'],
            'dates.*' => ['date_format:Y-m-d', 'distinct'],
        ]);

        if ($tolak = $this->tolakPotonganNol($data)) {
            return $tolak;
        }

        $worker  = Worker::findOrFail($data['worker_id']);
        $tanggal = collect($data['dates'])->sort()->values();

        $rows = DB::transaction(fn () => $tanggal->map(
            fn (string $t) => $this->catat($request, $worker, $data, $t)
        ));

        return response()->json(['data' => $rows], 201);
    }

    /** Aturan bersama store() & storeMany(); tanggalnya ditambahkan masing-masing. */
    private function aturanCatat(): array
    {
        return [
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'type'      => ['required', Rule::in([WageAdjustment::POTONGAN, WageAdjustment::TIMPA])],
            // 'timpa' boleh 0 (upah hari itu dinolkan); 'potongan' minimal 1,
            // karena potongan Rp 0 tidak mengubah apa pun selain menambah
            // baris yang membingungkan saat dibaca ulang.
            'amount'    => ['required', 'integer', 'min:0', 'max:100000000'],
            'reason'    => ['nullable', 'string', 'max:160'],
        ];
    }

    private function tolakPotonganNol(array $data): ?JsonResponse
    {
        if ($data['type'] === WageAdjustment::POTONGAN && $data['amount'] < 1) {
            return response()->json([
                'message' => 'Nominal potongan harus lebih dari 0.',
            ], 422);
        }

        return null;
    }

    private function catat(Request $request, Worker $worker, array $data, string $tanggal): WageAdjustment
    {
        // Buku kas HANYA dibubuhkan untuk hari ini — memanggil current() untuk
        // tanggal lampau akan membuka kembali buku yang sudah ditutup &
        // disetor. Pola yang sama dipakai ExpenseController::store().
        $bookId = $tanggal === now()->toDateString()
            ? app(CashBookService::class)->current(by: $request->attributes->get('auth_name'))->id
            : null;

        return WageAdjustment::create([
            'worker_id'   => $worker->id,
            'worker_name' => $worker->name,   // disalin: lihat catatan di migrasi
            'type'        => $data['type'],
            'amount'      => $data['amount'],
            'reason'      => $data['reason'] ?? null,
            'date'        => $tanggal,
            'book_id'     => $bookId,
            'created_by'  => $request->attributes->get('auth_name'),
        ]);
    }

    /** DELETE /api/wage-adjustments/{wageAdjustment} — koreksi salah input. */
    public function destroy(WageAdjustment $wageAdjustment): JsonResponse
    {
        $wageAdjustment->delete();

        return response()->json(['data' => null]);
    }

    /**
     * POST /api/wage-adjustments/bulk-delete  {"ids": [3, 5, 8]}
     *
     * Hapus sekaligus dari kalender potongan (satu tanggal atau rentang,
     * opsional hanya milik satu pekerja). Sengaja memakai daftar id, bukan
     * tanggal + pekerja: yang terhapus persis baris yang tadi ditampilkan di
     * dialog konfirmasi owner — catatan yang masuk dari HP lain di sela-sela
     * itu tidak ikut tersapu.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $jumlah = WageAdjustment::whereIn('id', $data['ids'])->delete();

        return response()->json(['data' => ['deleted' => $jumlah]]);
    }
}

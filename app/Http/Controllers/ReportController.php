<?php

namespace App\Http\Controllers;

use App\Models\FnbSale;
use App\Models\Transaction;
use App\Services\BookkeepingService;
use App\Services\StatisticsService;
use App\Services\WageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private BookkeepingService $books,
        private StatisticsService $stats,
        private WageService $wages,
    ) {}

    /** GET /api/reports/stats?period=harian|mingguan|bulanan — laporan & statistik dashboard */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['harian', 'mingguan', 'bulanan'])],
        ]);

        return response()->json([
            'data' => $this->stats->report($data['period'] ?? 'harian'),
        ]);
    }

    /**
     * GET /api/reports/daily?date=Y-m-d
     *   &book=3   -> hanya buku kas dengan id itu (lihat GET /api/cash-books)
     *
     * Rekap format buku kas fisik: Total / Tip / TF / Cash Motor / Cash
     * Mobil / Cash Total, dikurangi upah & pengeluaran -> laba bersih.
     */
    public function daily(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $bookId = $request->query('book') !== null ? (int) $request->query('book') : null;

        return response()->json(['data' => $this->books->dailyRecap($date, $bookId)]);
    }

    /** GET /api/reports/calendar?year=2026&month=7 — data kalender pembukuan */
    public function calendar(Request $request): JsonResponse
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return response()->json(['data' => $this->books->calendar($year, $month)]);
    }

    /** GET /api/reports/date-range?from=2026-07-01&to=2026-07-31 — data range tanggal pembukuan */
    public function dateRange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->books->dateRange($data['from'], $data['to'])]);
    }

    /** GET /api/reports/wages?from=2026-07-01&to=2026-07-31 — rekap upah per pekerja untuk range tanggal */
    public function wages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->wages->byDateRange($data['from'], $data['to'])]);
    }

    /** GET /api/reports/daily/csv?date=... — unduh laporan harian */
    public function dailyCsv(Request $request): StreamedResponse
    {
        $date  = $request->query('date', now()->toDateString());
        $recap = $this->books->dailyRecap($date);
        $trx   = Transaction::with('workers:id,name', 'addons')
            ->whereDate('date', $date)->orderBy('queue_no')->get();
        $fnb   = FnbSale::valid()->with('items')->whereDate('date', $date)->orderBy('id')->get();

        return response()->streamDownload(function () use ($recap, $trx, $fnb) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel baca UTF-8

            fputcsv($out, ['LAPORAN HARIAN OTIN CARWASH', $recap['date']], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['No', 'Kendaraan', 'Jenis', 'Layanan', 'Tambahan', 'Bayar', 'Plat', 'Pekerja', 'Tip', 'Total', 'Status', 'Dicatat oleh'], ';');

            foreach ($trx as $t) {
                fputcsv($out, [
                    $t->queue_no, $t->vehicle_name, $t->category, $t->service,
                    $t->addons->map(fn ($a) => $a->pivot->name.' ('.$a->pivot->price.')')->implode(', '),
                    strtoupper($t->payment_method), $t->plate ?? 'plat kosong',
                    $t->workers->pluck('name')->implode(' + '),
                    $t->tip ?: '',
                    $t->voided_at ? 0 : $t->total,
                    $t->voided_at ? 'BATAL: '.$t->void_reason : '',
                    $t->created_by ?? '',
                ], ';');
            }

            fputcsv($out, [], ';');
            fputcsv($out, ['=== MAKANAN & MINUMAN ==='], ';');
            fputcsv($out, ['Jam', 'Item', 'Bayar', 'Total', 'Dicatat oleh'], ';');
            foreach ($fnb as $sale) {
                $itemsText = $sale->items
                    ->map(fn ($i) => $i->product_name.' x'.$i->qty)
                    ->implode(', ');
                fputcsv($out, [
                    $sale->created_at?->format('H:i'), $itemsText,
                    strtoupper($sale->payment_method), $sale->total,
                    $sale->created_by ?? '',
                ], ';');
            }
            fputcsv($out, ['', 'TOTAL F&B', '', $recap['fnb_total']], ';');

            fputcsv($out, [], ';');
            fputcsv($out, ['=== PENGELUARAN ==='], ';');
            fputcsv($out, ['Keterangan', 'Jumlah', 'Dicatat oleh'], ';');
            foreach ($recap['expense_list'] as $e) {
                fputcsv($out, [$e->description, $e->amount, $e->created_by ?? ''], ';');
            }
            fputcsv($out, ['TOTAL PENGELUARAN', $recap['expenses']], ';');

            fputcsv($out, [], ';');
            fputcsv($out, ['Total', $recap['total']], ';');
            fputcsv($out, ['Tip', $recap['tip'] ?: 'kosong'], ';');
            fputcsv($out, ['TF', $recap['tf'] ?: 'kosong'], ';');
            fputcsv($out, ['Cash Motor', $recap['cash_motor'] ?: 'kosong'], ';');
            fputcsv($out, ['Cash Mobil', $recap['cash_mobil'] ?: 'kosong'], ';');
            fputcsv($out, ['F&B', $recap['fnb_total'] ?: 'kosong'], ';');
            fputcsv($out, ['Cash F&B', $recap['fnb_cash'] ?: 'kosong'], ';');
            fputcsv($out, ['Cash Total', $recap['cash_total']], ';');
            fputcsv($out, ['Upah pekerja', $recap['wages']], ';');
            fputcsv($out, ['Pengeluaran', $recap['expenses']], ';');
            fputcsv($out, ['LABA BERSIH', $recap['profit']], ';');

            fclose($out);
        }, "laporan-otin-carwash-{$date}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

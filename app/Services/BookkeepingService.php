<?php

namespace App\Services;

use App\Models\CashBook;
use App\Models\Expense;
use App\Models\FnbSale;
use App\Models\Transaction;
use App\Models\WashCategory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap pembukuan (meniru format buku kas fisik):
 * Total / Tip / TF / Cash Motor / Cash Mobil / Cash Total,
 * dikurangi upah & pengeluaran -> laba bersih.
 */
class BookkeepingService
{
    public function __construct(
        private WageService $wages,
        private ConsignmentService $titipan,
    ) {}

    /**
     * @param  int|null  $bookId  penyaring buku kas tunggal, atau null untuk
     *                            sehari penuh (semua buku digabung)
     */
    public function dailyRecap(string $date, ?int $bookId = null): array
    {
        $saring = fn ($query) => $bookId === null ? $query : $query->where('book_id', $bookId);

        $trx = $saring(Transaction::valid()->whereDate('date', $date))->get();

        $total     = (int) $trx->sum('total');
        $tf        = (int) $trx->where('payment_method', 'tf')->sum('total');
        $cashMotor = (int) $trx->where('payment_method', 'cash')->where('category', 'motor')->sum('total');
        $cashMobil = (int) $trx->where('payment_method', 'cash')->where('category', '!=', 'motor')->sum('total');
        $wages     = $this->wages->dailyTotal($date, $bookId);

        $fnb      = $saring(FnbSale::valid()->whereDate('date', $date))->get();
        $fnbTotal = (int) $fnb->sum('total');
        $fnbCash  = (int) $fnb->where('payment_method', 'cash')->sum('total');
        $fnbTf    = (int) $fnb->where('payment_method', 'tf')->sum('total');

        // Tip cuci + tip F&B jadi satu baris "Tip". Keduanya di luar omzet
        // masing-masing dan sama-sama masuk laba — lihat migrasi
        // add_tip_to_fnb_sales_table.
        $tip = (int) $trx->sum('tip') + (int) $fnb->sum('tip');

        $expenses      = $saring(Expense::whereDate('date', $date))->orderByDesc('id')->get();
        $expenseTotal  = (int) $expenses->sum('amount');

        // Setoran ke penitip ADALAH uang keluar (ikut 'expenses' & saldo kas),
        // tapi BUKAN biaya yang memotong laba: hak penitip sudah dipotong sejak
        // barangnya laku lewat $hakPenitip di bawah. Tanpa pemisahan ini,
        // uang yang sama dipotong dua kali dan laba jadi lebih kecil dari
        // yang sebenarnya.
        $setoranTitipan = (int) $expenses->where('is_consignment', true)->sum('amount');
        $expenseOperasional = $expenseTotal - $setoranTitipan;

        // Hak penitip dari barang titipan yang laku HARI INI. Uangnya sudah
        // masuk laci (ikut fnb_cash), tapi ia milik orang lain.
        $hakPenitip = $this->titipan->hakTerjual(from: $date, to: $date);

        return [
            'date'          => $date,
            'vehicles'      => $trx->count(),
            'total'         => $total,
            'tip'           => $tip,
            'tf'            => $tf,
            'cash_motor'    => $cashMotor,
            'cash_mobil'    => $cashMobil,
            'fnb_total'     => $fnbTotal,
            'fnb_cash'      => $fnbCash,
            'fnb_tf'        => $fnbTf,
            'cash_total'    => $cashMotor + $cashMobil + $fnbCash,
            'wages'         => $wages,
            'expenses'      => $expenseTotal,
            'expense_list'  => $expenses,
            // Baris sendiri di layar rekap: owner harus bisa melihat berapa
            // dari uang di laci hari ini yang sebenarnya bukan miliknya.
            'consignor_share' => $hakPenitip,
            'consignor_payout' => $setoranTitipan,
            'profit'        => $total + $tip + $fnbTotal - $wages - $expenseOperasional - $hakPenitip,
            'by_payment'    => $this->rincianBayar($trx),
            'worker_wages'  => $this->wages->dailyRecap($date, $bookId),
            // Daftar buku SELALU dari sehari penuh, bukan dari hasil yang
            // sudah disaring — layar memakainya untuk menggambar tab, dan
            // buku lain harus tetap terlihat saat salah satunya sedang dibuka.
            'books'         => $this->rekapBuku(
                $date,
                Transaction::valid()->whereDate('date', $date)->get(),
                FnbSale::valid()->whereDate('date', $date)->get(),
                Expense::whereDate('date', $date)->get(),
            ),
        ];
    }

    /**
     * Rincian tiap cara bayar menurut jenis kendaraan: berapa kali dan berapa
     * rupiah. Dipakai baris TF & Cash di Rekap Hari Ini yang bisa dibuka.
     *
     * "Cash Rp 330.000" saja tidak menjawab pertanyaan yang biasanya muncul
     * saat menghitung uang laci — motor berapa, mobil berapa.
     *
     * Urutan jenis kendaraan mengikuti katalog (motor, mobil kecil, mobil),
     * sama seperti layar lain, bukan abjad atau besar-kecilnya angka.
     */
    private function rincianBayar(Collection $trx): array
    {
        if ($trx->isEmpty()) {
            return [];
        }

        $urutan = WashCategory::orderBy('sort_order')->pluck('slug')->values()->all();
        $posisi = fn (string $slug) => ($i = array_search($slug, $urutan, true)) === false
            ? PHP_INT_MAX
            : $i;

        // cash dulu, baru tf — urutan yang sama dengan buku kas fisik. Cara
        // bayar lain (kalau kelak ada) menyusul di belakang, bukan hilang.
        $metode = $trx->pluck('payment_method')->unique()
            ->sortBy(fn ($m) => ['cash' => 0, 'tf' => 1][$m] ?? 2)
            ->values();

        return $metode->map(function (string $m) use ($trx, $posisi) {
            $baris = $trx->where('payment_method', $m);

            return [
                'method' => $m,
                'count'  => $baris->count(),
                'total'  => (int) $baris->sum('total'),
                'rows'   => $baris->groupBy('category')
                    ->map(fn (Collection $g, string $kat) => [
                        'category' => $kat,
                        'count'    => $g->count(),
                        'total'    => (int) $g->sum('total'),
                    ])
                    ->sortBy(fn (array $r) => $posisi($r['category']))
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    /**
     * Omzet & jumlah kendaraan dipecah per buku kas, lengkap dengan status
     * setoran (open/pending/deposited/rejected) dan nama kasir yang mencatat.
     *
     * Setiap baris SUDAH pasti punya book_id (lihat CashBookService::current()
     * — tidak ada lagi kelompok "tanpa buku" seperti dulu "di luar shift").
     * Buku yang belum kemasukan transaksi apa pun pada tanggal ini (mis. buku
     * baru yang baru dibuka) tetap disertakan dengan angka nol, supaya tab
     * "Buku 2" tidak menghilang begitu owner membuka Rekap sesaat setelah
     * kasir mengajukan setoran.
     */
    private function rekapBuku(string $date, Collection $trx, Collection $fnb, Collection $expenses): array
    {
        $books = CashBook::where('date', $date)->orderBy('number')->get();

        if ($books->isEmpty()) {
            return [];
        }

        $trxPerBuku = $trx->groupBy('book_id');
        $fnbPerBuku = $fnb->groupBy('book_id');
        $keluarPerBuku = $expenses->groupBy('book_id');

        return $books->map(function (CashBook $b) use ($trxPerBuku, $fnbPerBuku, $keluarPerBuku) {
            $t = $trxPerBuku->get($b->id, collect());
            $f = $fnbPerBuku->get($b->id, collect());
            $k = $keluarPerBuku->get($b->id, collect());

            $kasir = $t->pluck('created_by')->merge($f->pluck('created_by'))
                ->filter()->unique()->values()->all();

            $washCash = (int) $t->where('payment_method', 'cash')->sum('total');
            $fnbCash  = (int) $f->where('payment_method', 'cash')->sum('total');

            $pengeluaranBuku = (int) $k->sum('amount');

            return [
                'id'       => $b->id,
                'number'   => $b->number,
                'label'    => $b->label(),
                'status'   => $b->status,
                'vehicles' => $t->count(),
                'wash_total' => (int) $t->sum('total'),
                'tip'      => (int) $t->sum('tip') + (int) $f->sum('tip'),
                'fnb_total' => (int) $f->sum('total'),
                // Saldo kas kecil buku ini — MURNI informasi tambahan untuk
                // kasir, tidak menyentuh omzet/laba di bawah. null kalau
                // belum pernah diisi (bukan 0, biar jelas "belum diisi").
                'opening_balance' => $b->opening_balance,
                'remaining_balance' => $b->opening_balance !== null
                    ? $b->opening_balance - $pengeluaranBuku
                    : null,
                // Omzet bersih buku ini: uang masuk dikurangi pengeluaran yang
                // dicatat di buku yang sama. Pengeluaran bertanggal lampau
                // tidak punya book_id (lihat ExpenseController::store), jadi
                // tidak menempel ke buku mana pun — itu memang disengaja.
                'omzet'    => (int) $t->sum('total') + (int) $f->sum('total')
                              + (int) $t->sum('tip') + (int) $f->sum('tip')
                              - (int) $k->sum('amount'),
                'cashiers' => $kasir,
                // Buku yang masih terbuka belum punya angka beku — dihitung
                // hidup dari data yang sama seperti CashBookService::cashAmount(),
                // supaya kasir & owner melihat perkiraan yang selalu terkini.
                // Buku yang sudah diajukan/disetor/ditolak memakai angka yang
                // DIBEKUKAN saat diajukan (b->amount), bukan dihitung ulang —
                // itulah intinya "dibekukan", supaya koreksi data belakangan
                // tidak diam-diam mengubah angka yang sudah disepakati.
                'amount'   => $b->status === 'open' ? ($washCash + $fnbCash - (int) $k->sum('amount')) : $b->amount,
                'opened_at'    => optional($b->opened_at)->toIso8601String(),
                'opened_by'    => $b->opened_by,
                'requested_at' => optional($b->requested_at)->toIso8601String(),
                'requested_by' => $b->requested_by,
                'approved_at'  => optional($b->approved_at)->toIso8601String(),
                'approved_by'  => $b->approved_by,
                'rejected_at'  => optional($b->rejected_at)->toIso8601String(),
                'rejected_by'  => $b->rejected_by,
                'reject_reason' => $b->reject_reason,
            ];
        })->values()->all();
    }

    /**
     * Data kalender: SEMUA hari dalam sebulan yang punya catatan apa pun
     * (transaksi / F&B / pengeluaran), masing-masing dengan rekap lengkap
     * — omzet, tip, F&B, upah, pengeluaran, laba — meniru satu baris buku kas.
     */
    public function calendar(int $year, int $month): array
    {
        $awal = Carbon::create($year, $month, 1)->startOfMonth();

        return ['year' => $year, 'month' => $month]
            + $this->dateRange($awal->toDateString(), $awal->copy()->endOfMonth()->toDateString());
    }

    /** Rekap per tanggal untuk sebuah rentang, plus ringkasan totalnya. */
    public function dateRange(string $from, string $to): array
    {
        $tglKey = fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d;

        $wash = Transaction::valid()
            ->selectRaw('date, COUNT(*) as vehicles, SUM(total) as total, SUM(tip) as tip')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')->get();

        $fnb = FnbSale::valid()
            ->selectRaw('date, SUM(total) as total, SUM(tip) as tip')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')->get();

        $keluar = Expense::query()
            ->selectRaw('date, SUM(amount) as total')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')->get();

        $upah = $this->wages->rangeByDate($from, $to);

        // Dua angka titipan, sama seperti di dailyRecap():
        //   hak penitip    -> memotong laba (uang orang yang ikut masuk laci)
        //   setoran        -> TIDAK memotong laba (sudah dipotong saat laku),
        //                     walau ikut terhitung di 'expenses' karena kas
        //                     memang berkurang.
        $hakTitipan = $this->titipan->hakTerjualPerTanggal($from, $to);

        $setoranTitipan = Expense::query()
            ->where('is_consignment', true)
            ->selectRaw('date, SUM(amount) as total')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')->get();

        $kosong = ['vehicles' => 0, 'total' => 0, 'tip' => 0, 'fnb_total' => 0, 'wages' => 0,
            'expenses' => 0, 'consignor_share' => 0, 'consignor_payout' => 0];

        $byDate = [];

        foreach ($wash as $row) {
            $key = $tglKey($row->date);
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['vehicles'] = (int) $row->vehicles;
            $byDate[$key]['total']    = (int) $row->total;
            $byDate[$key]['tip']      = (int) $row->tip;
        }
        foreach ($fnb as $row) {
            $key = $tglKey($row->date);
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['fnb_total'] = (int) $row->total;
            // Ditambahkan, bukan ditimpa: baris tip hari itu mungkin sudah
            // terisi tip cuci dari perulangan di atas.
            $byDate[$key]['tip'] += (int) $row->tip;
        }
        foreach ($keluar as $row) {
            $key = $tglKey($row->date);
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['expenses'] = (int) $row->total;
        }
        foreach ($upah as $key => $jumlah) {
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['wages'] = $jumlah;
        }
        foreach ($setoranTitipan as $row) {
            $key = $tglKey($row->date);
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['consignor_payout'] = (int) $row->total;
        }
        foreach ($hakTitipan as $key => $jumlah) {
            $byDate[$key] ??= ['date' => $key] + $kosong;
            $byDate[$key]['consignor_share'] = $jumlah;
        }

        ksort($byDate);
        $days = collect(array_values($byDate))->map(function (array $d) {
            // Omzet = uang masuk DIKURANGI pengeluaran — lihat catatan di
            // dailyRecap(). Laba ditulis lewat omzet supaya pengeluaran tidak
            // terpotong dua kali; hasilnya sama dengan rumus lama.
            $d['omzet']  = $d['total'] + $d['tip'] + $d['fnb_total']
                - ($d['expenses'] - $d['consignor_payout'])   // setoran bukan biaya
                - $d['consignor_share'];                      // hak penitip, bukan pendapatan
            $d['profit'] = $d['omzet'] - $d['wages'];
            return $d;
        });

        return [
            'from'   => $from,
            'to'     => $to,
            'days'   => $days,
            'summary'=> [
                'operating_days' => $days->count(),
                'vehicles'       => (int) $days->sum('vehicles'),
                'total'          => (int) $days->sum('omzet'),
                'wash_total'     => (int) $days->sum('total'),
                'fnb_total'      => (int) $days->sum('fnb_total'),
                'tip'            => (int) $days->sum('tip'),
                'wages'          => (int) $days->sum('wages'),
                'expenses'       => (int) $days->sum('expenses'),
                // Titipan dipisah supaya owner bisa membaca laba tanpa bertanya
                // "kok F&B-nya segini tapi labanya segitu".
                'consignor_share'  => (int) $days->sum('consignor_share'),
                'consignor_payout' => (int) $days->sum('consignor_payout'),
                'profit'         => (int) $days->sum('profit'),
            ],
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FnbSale;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan & statistik untuk dashboard: tren harian / mingguan / bulanan,
 * ringkasan periode, serta peringkat layanan, jenis kendaraan, dan add-on.
 *
 * Semua angka dihitung di sini; controller cuma meneruskan.
 */
class StatisticsService
{
    public function __construct(private WageService $wages) {}

    /** @param 'harian'|'mingguan'|'bulanan' $period */
    public function report(string $period): array
    {
        [$mulai, $selesai, $judul] = $this->rentang($period);

        $titik = $this->tren($period, $mulai, $selesai);

        return [
            'period'     => $period,
            'label'      => $judul,
            'start'      => $mulai->toDateString(),
            'end'        => $selesai->toDateString(),
            'points'     => $titik,
            'summary'    => $this->ringkasan($titik, $mulai, $selesai),
            'services'   => $this->peringkatLayanan($mulai, $selesai),
            'categories' => $this->peringkatKategori($mulai, $selesai),
            'addons'     => $this->peringkatAddon($mulai, $selesai),
        ];
    }

    /** Rentang waktu tiap periode: harian 30 hari, mingguan 12 minggu, bulanan 12 bulan. */
    private function rentang(string $period): array
    {
        $kini = CarbonImmutable::today();

        return match ($period) {
            'mingguan' => [$kini->startOfWeek()->subWeeks(11), $kini->endOfWeek(), '12 minggu terakhir'],
            'bulanan'  => [$kini->startOfMonth()->subMonths(11), $kini->endOfMonth(), '12 bulan terakhir'],
            default    => [$kini->subDays(29), $kini, '30 hari terakhir'],
        };
    }

    /**
     * Satu titik per hari/minggu/bulan — termasuk periode kosong (nilai 0),
     * supaya grafiknya tidak "melompat" di hari libur.
     */
    private function tren(string $period, CarbonImmutable $mulai, CarbonImmutable $selesai): array
    {
        $cuci = Transaction::valid()
            ->whereBetween('date', [$mulai->toDateString(), $selesai->toDateString()])
            ->get(['date', 'total', 'tip']);

        $fnb = FnbSale::valid()
            ->whereBetween('date', [$mulai->toDateString(), $selesai->toDateString()])
            ->get(['date', 'total', 'tip']);

        $keluar = Expense::whereBetween('date', [$mulai->toDateString(), $selesai->toDateString()])
            ->get(['date', 'amount']);

        $upah = $this->upahPerTanggal($mulai, $selesai);

        $kosong = ['wash' => 0, 'fnb' => 0, 'tip' => 0, 'wages' => 0, 'expenses' => 0, 'vehicles' => 0];
        $ember  = [];

        foreach ($this->kerangka($period, $mulai, $selesai) as $kunci => $label) {
            $ember[$kunci] = ['key' => $kunci, 'label' => $label] + $kosong;
        }

        foreach ($cuci as $t) {
            $k = $this->kunci($period, CarbonImmutable::parse($t->date));
            if (! isset($ember[$k])) {
                continue;
            }
            $ember[$k]['wash']     += (int) $t->total;
            $ember[$k]['tip']      += (int) $t->tip;
            $ember[$k]['vehicles'] += 1;
        }

        foreach ($fnb as $s) {
            $k = $this->kunci($period, CarbonImmutable::parse($s->date));
            if (isset($ember[$k])) {
                $ember[$k]['fnb'] += (int) $s->total;
                // Tip F&B masuk baris tip yang sama dengan tip cuci — bukan
                // omzet F&B. Lihat migrasi add_tip_to_fnb_sales_table.
                $ember[$k]['tip'] += (int) $s->tip;
            }
        }

        foreach ($keluar as $e) {
            $k = $this->kunci($period, CarbonImmutable::parse($e->date));
            if (isset($ember[$k])) {
                $ember[$k]['expenses'] += (int) $e->amount;
            }
        }

        foreach ($upah as $tanggal => $jumlah) {
            $k = $this->kunci($period, CarbonImmutable::parse($tanggal));
            if (isset($ember[$k])) {
                $ember[$k]['wages'] += $jumlah;
            }
        }

        return collect($ember)->map(function (array $d) {
            // Omzet = uang masuk (cucian + F&B + tip) DIKURANGI pengeluaran.
            // Tip ikut karena memang uang yang diterima hari itu; pengeluaran
            // dipotong di sini supaya angka omzet di layar mana pun berarti
            // hal yang sama. Lihat catatan lengkap di BookkeepingService.
            $d['omzet']  = $d['wash'] + $d['fnb'] + $d['tip'] - $d['expenses'];
            // Rumus laba TIDAK berubah hasilnya: omzet bersih dikurangi upah
            // sama persis dengan (cuci + tip + F&B - upah - pengeluaran).
            // Ditulis lewat omzet supaya pengeluaran tidak terpotong dua kali.
            $d['profit'] = $d['omzet'] - $d['wages'];

            return $d;
        })->values()->all();
    }

    /** Daftar kunci periode kosong dari awal sampai akhir rentang. */
    private function kerangka(string $period, CarbonImmutable $mulai, CarbonImmutable $selesai): array
    {
        $hasil = [];
        $t     = $mulai;

        while ($t->lessThanOrEqualTo($selesai)) {
            $hasil[$this->kunci($period, $t)] = $this->labelTitik($period, $t);
            $t = match ($period) {
                'mingguan' => $t->addWeek(),
                'bulanan'  => $t->addMonth(),
                default    => $t->addDay(),
            };
        }

        return $hasil;
    }

    private function kunci(string $period, CarbonImmutable $t): string
    {
        return match ($period) {
            'mingguan' => $t->startOfWeek()->toDateString(),
            'bulanan'  => $t->format('Y-m'),
            default    => $t->toDateString(),
        };
    }

    private function labelTitik(string $period, CarbonImmutable $t): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return match ($period) {
            'mingguan' => $t->startOfWeek()->format('j').' '.$bulan[$t->startOfWeek()->month - 1],
            'bulanan'  => $bulan[$t->month - 1].' '.$t->format('y'),
            default    => $t->format('j').' '.$bulan[$t->month - 1],
        };
    }

    /** Total upah per tanggal dalam rentang. */
    private function upahPerTanggal(CarbonImmutable $mulai, CarbonImmutable $selesai): array
    {
        return Transaction::valid()
            ->join('transaction_worker', 'transaction_worker.transaction_id', '=', 'transactions.id')
            ->whereBetween('transactions.date', [$mulai->toDateString(), $selesai->toDateString()])
            ->selectRaw('transactions.date as tgl, SUM(transaction_worker.wage_share) as upah')
            ->groupBy('transactions.date')
            ->pluck('upah', 'tgl')
            ->mapWithKeys(fn ($v, $k) => [
                ($k instanceof \DateTimeInterface ? $k->format('Y-m-d') : (string) $k) => (int) $v,
            ])->all();
    }

    private function ringkasan(array $titik, CarbonImmutable $mulai, CarbonImmutable $selesai): array
    {
        $t       = collect($titik);
        $aktif   = $t->filter(fn ($d) => $d['omzet'] > 0 || $d['vehicles'] > 0)->count();
        $omzet   = (int) $t->sum('omzet');
        $vehicle = (int) $t->sum('vehicles');

        return [
            'omzet'          => $omzet,
            'wash'           => (int) $t->sum('wash'),
            'fnb'            => (int) $t->sum('fnb'),
            'tip'            => (int) $t->sum('tip'),
            'wages'          => (int) $t->sum('wages'),
            'expenses'       => (int) $t->sum('expenses'),
            'profit'         => (int) $t->sum('profit'),
            'vehicles'       => $vehicle,
            'active_periods' => $aktif,
            // Rata-rata dihitung dari periode yang benar-benar ada transaksinya,
            // bukan dibagi rata ke hari libur — supaya tidak menyesatkan.
            'avg_omzet'      => $aktif ? intdiv($omzet, $aktif) : 0,
            'avg_ticket'     => $vehicle ? intdiv((int) $t->sum('wash'), $vehicle) : 0,
            'best'           => $t->sortByDesc('omzet')->first(),
        ];
    }

    private function peringkatLayanan(CarbonImmutable $mulai, CarbonImmutable $selesai): Collection
    {
        return Transaction::valid()
            ->leftJoin('wash_services', 'wash_services.slug', '=', 'transactions.service')
            ->whereBetween('transactions.date', [$mulai->toDateString(), $selesai->toDateString()])
            ->selectRaw('COALESCE(wash_services.label, transactions.service) as label,
                         COUNT(*) as jumlah, SUM(transactions.total) as total')
            // group by ekspresinya, bukan aliasnya — MySQL ONLY_FULL_GROUP_BY menolak alias
            ->groupByRaw('COALESCE(wash_services.label, transactions.service)')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->jumlah, 'total' => (int) $r->total]);
    }

    private function peringkatKategori(CarbonImmutable $mulai, CarbonImmutable $selesai): Collection
    {
        return Transaction::valid()
            ->leftJoin('wash_categories', 'wash_categories.slug', '=', 'transactions.category')
            ->whereBetween('transactions.date', [$mulai->toDateString(), $selesai->toDateString()])
            ->selectRaw('COALESCE(wash_categories.label, transactions.category) as label,
                         COUNT(*) as jumlah, SUM(transactions.total) as total')
            ->groupByRaw('COALESCE(wash_categories.label, transactions.category)')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->jumlah, 'total' => (int) $r->total]);
    }

    private function peringkatAddon(CarbonImmutable $mulai, CarbonImmutable $selesai): Collection
    {
        return DB::table('addon_transaction')
            ->join('transactions', 'transactions.id', '=', 'addon_transaction.transaction_id')
            ->whereNull('transactions.voided_at')
            ->whereBetween('transactions.date', [$mulai->toDateString(), $selesai->toDateString()])
            ->selectRaw('addon_transaction.name as label, COUNT(*) as jumlah, SUM(addon_transaction.price) as total')
            ->groupBy('addon_transaction.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->jumlah, 'total' => (int) $r->total]);
    }
}

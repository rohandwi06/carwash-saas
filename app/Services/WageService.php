<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WageAdjustment;
use App\Models\WageRate;
use App\Models\Worker;
use Illuminate\Support\Collection;

/**
 * Upah pekerja: tarif per jenis kendaraan + layanan, dibagi rata jika dikerjakan
 * bersama — kecuali karyawan training, yang menerima nominal tetap per cucian.
 * Aturan bisnis upah HANYA hidup di sini.
 */
class WageService
{
    /**
     * Tarif upah per jenis kendaraan + layanan. Sumbernya tabel wage_rates —
     * owner mengaturnya di Pengaturan saat menambah/mengubah jenis kendaraan.
     */
    public function rateFor(string $category, string $service): int
    {
        return (int) (WageRate::where('category', $category)
            ->where('service', $service)
            ->value('amount') ?? 0);
    }

    /**
     * Nominal karyawan training untuk satu jenis kendaraan + layanan.
     * Nyuci motor dan nyuci mobil ekstra punya angkanya masing-masing.
     */
    public function traineeWageFor(string $category, string $service): int
    {
        return (int) (WageRate::where('category', $category)
            ->where('service', $service)
            ->value('trainee_amount') ?? 0);
    }

    /** Ubah nominal training untuk satu kendaraan + layanan. */
    public function setTraineeWageFor(string $category, string $service, int $amount): WageRate
    {
        $rate = WageRate::firstOrNew(['category' => $category, 'service' => $service]);
        $rate->trainee_amount = max(0, $amount);
        $rate->amount ??= 0;
        $rate->save();

        return $rate;
    }

    /** Bagi upah satu transaksi ke pekerja yang mengerjakan. */
    public function attachWorkers(Transaction $transaction, array $workerIds): void
    {
        $workerIds = array_values(array_unique($workerIds));

        if (count($workerIds) === 0) {
            return; // boleh tanpa pekerja; upah tidak tercatat
        }

        $jatah = $this->rateFor($transaction->category, $transaction->service);
        $upahTraining = $this->traineeWageFor($transaction->category, $transaction->service);
        $training = Worker::whereIn('id', $workerIds)->where('is_trainee', true)->pluck('id')->all();

        $bagian = $this->bagiUpah($jatah, $upahTraining, $workerIds, $training);

        $pivot = collect($workerIds)
            ->mapWithKeys(fn ($id) => [$id => ['wage_share' => $bagian[$id]]])
            ->all();

        $transaction->workers()->sync($pivot);
    }

    /**
     * Aturan pembagian upah satu cucian. Dipisah dari attachWorkers() supaya
     * bisa dihitung tanpa menyentuh database — inilah satu-satunya tempat
     * angka upah per orang ditentukan.
     *
     * - Angka training adalah JATAH BERSAMA seluruh karyawan training pada
     *   cucian itu, BUKAN per orang. Dua anak training berbagi jatah yang
     *   sama; mereka tidak menggandakannya.
     * - Sisanya dibagi rata ke pekerja senior.
     * - Kalau yang mengerjakan hanya training, mereka tetap menerima jatah
     *   kecilnya saja; selisihnya tidak dibagikan ke siapa pun (jadi milik
     *   owner). Ini pilihan sadar: status training berarti porsi kecil,
     *   entah ia bekerja sendiri atau bersama senior.
     *
     * Kenapa bersama, bukan per orang: dengan nominal per orang, menambah anak
     * training menggerus bagian senior — pada 1 senior + 2 training, seniornya
     * justru dapat paling sedikit. Padahal maksud fitur ini sebaliknya.
     *
     * BATAS PENTING: jatah training dipotong agar tidak pernah melebihi porsi
     * yang akan mereka terima seandainya dibagi rata (jatah x jumlah training
     * / jumlah semua). Dengan batas itu, tiap anak training tidak mungkin
     * melebihi senior, dan senior tidak mungkin dapat Rp 0 pada cucian
     * bertarif kecil (mis. motor Rp 5.000 dengan jatah training Rp 5.000).
     *
     * @param  int         $jatah          upah cucian yang dibagikan
     * @param  int         $jatahTraining  jatah BERSAMA training utk kendaraan+layanan ini
     * @param  array<int>  $semua          id semua pekerja pada transaksi
     * @param  array<int>  $training       id yang berstatus training
     * @return array<int,int>              id pekerja => upahnya
     */
    public function bagiUpah(int $jatah, int $jatahTraining, array $semua, array $training): array
    {
        $semua = array_values(array_unique($semua));
        $training = array_values(array_intersect($training, $semua));
        $senior = array_values(array_diff($semua, $training));

        if (count($training) === 0) {
            $rata = intdiv($jatah, max(1, count($semua)));

            return array_fill_keys($semua, $rata);
        }

        $batas = intdiv($jatah * count($training), count($semua));
        $kolam = min($jatahTraining, $batas);

        $upahTraining = intdiv($kolam, count($training));

        // Memakai yang BENAR-BENAR dibagikan, bukan $kolam: sisa pembulatan
        // pembagian ke anak training tidak boleh ikut hilang dari hitungan
        // senior.
        $sisa = $jatah - ($upahTraining * count($training));
        $upahSenior = count($senior) > 0 ? intdiv(max(0, $sisa), count($senior)) : 0;

        $hasil = [];
        foreach ($semua as $id) {
            $hasil[$id] = in_array($id, $training, true) ? $upahTraining : $upahSenior;
        }

        return $hasil;
    }

    /* ==================== PENYESUAIAN UPAH (potongan & timpa) ====================
       Owner bisa memotong upah pekerja yang melanggar aturan, atau menimpa
       angka upah hasil hitungan. Keduanya disimpan di wage_adjustments.

       SEMUA jalur hitung upah di kelas ini harus melewati penyesuaian yang
       sama — kalau ada satu saja yang lupa, laporan harian dan laporan rentang
       akan menampilkan angka upah berbeda untuk hari yang sama, dan laba
       bersih ikut berbeda. Itu sebabnya aturannya cuma hidup di satu fungsi:
       terapkanPenyesuaian(). */

    /**
     * Hitung upah bersih dari upah hasil hitungan + penyesuaian harinya.
     *
     * 'timpa' menetapkan angka dasar (menggantikan hasil hitungan), lalu
     * 'potongan' menguranginya. Hasilnya tidak pernah minus: memotong lebih
     * besar dari upahnya berarti pekerja tidak menerima apa-apa hari itu,
     * bukan berutang ke owner — utang perlu keputusan lain, bukan efek
     * samping diam-diam dari salah ketik nominal.
     *
     * @param  array{timpa:?int,potongan:int}|null  $adj
     */
    public function terapkanPenyesuaian(int $upahHitungan, ?array $adj): int
    {
        if ($adj === null) {
            return $upahHitungan;
        }

        $dasar = $adj['timpa'] ?? $upahHitungan;

        return max(0, $dasar - $adj['potongan']);
    }

    /**
     * Penyesuaian untuk rentang tanggal, siap pakai:
     *   ['2026-08-20' => [3 => ['timpa' => 20000, 'potongan' => 5000]]]
     *
     * Untuk 'timpa' yang tercatat lebih dari sekali pada pekerja+tanggal yang
     * sama, yang TERBARU dipakai (diurutkan naik lalu ditimpa) — owner yang
     * mengoreksi angkanya dua kali tidak perlu menghapus catatan lamanya.
     *
     * @return array<string,array<int,array{timpa:?int,potongan:int}>>
     */
    public function penyesuaianRentang(string $from, string $to, ?int $bookId = null): array
    {
        $rows = WageAdjustment::query()
            ->whereBetween('date', [$from, $to])
            ->when($bookId !== null, fn ($q) => $q->where('book_id', $bookId))
            ->orderBy('id')
            ->get();

        $peta = [];
        foreach ($rows as $r) {
            $tgl = $r->date->toDateString();
            $peta[$tgl][$r->worker_id] ??= ['timpa' => null, 'potongan' => 0];

            if ($r->type === WageAdjustment::TIMPA) {
                $peta[$tgl][$r->worker_id]['timpa'] = $r->amount;
            } else {
                $peta[$tgl][$r->worker_id]['potongan'] += $r->amount;
            }
        }

        return $peta;
    }

    /** Penyesuaian satu tanggal: [worker_id => ['timpa'=>?int,'potongan'=>int]] */
    public function penyesuaianHari(string $date, ?int $bookId = null): array
    {
        return $this->penyesuaianRentang($date, $date, $bookId)[$date] ?? [];
    }

    /**
     * Rekap upah per pekerja untuk satu tanggal.
     * 'breakdown' berisi SATU BARIS PER TRANSAKSI (bukan agregat) supaya
     * layar Pekerja & Upah bisa menunjukkan cucian mana saja yang dikerjakan
     * — angkanya nyambung dengan riwayat di Rekap Hari Ini.
     *
     * 'wage' adalah upah SETELAH penyesuaian (itu yang dibayarkan dan yang
     * dipakai laporan); 'gross_wage', 'penalty', dan 'override' dibawa serta
     * supaya layar bisa menunjukkan asal angkanya, bukan cuma hasil akhirnya.
     */
    public function dailyRecap(string $date, ?int $bookId = null): Collection
    {
        $penyesuaian = $this->penyesuaianHari($date, $bookId);

        return Worker::query()
            ->with(['transactions' => function ($q) use ($date, $bookId) {
                $q->whereDate('date', $date)->whereNull('voided_at')
                    ->orderBy('transactions.created_at');
                // Kolom disebut lengkap ('transactions.book_id') karena kueri
                // ini berjalan lewat relasi many-to-many — tanpa awalan tabel,
                // MySQL bingung dengan kolom serupa di tabel pivot.
                if ($bookId !== null) {
                    $q->where('transactions.book_id', $bookId);
                }
            }])
            ->get()
            ->map(function (Worker $w) use ($penyesuaian) {
                $adj    = $penyesuaian[$w->id] ?? null;
                $kotor  = (int) $w->transactions->sum('pivot.wage_share');

                return [
                    'id'         => $w->id,
                    'name'       => $w->name,
                    'vehicles'   => $w->transactions->count(),
                    'gross_wage' => $kotor,
                    'penalty'    => (int) ($adj['potongan'] ?? 0),
                    'override'   => $adj['timpa'] ?? null,
                    'wage'       => $this->terapkanPenyesuaian($kotor, $adj),
                    'breakdown'  => $w->transactions->map($this->barisTransaksi(...))->values()->toArray(),
                ];
            })
            // Pekerja yang tidak mengerjakan apa pun tetap muncul kalau hari
            // itu punya penyesuaian — potongan yang dicatat untuk orang yang
            // sedang tidak kebagian cucian jangan sampai hilang dari layar.
            ->filter(fn ($row) => $row['vehicles'] > 0
                || $row['penalty'] > 0
                || $row['override'] !== null)
            ->values();
    }

    /** Satu transaksi apa adanya, untuk ditampilkan di detail upah pekerja. */
    private function barisTransaksi(Transaction $t): array
    {
        return [
            'transaction_id' => $t->id,
            'queue_no'       => $t->queue_no,
            'time'           => $t->created_at?->format('H:i'),
            'vehicle_name'   => $t->vehicle_name,
            'plate'          => $t->plate,
            'category'       => $t->category,
            'service'        => $t->service,
            'payment_method' => $t->payment_method,
            'total'          => (int) $t->total,
            'wage'           => (int) $t->pivot->wage_share,
        ];
    }

    /** Total upah semua pekerja untuk satu tanggal (atau satu buku saja). */
    public function dailyTotal(string $date, ?int $bookId = null): int
    {
        return (int) $this->dailyRecap($date, $bookId)->sum('wage');
    }

    /**
     * Total upah per tanggal untuk range tanggal: ['2026-07-17' => 10000, ...]
     *
     * Penyesuaian tidak bisa dikurangkan begitu saja dari total harian: 'timpa'
     * MENGGANTI upah satu orang, jadi selisihnya baru diketahui setelah upah
     * kotor orang itu diketahui. Karena itu upah kotornya diambil PER PEKERJA
     * per tanggal dulu, baru penyesuaiannya diterapkan satu per satu.
     */
    public function rangeByDate(string $startDate, string $endDate): array
    {
        $kotor = Transaction::valid()
            ->join('transaction_worker', 'transaction_worker.transaction_id', '=', 'transactions.id')
            ->whereBetween('transactions.date', [$startDate, $endDate])
            ->selectRaw('transactions.date as tgl, transaction_worker.worker_id as pk,
                         SUM(transaction_worker.wage_share) as wages')
            ->groupBy('transactions.date', 'transaction_worker.worker_id')
            ->get();

        $penyesuaian = $this->penyesuaianRentang($startDate, $endDate);

        $hasil = [];
        foreach ($kotor as $row) {
            $tgl = $row->tgl instanceof \Carbon\Carbon
                ? $row->tgl->toDateString()
                : (string) $row->tgl;

            $hasil[$tgl] ??= 0;
            $hasil[$tgl] += $this->terapkanPenyesuaian(
                (int) $row->wages,
                $penyesuaian[$tgl][$row->pk] ?? null,
            );
        }

        // Hari yang TIDAK punya cucian sama sekali tapi punya penyesuaian:
        // 'timpa' di hari seperti itu tetap memunculkan angka upah, jadi
        // harinya tidak boleh hilang dari rekap.
        foreach ($penyesuaian as $tgl => $perPekerja) {
            foreach ($perPekerja as $pk => $adj) {
                if (isset($hasil[$tgl]) && $this->punyaUpahKotor($kotor, $tgl, $pk)) {
                    continue;
                }
                $hasil[$tgl] = ($hasil[$tgl] ?? 0) + $this->terapkanPenyesuaian(0, $adj);
            }
        }

        return $hasil;
    }

    /** Apakah pekerja ini sudah ikut terhitung dari upah kotor tanggal itu. */
    private function punyaUpahKotor(Collection $kotor, string $tgl, int $workerId): bool
    {
        return $kotor->contains(function ($row) use ($tgl, $workerId) {
            $t = $row->tgl instanceof \Carbon\Carbon ? $row->tgl->toDateString() : (string) $row->tgl;

            return $t === $tgl && (int) $row->pk === $workerId;
        });
    }

    /** Rekap upah per pekerja untuk range tanggal dengan breakdown per tanggal. */
    public function byDateRange(string $startDate, string $endDate): Collection
    {
        $penyesuaian = $this->penyesuaianRentang($startDate, $endDate);

        return Worker::query()
            ->with(['transactions' => fn ($q) => $q
                ->whereBetween('date', [$startDate, $endDate])
                ->whereNull('voided_at')
                ->orderBy('date')
            ])
            ->get()
            ->map(function (Worker $w) use ($penyesuaian) {
                // Dihitung per TANGGAL, bukan sekali di akhir: 'timpa' berlaku
                // untuk satu hari tertentu, jadi menjumlahkan dulu lalu
                // menyesuaikan akan menimpa upah seluruh rentang.
                $harian = $w->transactions
                    ->groupBy(fn ($t) => $t->date->toDateString())
                    ->map(function ($dayTrx, $date) use ($w, $penyesuaian) {
                        $kotor = (int) $dayTrx->sum('pivot.wage_share');
                        $adj   = $penyesuaian[$date][$w->id] ?? null;

                        return [
                            'date'       => (string) $date,
                            'vehicles'   => $dayTrx->count(),
                            'gross_wage' => $kotor,
                            'penalty'    => (int) ($adj['potongan'] ?? 0),
                            'override'   => $adj['timpa'] ?? null,
                            'wage'       => $this->terapkanPenyesuaian($kotor, $adj),
                        ];
                    });

                // Hari yang pekerja ini tidak kebagian cucian tapi tetap punya
                // penyesuaian — supaya potongannya terlihat, bukan lenyap.
                foreach ($penyesuaian as $tgl => $perPekerja) {
                    if (isset($perPekerja[$w->id]) && ! $harian->has($tgl)) {
                        $adj = $perPekerja[$w->id];
                        $harian->put($tgl, [
                            'date'       => (string) $tgl,
                            'vehicles'   => 0,
                            'gross_wage' => 0,
                            'penalty'    => (int) $adj['potongan'],
                            'override'   => $adj['timpa'],
                            'wage'       => $this->terapkanPenyesuaian(0, $adj),
                        ]);
                    }
                }

                $harian = $harian->sortBy('date')->values();

                return [
                    'id'              => $w->id,
                    'name'            => $w->name,
                    'total_wage'      => (int) $harian->sum('wage'),
                    'total_gross'     => (int) $harian->sum('gross_wage'),
                    'total_penalty'   => (int) $harian->sum('penalty'),
                    'total_vehicles'  => $w->transactions->count(),
                    'daily_breakdown' => $harian->toArray(),
                ];
            })
            ->filter(fn ($row) => $row['total_vehicles'] > 0 || count($row['daily_breakdown']) > 0)
            ->sortBy('name')
            ->values();
    }
}

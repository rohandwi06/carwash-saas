<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\WageAdjustment;
use App\Models\WageRate;
use App\Models\Worker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Upah pekerja: tarif per jenis kendaraan + layanan, dibagi rata jika dikerjakan
 * bersama — kecuali karyawan training, yang menerima nominal tetap per cucian.
 * Aturan bisnis upah HANYA hidup di sini.
 */
class WageService
{
    /** Upah per mobil: nominal tetap per jenis kendaraan + layanan (cara lama). */
    public const MODE_NOMINAL = 'nominal';

    /** Bagi hasil: sekian persen dari harga cuci + add-on untuk pekerja. */
    public const MODE_PERSEN = 'persen';

    /**
     * Upah per bulan: gaji tetap tiap pekerja, dibebankan rata per hari
     * (SalaryService). Cucian tidak membawa upah sendiri selama mode ini berlaku.
     */
    public const MODE_BULANAN = 'bulanan';

    /** Bagian pekerja bila owner memilih persenan tapi belum pernah mengisi angkanya. */
    public const PERSEN_BAWAAN = 40;

    public function __construct(private SalaryService $gaji) {}

    /**
     * Cara hitung upah yang sedang berlaku. Cucian yang belum pernah menyentuh
     * pengaturan ini tetap 'nominal' — persis perilaku sebelum fitur ini ada.
     *
     * @return array{mode:string, percent:int}
     */
    public function sharing(): array
    {
        $persen = Setting::get('wage_percent');

        return [
            'mode'    => self::modeSah(Setting::get('wage_mode')),
            'percent' => $persen === null ? self::PERSEN_BAWAAN : max(0, min(100, (int) $persen)),
        ];
    }

    /**
     * Simpan cara hitung upah. $percent null = angka persen yang tersimpan tidak diubah.
     *
     * Persenan <-> upah per kendaraan hanya berlaku untuk transaksi berikutnya,
     * seperti dulu. Masuk ke atau keluar dari UPAH PER BULAN berlaku untuk
     * SELURUH HARI INI: sehari tidak boleh setengah digaji harian dan setengah
     * dibayar per cucian (keduanya akan terhitung). Jadi upah cucian hari ini
     * dihitung ulang menurut cara barunya; hari-hari sebelumnya tidak disentuh.
     */
    public function setSharing(string $mode, ?int $percent = null): array
    {
        $lama = $this->sharing()['mode'];
        $baru = self::modeSah($mode);

        return DB::transaction(function () use ($lama, $baru, $percent) {
            Setting::put('wage_mode', $baru);
            if ($percent !== null) {
                Setting::put('wage_percent', (string) max(0, min(100, $percent)));
            }

            if ($lama !== $baru && in_array(self::MODE_BULANAN, [$lama, $baru], true)) {
                $baru === self::MODE_BULANAN ? $this->gaji->mulai() : $this->gaji->berhenti();

                Transaction::valid()->whereDate('date', now()->toDateString())->with('workers')->get()
                    ->each(fn (Transaction $t) => $this->attachWorkers($t, $t->workers->pluck('id')->all()));
            }

            return $this->sharing();
        });
    }

    /** Mode yang tidak dikenal (termasuk belum pernah diatur) = upah per kendaraan. */
    private static function modeSah(?string $mode): string
    {
        return in_array($mode, [self::MODE_PERSEN, self::MODE_BULANAN], true) ? $mode : self::MODE_NOMINAL;
    }

    /**
     * Upah yang DIBAGIKAN ke pekerja untuk satu cucian — satu-satunya tempat
     * cara hitungnya diputuskan.
     *
     *   nominal  tarif per jenis kendaraan + layanan (rateFor)
     *   persen   persen dari $totalCuci = harga cuci + add-on. Tip dan F&B
     *            tidak pernah ikut: tip sudah milik pekerja, F&B bukan kerja
     *            mereka (keputusan owner 2026-10-09).
     *
     * Pecahan rupiah dari persenan dibuang (intdiv), tidak dibulatkan ke
     * atas. Jatah ini lalu dibagi habis ke pekerjanya di bagiUpah().
     */
    public function jatahFor(string $category, string $service, int $totalCuci, ?string $date = null): int
    {
        // Hari yang upahnya per bulan: cucian tidak membawa upah sendiri,
        // pekerjanya dibayar lewat gaji harian. Ditentukan oleh TANGGAL
        // cuciannya, bukan pengaturan saat ini — cucian susulan untuk tanggal
        // sebelum mode bulanan dimulai tetap dibayar per kendaraan.
        if ($this->gaji->berlaku($date ?? now()->toDateString())) {
            return 0;
        }

        $atur = $this->sharing();

        return $atur['mode'] === self::MODE_PERSEN
            ? intdiv(max(0, $totalCuci) * $atur['percent'], 100)
            : $this->rateFor($category, $service);
    }

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

        // total = harga cuci + add-on; sudah tersimpan sebelum fungsi ini
        // dipanggil, baik saat transaksi dibuat maupun dikoreksi.
        $jatah = $this->jatahFor($transaction->category, $transaction->service, (int) $transaction->total,
            $transaction->date?->toDateString());
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
     * - Angka training berlaku untuk SETIAP anak training pada cucian itu
     *   (keputusan owner 2026-09-29: "3k per orang, bukan 3k dibagi tiga").
     *   Upah cucian Rp 50.000, 5 tetap + 3 training, training Rp 3.000 ->
     *   tiap training Rp 3.000, tiap karyawan tetap (50.000 - 9.000) / 5 =
     *   Rp 8.200.
     * - Sisanya dibagi rata ke pekerja senior (karyawan tetap).
     * - Kalau yang mengerjakan hanya training, mereka tetap menerima jatah
     *   kecilnya saja; selisihnya tidak dibagikan ke siapa pun (jadi milik
     *   owner). Ini pilihan sadar: status training berarti porsi kecil,
     *   entah ia bekerja sendiri atau bersama senior.
     *
     * Dulu angkanya jatah BERSAMA (3k dibagi tiga) supaya menambah anak
     * training tidak menggerus bagian senior. Dengan per orang, makin banyak
     * anak training makin kecil bagian senior — batas di bawah yang menjaga
     * agar senior tidak pernah kalah dari anak training.
     *
     * BATAS PENTING: upah tiap anak training dipotong agar tidak pernah
     * melebihi bagiannya seandainya semua dibagi rata (jatah / jumlah semua).
     * Dengan batas itu, anak training tidak mungkin melebihi senior, dan
     * senior tidak mungkin dapat Rp 0 pada cucian bertarif kecil (mis. motor
     * Rp 5.000 dengan jatah training Rp 5.000).
     *
     * SISA PEMBAGIAN (keputusan owner 2026-10-12): rupiah yang tidak habis
     * dibagi rata diberikan ke pekerja PERTAMA dalam daftar (yang pertama
     * dipilih kasir), supaya jumlah upah semua pekerja tepat sama dengan
     * jatahnya. Rp 100.000 bertiga = 33.334 + 33.333 + 33.333, bukan
     * 3 x 33.333 dengan Rp 1 yang hilang ke laba.
     *
     * @param  int         $jatah          upah cucian yang dibagikan
     * @param  int         $jatahTraining  upah SETIAP anak training utk kendaraan+layanan ini
     * @param  array<int>  $semua          id semua pekerja pada transaksi
     * @param  array<int>  $training       id yang berstatus training
     * @return array<int,int>              id pekerja => upahnya
     */
    public function bagiUpah(int $jatah, int $jatahTraining, array $semua, array $training): array
    {
        $semua = array_values(array_unique($semua));
        $training = array_values(array_intersect($training, $semua));
        $senior = array_values(array_diff($semua, $training));

        if (count($semua) === 0) {
            return [];
        }

        if (count($training) === 0) {
            $rata = intdiv($jatah, count($semua));
            $hasil = array_fill_keys($semua, $rata);
            $hasil[$semua[0]] += $jatah - $rata * count($semua);

            return $hasil;
        }

        $upahTraining = min($jatahTraining, intdiv($jatah, count($semua)));

        $sisa = max(0, $jatah - ($upahTraining * count($training)));
        $upahSenior = count($senior) > 0 ? intdiv($sisa, count($senior)) : 0;

        $hasil = [];
        foreach ($semua as $id) {
            $hasil[$id] = in_array($id, $training, true) ? $upahTraining : $upahSenior;
        }
        // Hanya bagian senior yang dibagi habis. Kalau semuanya training,
        // selisihnya memang tidak dibagikan (lihat catatan di atas).
        if (count($senior) > 0) {
            $hasil[$senior[0]] += $sisa - $upahSenior * count($senior);
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
    public function terapkanPenyesuaian(int $upahHitungan, ?array $adj, bool $bulanan = false): int
    {
        if ($adj === null) {
            return $upahHitungan;
        }

        $dasar = $adj['timpa'] ?? $upahHitungan;

        // $bulanan: pekerjanya digaji per bulan dan angka ini cuma bagian SATU
        // HARI dari gajinya (keputusan owner 2026-10-11: potongan mengurangi
        // gaji bulan berjalan). Potongan kasbon Rp 500.000 jelas lebih besar
        // dari gaji sehari — kalau dibatasi nol di sini, potongannya hilang.
        // Jadi angka harinya boleh minus; jumlah sebulannya = gaji - potongan.
        return $bulanan ? $dasar - $adj['potongan'] : max(0, $dasar - $adj['potongan']);
    }

    /**
     * Potongan yang BENAR-BENAR memotong: yang tercatat bisa lebih besar dari
     * upahnya (upah tidak pernah minus), dan selisih itu tidak menahan uang
     * apa pun. Layar yang menulis "dipotong Rp X" harus memakai angka ini,
     * bukan jumlah yang tercatat — kalau tidak, owner membaca "dipotong
     * Rp 100.000" untuk pekerja yang upahnya memang Rp 0.
     */
    public function potonganTerpakai(int $upahHitungan, ?array $adj, bool $bulanan = false): int
    {
        if ($adj === null) {
            return 0;
        }

        return ($adj['timpa'] ?? $upahHitungan) - $this->terapkanPenyesuaian($upahHitungan, $adj, $bulanan);
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
        // Gaji bulanan tidak menempel ke buku kas mana pun (sama seperti
        // kompensasi), jadi hanya dihitung untuk sehari penuh. 'salary' =
        // bagian gaji hari itu; sudah termasuk di dalam 'gross_wage'.
        $gaji = $bookId === null ? $this->gaji->harian($date) : [];

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
            ->map(function (Worker $w) use ($penyesuaian, $gaji) {
                $adj     = $penyesuaian[$w->id] ?? null;
                $harian  = $gaji[$w->id] ?? 0;
                $kotor   = (int) $w->transactions->sum('pivot.wage_share') + $harian;
                $bulanan = $harian > 0;

                return [
                    'id'         => $w->id,
                    'name'       => $w->name,
                    'vehicles'   => $w->transactions->count(),
                    'salary'     => $harian,
                    'gross_wage' => $kotor,
                    'penalty'    => (int) ($adj['potongan'] ?? 0),
                    'penalty_applied' => $this->potonganTerpakai($kotor, $adj, $bulanan),
                    'override'   => $adj['timpa'] ?? null,
                    'wage'       => $this->terapkanPenyesuaian($kotor, $adj, $bulanan),
                    'breakdown'  => $w->transactions->map($this->barisTransaksi(...))->values()->toArray(),
                ];
            })
            // Pekerja yang tidak mengerjakan apa pun tetap muncul kalau hari
            // itu punya penyesuaian — potongan yang dicatat untuk orang yang
            // sedang tidak kebagian cucian jangan sampai hilang dari layar.
            // Pekerja bergaji bulanan muncul tiap hari, kerja atau tidak:
            // gajinya memang dibebankan tiap hari.
            ->filter(fn ($row) => $row['vehicles'] > 0
                || $row['salary'] > 0
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

        // [tanggal][pekerja] => upah kotor: bagian dari cucian + gaji harian.
        $perHari = [];
        foreach ($kotor as $row) {
            $tgl = $row->tgl instanceof \Carbon\Carbon
                ? $row->tgl->toDateString()
                : (string) $row->tgl;

            $perHari[$tgl][(int) $row->pk] = (int) $row->wages;
        }

        $gaji = $this->gaji->harianRentang($startDate, $endDate);
        foreach ($gaji as $tgl => $perPekerja) {
            foreach ($perPekerja as $pk => $harian) {
                $perHari[$tgl][$pk] = ($perHari[$tgl][$pk] ?? 0) + $harian;
            }
        }

        $penyesuaian = $this->penyesuaianRentang($startDate, $endDate);

        $hasil = [];
        foreach ($perHari as $tgl => $perPekerja) {
            foreach ($perPekerja as $pk => $upah) {
                $hasil[$tgl] = ($hasil[$tgl] ?? 0) + $this->terapkanPenyesuaian(
                    $upah,
                    $penyesuaian[$tgl][$pk] ?? null,
                    isset($gaji[$tgl][$pk]),
                );
            }
        }

        // Pekerja yang hari itu TIDAK punya upah kotor (tanpa cucian, tanpa
        // gaji harian) tapi punya penyesuaian: 'timpa' di hari seperti itu
        // tetap memunculkan angka upah, jadi harinya tidak boleh hilang dari
        // rekap.
        foreach ($penyesuaian as $tgl => $perPekerja) {
            foreach ($perPekerja as $pk => $adj) {
                if (isset($perHari[$tgl][$pk])) {
                    continue;
                }
                $hasil[$tgl] = ($hasil[$tgl] ?? 0) + $this->terapkanPenyesuaian(0, $adj);
            }
        }

        return $hasil;
    }

    /** Rekap upah per pekerja untuk range tanggal dengan breakdown per tanggal. */
    public function byDateRange(string $startDate, string $endDate): Collection
    {
        $penyesuaian = $this->penyesuaianRentang($startDate, $endDate);
        $gaji = $this->gaji->harianRentang($startDate, $endDate);

        return Worker::query()
            ->with(['transactions' => fn ($q) => $q
                ->whereBetween('date', [$startDate, $endDate])
                ->whereNull('voided_at')
                ->orderBy('date')
            ])
            ->get()
            ->map(function (Worker $w) use ($penyesuaian, $gaji) {
                // Dihitung per TANGGAL, bukan sekali di akhir: 'timpa' berlaku
                // untuk satu hari tertentu, jadi menjumlahkan dulu lalu
                // menyesuaikan akan menimpa upah seluruh rentang.
                $perTanggal = $w->transactions->groupBy(fn ($t) => $t->date->toDateString());

                // Tanggal yang punya sesuatu untuk pekerja ini: cucian, gaji
                // harian (upah per bulan), atau penyesuaian. Dua yang terakhir
                // ikut supaya hari tanpa cucian tidak lenyap dari kartunya.
                $punya = fn (array $peta) => array_keys(array_filter($peta, fn ($per) => isset($per[$w->id])));
                $tanggal = collect($perTanggal->keys())
                    ->merge($punya($gaji))->merge($punya($penyesuaian))
                    ->map(fn ($t) => (string) $t)->unique()->sort()->values();

                $harian = $tanggal->map(function (string $tgl) use ($w, $perTanggal, $gaji, $penyesuaian) {
                    $cucian   = $perTanggal->get($tgl, collect());
                    $gajiHari = $gaji[$tgl][$w->id] ?? 0;
                    $kotor    = (int) $cucian->sum('pivot.wage_share') + $gajiHari;
                    $adj      = $penyesuaian[$tgl][$w->id] ?? null;
                    $bulanan  = $gajiHari > 0;

                    return [
                        'date'       => $tgl,
                        'vehicles'   => $cucian->count(),
                        'salary'     => $gajiHari,
                        'gross_wage' => $kotor,
                        'penalty'    => (int) ($adj['potongan'] ?? 0),
                        'penalty_applied' => $this->potonganTerpakai($kotor, $adj, $bulanan),
                        'override'   => $adj['timpa'] ?? null,
                        'wage'       => $this->terapkanPenyesuaian($kotor, $adj, $bulanan),
                    ];
                });

                return [
                    'id'              => $w->id,
                    'name'            => $w->name,
                    'total_wage'      => (int) $harian->sum('wage'),
                    'total_gross'     => (int) $harian->sum('gross_wage'),
                    'total_salary'    => (int) $harian->sum('salary'),
                    'total_penalty'   => (int) $harian->sum('penalty'),
                    'total_penalty_applied' => (int) $harian->sum('penalty_applied'),
                    'total_vehicles'  => $w->transactions->count(),
                    'daily_breakdown' => $harian->toArray(),
                ];
            })
            ->filter(fn ($row) => $row['total_vehicles'] > 0 || count($row['daily_breakdown']) > 0)
            ->sortBy('name')
            ->values();
    }

    /**
     * Seperti byDateRange(), tapi hanya untuk tanggal-tanggal tertentu yang
     * boleh loncat-loncat (pilihan di kalender Upah & potongan). Rentangnya
     * dihitung sekali dari yang paling awal sampai paling akhir, lalu hari di
     * antaranya yang tidak dipilih dibuang dan totalnya dihitung ulang —
     * jadi aturan hitung per tanggal tetap satu, milik byDateRange().
     */
    public function byDates(array $dates): Collection
    {
        $tanggal = collect($dates)->unique()->sort()->values();
        $dipilih = $tanggal->flip();

        return $this->byDateRange($tanggal->first(), $tanggal->last())
            ->map(function (array $row) use ($dipilih) {
                $harian = collect($row['daily_breakdown'])
                    ->filter(fn ($d) => $dipilih->has($d['date']))
                    ->values();

                return array_merge($row, [
                    'total_wage'      => (int) $harian->sum('wage'),
                    'total_gross'     => (int) $harian->sum('gross_wage'),
                    'total_salary'    => (int) $harian->sum('salary'),
                    'total_penalty'   => (int) $harian->sum('penalty'),
                    'total_penalty_applied' => (int) $harian->sum('penalty_applied'),
                    'total_vehicles'  => (int) $harian->sum('vehicles'),
                    'daily_breakdown' => $harian->toArray(),
                ]);
            })
            ->filter(fn ($row) => count($row['daily_breakdown']) > 0)
            ->values();
    }
}

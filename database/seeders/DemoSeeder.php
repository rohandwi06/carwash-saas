<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\CashBook;
use App\Models\Consignor;
use App\Models\Expense;
use App\Models\FnbSale;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WageAdjustment;
use App\Models\WageRate;
use App\Models\WashPrice;
use App\Models\Worker;
use App\Services\CashBookService;
use App\Services\ConsignmentService;
use App\Services\FnbService;
use App\Services\TransactionService;
use App\Services\WageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh 30 hari penuh (sampai kemarin) + hari ini sampai jam sekarang,
 * untuk demo & pengembangan:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan db:seed --class=DemoSeeder
 *
 * Semua transaksi dibuat lewat service yang sama dengan layar kasir (harga,
 * upah, buku kas dihitung server), dengan jam "dimundurkan" per kejadian
 * lewat Carbon::setTestNow(). Karena itu Rekap, Pembukuan, Upah, Buku Kas,
 * dan Dashboard saling cocok — persis seperti data yang dicatat sungguhan.
 *
 * Kejadian yang jatuh SESUDAH jam sekarang dilewati, jadi tidak ada catatan
 * dari masa depan: dijalankan pagi buta, hari ini masih kosong dan setoran
 * sore kemarin masih menunggu persetujuan — persis keadaan sungguhan.
 *
 * Angka acaknya memakai seed tetap: hasilnya sama setiap kali dijalankan
 * (tanggalnya ikut hari ini). Menolak jalan di produksi dan di database yang
 * sudah berisi transaksi, supaya tidak pernah tercampur dengan data asli.
 *
 * Akun kasir yang dibuat: dina / kasir123 (pagi), rizal / kasir123 (sore).
 */
class DemoSeeder extends Seeder
{
    private const HARI = 30;

    private const KASIR = [
        ['name' => 'Dina',  'username' => 'dina'],
        ['name' => 'Rizal', 'username' => 'rizal'],
    ];

    private const PEKERJA = [
        ['name' => 'Andi',   'phone' => '081234560001', 'birth_date' => '1995-03-12'],
        ['name' => 'Bayu',   'phone' => '081234560002', 'birth_date' => '1998-07-21'],
        ['name' => 'Candra', 'phone' => '081234560003', 'birth_date' => '2000-01-05'],
        ['name' => 'Dedi',   'phone' => null, 'birth_date' => null],
        ['name' => 'Eko',    'phone' => '081234560005', 'birth_date' => '1993-11-30'],
        ['name' => 'Fajar',  'phone' => null, 'birth_date' => null],
        ['name' => 'Gilang', 'phone' => null, 'birth_date' => null, 'is_trainee' => true],
    ];

    /** Porsi kendaraan per jenis — motor & mobil kecil paling sering. */
    private const PORSI_JENIS = ['motor' => 35, 'kecil' => 30, 'sedang' => 25, 'besar' => 10];

    private const PENGELUARAN = [
        ['Sabun & shampo mobil', 45000, 90000],
        ['Makan siang pekerja', 60000, 110000],
        ['Bensin genset', 30000, 60000],
        ['Lap microfiber', 25000, 50000],
        ['Pewangi & semir stok', 40000, 80000],
        ['Air galon', 18000, 24000],
    ];

    private const ALASAN_BATAL = [
        'Salah pilih jenis kendaraan',
        'Pelanggan batal, belum dikerjakan',
        'Tercatat dua kali',
        'Salah input cara bayar',
    ];

    private array $kategori = [];

    private array $layananPerJenis = [];

    private array $kendaraanPerJenis = [];

    private ?int $trainee = null;

    /** Jam nyata saat seeder dijalankan; kejadian sesudahnya dilewati. */
    private CarbonImmutable $sekarang;

    public function __construct(
        private TransactionService $transaksi,
        private FnbService $fnb,
        private CashBookService $buku,
        private ConsignmentService $titipan,
        private WageService $upah,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DemoSeeder tidak boleh dijalankan di produksi.');

            return;
        }
        if (Transaction::exists()) {
            $this->command->error('Database ini sudah berisi transaksi. Jalankan di database kosong: '
                .'php artisan migrate:fresh --seed, lalu seeder ini.');

            return;
        }

        mt_srand(20260922);
        $sekarang = $this->sekarang = CarbonImmutable::now();
        $awal     = $sekarang->startOfDay()->subDays(self::HARI);

        $this->siapkanKatalog();
        [$pekerja, $this->trainee] = $this->siapkanOrang();
        [$penitip, $kue]     = $this->siapkanTitipan($awal);
        $menu = Product::where('is_active', true)->whereNull('consignor_id')->get();

        $hitung = ['cuci' => 0, 'fnb' => 0, 'batal' => 0];
        $bukuSore = null;   // buku shift sore kemarin, disetujui owner pagi ini

        try {
            // self::HARI hari penuh + hari ini (indeks terakhir).
            for ($h = 0; $h <= self::HARI; $h++) {
                $hari    = $awal->addDays($h);
                $hariIni = $h === self::HARI;
                $kemarin = $h === self::HARI - 1;

                // Pagi: owner menyetujui setoran shift sore kemarin, kasir
                // pagi mengisi saldo awal buku hari ini.
                if ($bukuSore) {
                    // Dilewati bila jam 07:10 belum tiba: setoran tetap menunggu.
                    $this->pada($hari->setTime(7, 10), fn () => $this->buku->approveDeposit($bukuSore, 'Owner'));
                    $bukuSore = null;
                }
                $this->pada($hari->setTime(7, 25), function () {
                    $b = $this->buku->current(by: 'Dina');
                    $this->buku->setOpeningBalance($b, 100000 * mt_rand(1, 3));
                });

                // Pekerja yang masuk hari ini: 4-6 orang, trainee kadang ikut.
                $hadir = collect($pekerja)->shuffle()->take(mt_rand(4, 6))->all();
                if (mt_rand(1, 100) <= 60) {
                    $hadir[] = $this->trainee;
                }

                // Jumlah cucian: ~16/hari (patokan OTIN), akhir pekan lebih
                // ramai, sesekali hari sepi (hujan).
                $faktor = $hari->isWeekend() ? 1.5 : 1.0;
                if (mt_rand(1, 100) <= 15) {
                    $faktor *= 0.55;
                }
                $jumlah = max(4, (int) round(16 * $faktor * mt_rand(80, 120) / 100));

                $kejadian = [];
                foreach ($this->jamAcak($hari, $jumlah) as $jam) {
                    $kejadian[] = [$jam, 'cuci'];
                }
                foreach ($this->jamAcak($hari, mt_rand(1, 4)) as $jam) {
                    $kejadian[] = [$jam, 'fnb'];
                }
                foreach (range(1, mt_rand(1, 2)) as $_) {
                    $kejadian[] = [$hari->setTime(mt_rand(9, 18), mt_rand(0, 59)), 'keluar'];
                }
                // Serah terima shift: kasir pagi menyetor bukunya jam 14:00.
                $kejadian[] = [$hari->setTime(14, 0), 'setor-pagi'];
                usort($kejadian, fn ($a, $b) => $a[0] <=> $b[0]);

                $cucianHariIni = [];
                foreach ($kejadian as [$jam, $jenis]) {
                    $kasir = $jam->hour < 14 ? 'Dina' : 'Rizal';

                    $this->pada($jam, function () use ($jenis, $kasir, $hadir, $menu, $kue, &$cucianHariIni, &$hitung) {
                        match ($jenis) {
                            'cuci' => (function () use ($kasir, $hadir, $menu, $kue, &$cucianHariIni, &$hitung) {
                                $cucianHariIni[] = $this->satuCucian($kasir, $hadir, $menu, $kue);
                                $hitung['cuci']++;
                            })(),
                            'fnb' => $this->satuJajan($kasir, $menu, $kue) && $hitung['fnb']++,
                            'keluar' => $this->satuPengeluaran($kasir),
                            'setor-pagi' => $this->buku->requestDeposit($this->buku->current(), 'Dina'),
                        };
                    });
                }

                // Setoran siang langsung diterima owner, kecuali sekali ditolak
                // (uang kurang) supaya status "ditolak" punya contoh.
                $siang = CashBook::whereDate('date', $hari)->where('status', 'pending')->orderBy('number')->first();
                if ($siang) {
                    $this->pada($hari->setTime(15, 5), fn () => $h === 11
                        ? $this->buku->rejectDeposit($siang, 'Uang kurang Rp 20.000 dari catatan', 'Owner')
                        : $this->buku->approveDeposit($siang, 'Owner'));
                }

                $hitung['batal'] += $this->kejadianPengawasan($h, $hari, $kemarin, $cucianHariIni, $pekerja);

                // Tutup hari: kasir sore menyetor; owner menyetujui besok pagi.
                // Hari ini dibiarkan terbuka — tokonya masih buka.
                if (! $hariIni) {
                    $bukuSore = $this->pada($hari->setTime(21, 5),
                        fn () => $this->buku->requestDeposit($this->buku->current(), 'Rizal'));
                }

                // Penitip kue: stok diantar tiap Senin, utang dibayar tiap Sabtu.
                if ($hari->isMonday() && $h > 0) {
                    $this->pada($hari->setTime(7, 40), fn () => $this->titipan->terima($kue->fresh(), 25, 'Antar mingguan', 'Owner'));
                }
                if ($hari->isSaturday() && ($utang = $this->titipan->utang($penitip->fresh())) > 0) {
                    $this->pada($hari->setTime(20, 30), fn () => $this->titipan->bayar($penitip->fresh(), $utang, 'Bayar mingguan', 'Owner'));
                }
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command->info(sprintf(
            'Data demo %d hari + hari ini: %d cucian, %d jajan, %d pengajuan batal. Login kasir: dina / kasir123, rizal / kasir123.',
            self::HARI, $hitung['cuci'], $hitung['fnb'], $hitung['batal'],
        ));
    }

    /* ------------------------------------------------------------------ */

    private function siapkanKatalog(): void
    {
        $this->kategori = array_intersect_key(self::PORSI_JENIS,
            array_flip(WashPrice::distinct()->pluck('category_slug')->all()));

        foreach (array_keys($this->kategori) as $slug) {
            $this->layananPerJenis[$slug]   = WashPrice::where('category_slug', $slug)->pluck('service_slug')->all();
            $this->kendaraanPerJenis[$slug] = Vehicle::where('category', $slug)->pluck('name')->all() ?: ['Kendaraan'];
        }

        // Upah ~40% harga layanan — sebanding dengan data nyata OTIN (upah
        // ±45% omzet cuci). Tarif bawaan config (Rp 5-12rb) jauh di bawah itu
        // dan membuat laba demo tampak 80% omzet, angka yang tidak dipercaya
        // pemilik cucian mana pun. Jatah trainee diambil dari upah itu.
        foreach (WashPrice::all() as $p) {
            WageRate::updateOrCreate(
                ['category' => $p->category_slug, 'service' => $p->service_slug],
                ['amount' => (int) round($p->price * 0.4 / 500) * 500],
            );
            $this->upah->setTraineeWageFor($p->category_slug, $p->service_slug,
                $p->category_slug === 'motor' ? 2000 : 4000);
        }

        // Stok menu cukup untuk sebulan.
        Product::whereNull('consignor_id')->update(['stock' => 400]);
    }

    /** @return array{0: array<int>, 1: int} id pekerja senior & id trainee */
    private function siapkanOrang(): array
    {
        foreach (self::KASIR as $k) {
            User::firstOrCreate(['username' => $k['username']], [
                'name' => $k['name'], 'password' => Hash::make('kasir123'), 'role' => 'kasir',
            ]);
        }

        $senior = [];
        $trainee = null;
        foreach (self::PEKERJA as $p) {
            $w = Worker::create([
                'name' => $p['name'], 'is_present' => true, 'is_trainee' => $p['is_trainee'] ?? false,
                'phone' => $p['phone'], 'birth_date' => $p['birth_date'],
            ]);
            ($p['is_trainee'] ?? false) ? $trainee = $w->id : $senior[] = $w->id;
        }

        return [$senior, $trainee];
    }

    /** @return array{0: Consignor, 1: Product} */
    private function siapkanTitipan(CarbonImmutable $awal): array
    {
        $penitip = Consignor::create([
            'name' => 'Bu Sri', 'phone' => '081298760000', 'note' => 'Kue basah, antar tiap Senin',
            'share_mode' => 'setor', 'is_active' => true,
        ]);
        $kue = Product::create([
            'name' => 'Kue Basah', 'type' => 'makanan', 'price' => 3000, 'stock' => 0,
            'is_active' => true, 'consignor_id' => $penitip->id, 'payout_price' => 2000,
        ]);
        $this->pada($awal->setTime(7, 40), fn () => $this->titipan->terima($kue, 25, 'Antar pertama', 'Owner'));

        return [$penitip, $kue->fresh()];
    }

    private function satuCucian(string $kasir, array $hadir, $menu, Product $kue): Transaction
    {
        $jenis   = $this->acakBerbobot($this->kategori);
        $layanan = $this->layananPerJenis[$jenis];
        $svc     = in_array('reguler', $layanan, true) && mt_rand(1, 100) <= 70
            ? 'reguler'
            : $layanan[array_rand($layanan)];

        $addon = [];
        if ($jenis !== 'motor' && mt_rand(1, 100) <= 18) {
            $semua = Addon::where('is_active', true)->orderBy('id')->pluck('id')->all();
            shuffle($semua);
            $addon = array_slice($semua, 0, mt_rand(1, 2));
        }

        $jajan = [];
        if (mt_rand(1, 100) <= 12) {
            $jajan = $this->pilihMenu($menu, $kue);
        }

        return $this->transaksi->create([
            'vehicle_name'   => $this->kendaraanPerJenis[$jenis][array_rand($this->kendaraanPerJenis[$jenis])],
            'category'       => $jenis,
            'service'        => $svc,
            'payment_method' => mt_rand(1, 100) <= 65 ? 'cash' : 'tf',
            'plate'          => $this->platAcak(),
            'tip'            => mt_rand(1, 100) <= 20 ? [2000, 5000, 5000, 10000][mt_rand(0, 3)] : 0,
            'addon_ids'      => $addon,
            'worker_ids'     => $this->regu($hadir, $jenis),
            'fnb_items'      => $jajan,
            'created_by'     => $kasir,
        ]);
    }

    /** Motor dikerjakan satu senior; mobil 2-3 senior, trainee ikut bila masuk. */
    private function regu(array $hadir, string $jenis): array
    {
        $senior = array_values(array_diff($hadir, [$this->trainee]));
        shuffle($senior);
        $regu = array_slice($senior, 0, $jenis === 'motor' ? 1 : mt_rand(2, 3));
        if ($jenis !== 'motor' && in_array($this->trainee, $hadir, true) && mt_rand(1, 100) <= 50) {
            $regu[] = $this->trainee;
        }

        return $regu;
    }

    private function satuJajan(string $kasir, $menu, Product $kue): bool
    {
        $items = $this->pilihMenu($menu, $kue);
        if ($items === []) {
            return false;
        }
        $this->fnb->create([
            'payment_method' => mt_rand(1, 100) <= 80 ? 'cash' : 'tf',
            'items'          => $items,
            'tip'            => 0,
            'created_by'     => $kasir,
        ]);

        return true;
    }

    private function pilihMenu($menu, Product $kue): array
    {
        $items = collect($menu)->shuffle()->take(mt_rand(1, 2))
            ->map(fn ($p) => ['product_id' => $p->id, 'qty' => mt_rand(1, 2)])->values()->all();

        $stokKue = (int) $kue->fresh()->stock;
        if ($stokKue > 0 && mt_rand(1, 100) <= 45) {
            $items[] = ['product_id' => $kue->id, 'qty' => min($stokKue, mt_rand(1, 3))];
        }

        return $items;
    }

    private function satuPengeluaran(string $kasir): void
    {
        [$ket, $min, $maks] = self::PENGELUARAN[array_rand(self::PENGELUARAN)];
        Expense::create([
            'description' => $ket,
            'amount'      => (int) round(mt_rand($min, $maks) / 1000) * 1000,
            'date'        => now()->toDateString(),
            'book_id'     => $this->buku->current(by: $kasir)->id,
            'created_by'  => $kasir,
        ]);
    }

    /**
     * Kejadian yang justru jadi alasan owner memakai aplikasi ini:
     * pengajuan batal dari kasir (disetujui / ditolak / masih menunggu),
     * koreksi oleh owner, dan potongan upah. Mengembalikan jumlah pengajuan.
     */
    private function kejadianPengawasan(int $h, CarbonImmutable $hari, bool $kemarin, array $cucian, array $pekerja): int
    {
        if (count($cucian) < 3) {
            return 0;
        }
        $pengajuan = 0;

        // Kasir mengajukan batal kira-kira tiap 4 hari. Pengajuan kemarin
        // dibiarkan menunggu, supaya antrean persetujuan owner tidak kosong.
        if ($h % 4 === 2 || $kemarin) {
            $t   = $cucian[array_rand($cucian)];
            $jam = CarbonImmutable::parse($t->created_at)->addMinutes(mt_rand(5, 40));
            $ok  = $this->pada($jam, fn () => $this->transaksi->requestVoid(
                $t, self::ALASAN_BATAL[array_rand(self::ALASAN_BATAL)], $t->created_by));
            if ($ok) {
                $pengajuan++;
                if (! $kemarin) {
                    $this->pada($jam->addHours(2), fn () => $h % 12 === 10
                        ? $this->transaksi->rejectVoid($t->fresh(), 'Mobil sudah selesai dicuci, tidak bisa dibatalkan', 'Owner')
                        : $this->transaksi->approveVoid($t->fresh(), 'Owner'));
                }
            }
        }

        // Owner mengoreksi jenis kendaraan yang salah pilih (tiap ~6 hari).
        if ($h % 6 === 3) {
            $t = collect($cucian)->first(function ($x) {
                $x = $x->fresh();

                return $x->category === 'kecil' && $x->void_requested_at === null && $x->voided_at === null;
            });
            if ($t && in_array($t->service, $this->layananPerJenis['sedang'] ?? [], true)) {
                $this->pada(CarbonImmutable::parse($t->created_at)->addHour(),
                    fn () => $this->transaksi->update($t->fresh(), [
                        'category' => 'sedang', 'edit_reason' => 'Kendaraannya MPV, bukan mobil kecil',
                    ], 'Owner'));
            }
        }

        // Potongan upah karena terlambat (tiap ~9 hari).
        if ($h % 9 === 4) {
            $w = Worker::find($pekerja[array_rand($pekerja)]);
            $this->pada($hari->setTime(17, 30), fn () => WageAdjustment::create([
                'worker_id' => $w->id, 'worker_name' => $w->name, 'type' => WageAdjustment::POTONGAN,
                'amount' => 10000, 'reason' => 'Terlambat datang', 'date' => now()->toDateString(),
                'book_id' => $this->buku->current(by: 'Owner')->id, 'created_by' => 'Owner',
            ]));
        }

        return $pengajuan;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Jalankan $fn seolah-olah jam menunjukkan $jam. Jam yang belum tiba
     * dilewati (mengembalikan null) — tidak ada catatan dari masa depan.
     */
    private function pada(CarbonImmutable $jam, callable $fn): mixed
    {
        if ($jam->greaterThan($this->sekarang)) {
            return null;
        }
        Carbon::setTestNow($jam);

        return $fn();
    }

    /**
     * Jam kedatangan pelanggan: ramai pagi (08-11) & sore (15-17).
     *
     * @return list<CarbonImmutable>
     */
    private function jamAcak(CarbonImmutable $hari, int $n): array
    {
        $bobotJam = [7 => 3, 8 => 8, 9 => 10, 10 => 10, 11 => 8, 12 => 5, 13 => 6,
            14 => 7, 15 => 9, 16 => 10, 17 => 8, 18 => 5, 19 => 3, 20 => 2];
        $jam = [];
        for ($i = 0; $i < $n; $i++) {
            $jam[] = $hari->setTime($this->acakBerbobot($bobotJam), mt_rand(0, 59), mt_rand(0, 59));
        }
        sort($jam);

        return $jam;
    }

    private function acakBerbobot(array $bobot): int|string
    {
        $r = mt_rand(1, array_sum($bobot));
        foreach ($bobot as $kunci => $b) {
            if (($r -= $b) <= 0) {
                return $kunci;
            }
        }

        return array_key_first($bobot);
    }

    private function platAcak(): ?string
    {
        if (mt_rand(1, 100) <= 25) {
            return null;   // kasir tidak selalu mencatat plat
        }
        $wilayah = ['B', 'L', 'N', 'AG', 'W', 'S', 'AE'][mt_rand(0, 6)];
        $huruf = chr(mt_rand(65, 90)).(mt_rand(0, 1) ? chr(mt_rand(65, 90)) : '');

        return $wilayah.' '.mt_rand(1000, 9999).' '.$huruf;
    }
}

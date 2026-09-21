<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan data lama yang tergolong per shift menjadi buku kas.
 *
 * Untuk tiap TANGGAL YANG SUDAH LEWAT: tiap kelompok shift_name (termasuk
 * yang kosong — di luar jam shift) diurutkan menurut waktu transaksi
 * PALING AWAL dan diberi nomor buku berurutan. Statusnya 'deposited' —
 * hari-hari itu sudah berlalu dan pendapatannya sudah nyata diterima; tidak
 * masuk akal memaksa owner menyetujui setoran mundur untuk 11 hari sekaligus.
 * opened_by/requested_by/approved_by DIBIARKAN KOSONG: tidak ada catatan
 * sungguhan siapa yang membuka/menyetor/menerima tiap kelompok itu, dan
 * mengarang nama hanya akan menyesatkan.
 *
 * Untuk HARI INI (tanggal migrasi ini dijalankan): SEMUA kelompoknya digabung
 * jadi SATU buku berstatus 'open' — bukan dipisah per shift seperti hari
 * lampau. Alasannya: hari ini belum berakhir, dan sesi kerja hari ini pada
 * kenyataannya belum pernah benar-benar "ditutup & disetor" lewat alur baru
 * ini. Memberinya status 'deposited' akan berbohong seolah sudah disetor
 * padahal belum; memisahnya jadi beberapa buku 'deposited' + satu 'open'
 * akan mengesankan ada penyetoran yang sebenarnya tidak pernah terjadi.
 *
 * amount (uang cash yang harus disetor) dihitung SETELAH book_id terpasang
 * di semua baris, supaya hitungannya memakai data yang sudah lengkap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hariIni = now()->toDateString();

        $tanggal = collect(['transactions', 'fnb_sales', 'expenses'])
            ->flatMap(fn ($t) => DB::table($t)->whereNull('book_id')->distinct()->pluck('date'))
            ->map(fn ($d) => (string) $d)
            ->unique()
            ->sort()
            ->values();

        foreach ($tanggal as $tgl) {
            if ($tgl === $hariIni) {
                $this->satuBukuTerbuka($tgl);
            } else {
                $this->pisahPerKelompok($tgl);
            }
        }

        $this->hitungSetoran();
    }

    /** Hari yang sudah lewat: satu buku 'deposited' per kelompok shift_name. */
    private function pisahPerKelompok(string $tgl): void
    {
        $kelompok = $this->kelompokPada($tgl);

        foreach ($kelompok->values() as $i => $k) {
            $nomor = $i + 1;
            $id = DB::table('cash_books')->insertGetId([
                'date'         => $tgl,
                'number'       => $nomor,
                'status'       => 'deposited',
                'opened_at'    => $k['mulai'],
                'requested_at' => $k['akhir'],
                'approved_at'  => $k['akhir'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $this->tandaiBaris($tgl, $k['shift_name'], $id);
        }
    }

    /** Hari ini: semua kelompok digabung jadi satu buku yang masih terbuka. */
    private function satuBukuTerbuka(string $tgl): void
    {
        $kelompok = $this->kelompokPada($tgl);
        if ($kelompok->isEmpty()) {
            return;
        }

        $mulaiPalingAwal = $kelompok->min('mulai');

        $id = DB::table('cash_books')->insertGetId([
            'date'       => $tgl,
            'number'     => 1,
            'status'     => 'open',
            'opened_at'  => $mulaiPalingAwal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($kelompok as $k) {
            $this->tandaiBaris($tgl, $k['shift_name'], $id);
        }
    }

    /**
     * Kelompok (shift_name -> [mulai, akhir]) pada satu tanggal, gabungan dari
     * ketiga tabel, diurutkan dari yang paling awal terjadi.
     *
     * @return \Illuminate\Support\Collection<string,array{shift_name:?string,mulai:string,akhir:string}>
     */
    private function kelompokPada(string $tgl): \Illuminate\Support\Collection
    {
        $kelompok = collect();

        foreach (['transactions', 'fnb_sales', 'expenses'] as $tabel) {
            DB::table($tabel)
                ->whereNull('book_id')
                ->where('date', $tgl)
                ->select('shift_name', 'created_at')
                ->get()
                ->each(function ($row) use (&$kelompok) {
                    $kunci = $row->shift_name ?? '';
                    $ada = $kelompok->get($kunci);
                    $kelompok[$kunci] = [
                        'shift_name' => $row->shift_name,
                        'mulai' => $ada ? min($ada['mulai'], $row->created_at) : $row->created_at,
                        'akhir' => $ada ? max($ada['akhir'], $row->created_at) : $row->created_at,
                    ];
                });
        }

        return $kelompok->sortBy('mulai');
    }

    private function tandaiBaris(string $tgl, ?string $shiftName, int $bookId): void
    {
        foreach (['transactions', 'fnb_sales', 'expenses'] as $tabel) {
            DB::table($tabel)
                ->whereNull('book_id')
                ->where('date', $tgl)
                ->where(function ($q) use ($shiftName) {
                    $shiftName === null ? $q->whereNull('shift_name') : $q->where('shift_name', $shiftName);
                })
                ->update(['book_id' => $bookId]);
        }
    }

    /**
     * Uang cash yang harus disetor per buku: omzet cash (cuci + F&B) dikurangi
     * pengeluaran di buku itu. TF tidak dihitung — sudah otomatis masuk
     * rekening, tidak ada wujud fisik untuk diserahkan.
     */
    private function hitungSetoran(): void
    {
        $cuci = DB::table('transactions')
            ->whereNotNull('book_id')->where('payment_method', 'cash')
            ->selectRaw('book_id, SUM(total) as jumlah')->groupBy('book_id')->pluck('jumlah', 'book_id');

        $fnb = DB::table('fnb_sales')
            ->whereNotNull('book_id')->where('payment_method', 'cash')
            ->selectRaw('book_id, SUM(total) as jumlah')->groupBy('book_id')->pluck('jumlah', 'book_id');

        $keluar = DB::table('expenses')
            ->whereNotNull('book_id')
            ->selectRaw('book_id, SUM(amount) as jumlah')->groupBy('book_id')->pluck('jumlah', 'book_id');

        // Hanya buku yang SUDAH diajukan/disetujui yang dibekukan angkanya;
        // buku yang masih 'open' dibiarkan null — nilainya dihitung hidup
        // oleh aplikasi selama buku itu masih berjalan.
        foreach (DB::table('cash_books')->whereIn('status', ['deposited', 'pending'])->pluck('id') as $id) {
            $jumlah = (int) ($cuci[$id] ?? 0) + (int) ($fnb[$id] ?? 0) - (int) ($keluar[$id] ?? 0);
            DB::table('cash_books')->where('id', $id)->update(['amount' => $jumlah]);
        }
    }

    public function down(): void
    {
        // Sengaja kosong: seperti migrasi backfill shift sebelumnya, hasil
        // pemindahan ini tidak bisa dibalik dengan tepat tanpa risiko
        // menghapus buku yang sudah dipakai (mis. sudah diajukan setoran
        // sungguhan setelah migrasi ini jalan).
    }
};

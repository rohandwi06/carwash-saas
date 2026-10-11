<?php

namespace App\Services;

use App\Models\MonthlyWagePeriod;
use App\Models\Worker;
use App\Models\WorkerSalary;
use Carbon\CarbonImmutable;

/**
 * Upah per bulan: kapan berlaku, berapa gaji tiap pekerja, dan berapa yang
 * dibebankan ke tiap hari.
 *
 * Aturannya (keputusan owner 2026-10-11): gaji tetap berapa pun hari masuknya,
 * dibebankan RATA per hari kalender — gaji / jumlah hari bulan itu — supaya
 * laba bersih harian tetap masuk akal. Sisa pembagiannya ditaruh di hari
 * terakhir bulan, jadi jumlah sebulan selalu tepat sama dengan gajinya.
 *
 * Tidak ada cron dan tidak ada baris per hari: semuanya dihitung saat dibaca,
 * dari tanggal berlaku mode (monthly_wage_periods) dan tanggal berlaku gaji
 * (worker_salaries). Karena itu mengubah gaji atau berganti cara upah tidak
 * pernah mengubah hari-hari yang sudah lewat.
 */
class SalaryService
{
    /** Apakah cara upah "per bulan" berlaku pada tanggal itu. */
    public function berlaku(string $date): bool
    {
        return MonthlyWagePeriod::whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->exists();
    }

    /** Mode bulanan dinyalakan: berlaku mulai HARI INI (seluruh hari ini). */
    public function mulai(): void
    {
        if (! $this->berlaku(now()->toDateString())) {
            MonthlyWagePeriod::create(['start_date' => now()->toDateString()]);
        }
    }

    /**
     * Mode bulanan dimatikan: hari ini sudah BUKAN bulanan, kemarin ke belakang
     * tetap. Periode yang baru dimulai hari ini dihapus — ia tidak pernah
     * berlaku sehari penuh pun.
     */
    public function berhenti(): void
    {
        $hariIni = now()->toDateString();

        foreach (MonthlyWagePeriod::whereNull('end_date')->orWhereDate('end_date', '>=', $hariIni)->get() as $p) {
            $p->start_date->toDateString() >= $hariIni
                ? $p->delete()
                : $p->update(['end_date' => now()->subDay()->toDateString()]);
        }
    }

    /**
     * Gaji bulanan yang berlaku sekarang untuk semua pekerja, urut nama —
     * bentuk yang dipakai layar Pengaturan. Yang belum pernah diisi = 0.
     *
     * @return array<int,array{worker_id:int,name:string,amount:int}>
     */
    public function daftar(): array
    {
        $gaji = $this->gajiPada(now()->toDateString());

        return Worker::orderBy('name')->get(['id', 'name'])
            ->map(fn (Worker $w) => ['worker_id' => $w->id, 'name' => $w->name, 'amount' => $gaji[$w->id] ?? 0])
            ->all();
    }

    /**
     * Tetapkan gaji bulanan seorang pekerja MULAI HARI INI. Hari-hari
     * sebelumnya tetap memakai angka lamanya. Mengisi dua kali di hari yang
     * sama hanya memperbarui baris hari itu.
     */
    public function tetapkan(int $workerId, int $amount, ?string $oleh): void
    {
        $hariIni = now()->toDateString();

        // Angkanya tidak berubah: jangan menambah baris yang tidak berarti.
        if (($this->gajiPada($hariIni)[$workerId] ?? 0) === $amount) {
            return;
        }

        $baris = WorkerSalary::where('worker_id', $workerId)->whereDate('start_date', $hariIni)->first();

        $baris
            ? $baris->update(['amount' => $amount, 'created_by' => $oleh])
            : WorkerSalary::create(['worker_id' => $workerId, 'amount' => $amount, 'start_date' => $hariIni, 'created_by' => $oleh]);
    }

    /**
     * Beban gaji per hari per pekerja untuk rentang tanggal:
     *   ['2026-10-11' => [3 => 96774, 5 => 50000], ...]
     *
     * Hanya memuat tanggal yang (1) mode bulanannya berlaku dan (2) tidak
     * melewati hari ini — gaji hari esok belum terjadi. Pekerja bergaji 0
     * tidak dimuat.
     *
     * @return array<string,array<int,int>>
     */
    public function harianRentang(string $from, string $to): array
    {
        $sampai = min($to, now()->toDateString());
        if ($from > $sampai) {
            return [];
        }

        $periode = MonthlyWagePeriod::whereDate('start_date', '<=', $sampai)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->get()
            ->map(fn (MonthlyWagePeriod $p) => [$p->start_date->toDateString(), $p->end_date?->toDateString()]);

        if ($periode->isEmpty()) {
            return [];
        }

        // Per pekerja: [[mulai, gaji], ...] urut tanggal mulai.
        $riwayat = [];
        foreach (WorkerSalary::whereDate('start_date', '<=', $sampai)->orderBy('start_date')->get() as $g) {
            $riwayat[$g->worker_id][] = [$g->start_date->toDateString(), $g->amount];
        }

        $hasil = [];
        for ($hari = CarbonImmutable::parse($from); $hari->toDateString() <= $sampai; $hari = $hari->addDay()) {
            $tgl = $hari->toDateString();

            if (! $periode->contains(fn (array $p) => $p[0] <= $tgl && ($p[1] === null || $p[1] >= $tgl))) {
                continue;
            }

            foreach ($riwayat as $pekerja => $baris) {
                $gaji = 0;
                foreach ($baris as [$mulai, $jumlah]) {
                    if ($mulai > $tgl) {
                        break;
                    }
                    $gaji = $jumlah;
                }

                if ($gaji > 0) {
                    $hasil[$tgl][$pekerja] = self::bagianHari($gaji, $hari->day, $hari->daysInMonth);
                }
            }
        }

        return $hasil;
    }

    /** Beban gaji satu tanggal: [worker_id => rupiah]. */
    public function harian(string $date): array
    {
        return $this->harianRentang($date, $date)[$date] ?? [];
    }

    /**
     * Bagian satu hari dari gaji sebulan. Pecahan rupiah dibuang, dan sisanya
     * ditaruh di hari TERAKHIR bulan: Rp 3.000.000 di bulan 31 hari =
     * 96.774 x 30 + 96.780, jumlahnya tepat Rp 3.000.000.
     */
    public static function bagianHari(int $gaji, int $hariKe, int $jumlahHari): int
    {
        $dasar = intdiv($gaji, $jumlahHari);

        return $hariKe === $jumlahHari ? $gaji - $dasar * ($jumlahHari - 1) : $dasar;
    }

    /** Gaji bulanan tiap pekerja yang berlaku pada tanggal itu: [worker_id => rupiah]. */
    private function gajiPada(string $date): array
    {
        $gaji = [];
        foreach (WorkerSalary::whereDate('start_date', '<=', $date)->orderBy('start_date')->get() as $g) {
            $gaji[$g->worker_id] = $g->amount;   // yang terakhir menimpa yang lebih lama
        }

        return $gaji;
    }
}

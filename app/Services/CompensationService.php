<?php

namespace App\Services;

use App\Models\WorkerCompensation;
use Illuminate\Support\Collection;

/**
 * Kompensasi karyawan dalam hitungan laporan.
 *
 * Laporan harian, laporan rentang, dan statistik Dashboard memakai tiga jalur
 * hitung berbeda — ketiganya mengambil angka kompensasi dari sini supaya laba
 * bersih yang tertulis sama di layar mana pun.
 */
class CompensationService
{
    /** Catatan kompensasi satu tanggal, terbaru di atas. */
    public function hari(string $date): Collection
    {
        return WorkerCompensation::whereDate('date', $date)->orderByDesc('id')->get();
    }

    /**
     * Jumlah kompensasi per tanggal: ['2026-10-11' => 45000, ...].
     * Dijumlah di PHP dari tanggal yang sudah di-cast, bukan GROUP BY: kolom
     * 'date' di SQLite (database tes) tersimpan lengkap dengan jam.
     *
     * @return array<string,int>
     */
    public function perTanggal(string $from, string $to): array
    {
        return WorkerCompensation::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get(['date', 'amount'])
            ->groupBy(fn (WorkerCompensation $k) => $k->date->toDateString())
            ->map(fn (Collection $g) => (int) $g->sum('amount'))
            ->all();
    }
}

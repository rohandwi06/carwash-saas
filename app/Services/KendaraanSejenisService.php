<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\WashCategory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Owner membetulkan jenis satu mobil -> AI belajar alasannya dan mengusulkan
 * mobil sejenis yang masih salah jenis (permintaan owner 17/10).
 *
 * Alurnya dua langkah, sengaja:
 *   1. usulan(): AI hanya MENGUSULKAN (dengan alasan per mobil). Tidak ada
 *      yang berubah.
 *   2. terapkan(): mobil yang DICENTANG owner dipindah, dan transaksi lamanya
 *      ikut dikoreksi lewat TransactionService::update — harga, promo, upah,
 *      buku kas yang sudah ditutup, dan jejak koreksinya (edited_*) ikut
 *      tercatat persis seperti koreksi tangan.
 */
class KendaraanSejenisService
{
    /** Batas calon yang dikirim ke AI: prompt tetap pendek & murah. */
    private const MAKS_CALON = 200;

    public function __construct(
        private GeminiVehicleService $ai,
        private TransactionService $transaksi,
    ) {}

    /**
     * @return array{ke:string, aturan:string, usulan:list<array{id:int,name:string,alasan:string,transaksi:int}>, acuan:array{id:int,name:string,transaksi:int}}
     */
    public function usulan(Vehicle $acuan, string $dari): array
    {
        $ke = $acuan->category;
        $hasil = [
            'ke'     => $ke,
            'aturan' => '',
            'usulan' => [],
            // Transaksi lama mobil yang baru dipindah ini sendiri juga ikut
            // dibetulkan saat diterapkan, jadi jumlahnya ikut ditampilkan.
            'acuan'  => [
                'id'        => $acuan->id,
                'name'      => $acuan->name,
                'transaksi' => $this->transaksiBeda($acuan->name, $ke),
            ],
        ];
        if ($dari === $ke || ! WashCategory::where('slug', $dari)->exists()) {
            return $hasil;
        }

        $calon = Vehicle::where('category', $dari)->whereKeyNot($acuan->id)
            ->orderBy('name')->limit(self::MAKS_CALON)->pluck('name', 'id')->all();
        if ($calon === []) {
            return $hasil;
        }

        // Jawaban disimpan sehari: owner yang membuka ulang usulan yang sama
        // tidak membakar kuota lagi. Sidiknya ikut daftar calon, jadi begitu
        // ada mobil yang pindah, AI ditanya ulang.
        $sidik = md5($acuan->name.'|'.$dari.'|'.$ke.'|'.implode(',', array_keys($calon)));
        $ai = Cache::remember("ai-sejenis:{$sidik}", now()->addDay(),
            fn () => $this->ai->usulSejenis($acuan->name, $dari, $ke, $calon));

        $hasil['aturan'] = $ai['aturan'];
        foreach ($ai['usulan'] as $id => $alasan) {
            $hasil['usulan'][] = [
                'id'        => (int) $id,
                'name'      => $calon[$id],
                'alasan'    => $alasan,
                'transaksi' => $this->transaksiBeda($calon[$id], $ke),
            ];
        }

        return $hasil;
    }

    /**
     * Pindahkan mobil terpilih ke [$ke] dan betulkan transaksi lamanya.
     * Transaksi yang tidak bisa dikoreksi (layanannya tidak tersedia di jenis
     * baru, sedang menunggu keputusan batal) dilewati dan dihitung.
     *
     * @param  list<int>  $ids
     * @return array{mobil:int, transaksi:int, dilewati:int}
     */
    public function terapkan(array $ids, string $ke, string $alasan, ?string $oleh): array
    {
        $mobil = Vehicle::whereKey($ids)->get();
        $hasil = ['mobil' => 0, 'transaksi' => 0, 'dilewati' => 0];

        foreach ($mobil as $v) {
            if ($v->category !== $ke) {
                $v->update(['category' => $ke, 'needs_review' => false, 'corrected_at' => now()]);
                $hasil['mobil']++;
            }

            $lama = Transaction::where('vehicle_name', $v->name)->where('category', '!=', $ke)
                ->whereNull('voided_at')->with('workers')->orderBy('id')->get();
            foreach ($lama as $t) {
                try {
                    // Satu transaksi satu DB::transaction (di dalam update):
                    // yang gagal tidak membatalkan yang sudah berhasil.
                    $this->transaksi->update($t, [
                        'category'    => $ke,
                        // Dikirim ulang supaya upah dihitung ulang dengan tarif
                        // jenis yang baru.
                        'worker_ids'  => $t->workers->pluck('id')->all(),
                        'edit_reason' => mb_substr('Jenis dibetulkan: '.$alasan, 0, 120),
                    ], $oleh);
                    $hasil['transaksi']++;
                } catch (Throwable) {
                    $hasil['dilewati']++;
                }
            }
        }

        return $hasil;
    }

    private function transaksiBeda(string $nama, string $ke): int
    {
        return Transaction::where('vehicle_name', $nama)->where('category', '!=', $ke)
            ->whereNull('voided_at')->count();
    }
}

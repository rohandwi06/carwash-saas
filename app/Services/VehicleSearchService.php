<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\WashCategory;
use Illuminate\Support\Collection;

/**
 * Fuzzy search nama kendaraan (toleran salah eja).
 * "penter" -> "Isuzu Panther". Murni string matching, bukan AI.
 */
class VehicleSearchService
{
    public function search(string $query, int $limit = 6): Collection
    {
        $query = strtolower(trim($query));

        if ($query === '') {
            return collect();
        }

        $threshold = (float) config('carwash.search_threshold');

        // Jaga-jaga kalau ada baris lama yang kategorinya sudah dihapus dari
        // Pengaturan (mis. sebelum WashCategoryController::destroy() menolak
        // penghapusan yang masih dipakai katalog). Tanpa saringan ini,
        // frontend crash: CFG.categories[kategori] jadi undefined karena
        // kategorinya sudah tidak ada di /api/config.
        $kategoriValid = WashCategory::pluck('slug')->all();

        $hasil = Vehicle::whereIn('category', $kategoriValid)->get()
            ->map(function (Vehicle $v) use ($query) {
                $v->score = $this->score($query, strtolower($v->name));
                return $v;
            })
            ->filter(fn (Vehicle $v) => $v->score >= $threshold)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        // Ejaan yang sudah dipelajari dari kasir ("raise" -> Toyota Raize)
        // menang: ditaruh paling atas dengan skor yakin, sehingga AI tidak
        // ikut ditanya untuk ketikan yang jawabannya sudah diketahui.
        $dipelajari = app(VehicleMemoryService::class)->cariEjaan($query);
        if ($dipelajari && in_array($dipelajari->category, $kategoriValid, true)) {
            $dipelajari->score = 2.0;
            $hasil = $hasil->reject(fn (Vehicle $v) => $v->id === $dipelajari->id)
                ->prepend($dipelajari)->take($limit)->values();
        }

        return $hasil;
    }

    private function score(string $query, string $name): float
    {
        // Substring = sinyal terkuat
        if (str_contains($name, $query)) {
            return 1 + strlen($query) / strlen($name);
        }

        // Versi "rapat": spasi & tanda baca dibuang, supaya kasir boleh mengetik
        // "mazdarx7" untuk "Mazda RX-7" tanpa perlu spasi/tanda hubung.
        $namaRapat  = $this->rapatkan($name);
        $queryRapat = $this->rapatkan($query);

        if ($queryRapat !== '' && $namaRapat !== '' && str_contains($namaRapat, $queryRapat)) {
            return 1 + strlen($queryRapat) / strlen($namaRapat);
        }

        // Cocokkan per kata agar "penter" ketemu "panther" di "isuzu panther"
        $best = 0.0;
        foreach (explode(' ', $name) as $word) {
            $best = max($best, $this->mirip($query, $word));
        }

        // Ketikan rapat yang typo ("mzdarx7") tetap ketemu — dibandingkan ke nama utuh.
        $best = max($best, $this->mirip($queryRapat, $namaRapat));

        return $best;
    }

    /** Sisakan huruf & angka saja: "Mazda RX-7" -> "mazdarx7" */
    private function rapatkan(string $teks): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', $teks);
    }

    private function mirip(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $s = 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));

        if ($a[0] === $b[0]) {
            $s += 0.08; // huruf pertama sama = sinyal kecil bahwa ini yang dimaksud
        }

        return $s;
    }
}

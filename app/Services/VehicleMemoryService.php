<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleAlias;
use Illuminate\Support\Collection;

/**
 * Ingatan pencarian kendaraan: aplikasi "belajar" dari pilihan kasir dan
 * koreksi owner (permintaan owner 16/10). Gemini sendiri tidak dilatih ulang;
 * yang berubah adalah apa yang aplikasi ketahui sebelum dan saat bertanya.
 *
 * 1. EJAAN KASIR. Kasir mengetik "raise", lalu mencatat cucian Toyota Raize.
 *    Ejaan itu disimpan. Setelah pasangan yang sama terjadi DUA kali,
 *    pencarian langsung menyodorkan Toyota Raize sebagai jawaban yakin dan
 *    AI tidak ditanya lagi. Dua kali, bukan sekali: satu ketukan yang
 *    meleset tidak boleh jadi aturan permanen. Kalau ejaan yang sama
 *    kemudian dipakai untuk mobil lain, hitungannya mulai dari nol lagi.
 *
 *    Yang tidak diingat: ejaan yang sudah ketemu langsung lewat nama mobil
 *    mana pun di katalog ("avanza" ada di "Toyota Avanza"). Mengingatnya
 *    tidak menambah apa-apa, dan justru bisa membajak pencarian mobil lain
 *    yang namanya memuat kata itu.
 *
 * 2. KOREKSI OWNER. Mobil yang jenisnya diganti owner (Daftar mobil, atau
 *    Koreksi transaksi yang ikut membetulkan Daftar mobil) ditandai
 *    corrected_at, dan dikirim ke AI sebagai contoh penggolongan cucian ini
 *    setiap kali AI menebak mobil baru (GeminiVehicleService).
 */
class VehicleMemoryService
{
    /** Ejaan baru dipercaya setelah dipakai sekian kali untuk mobil yang sama. */
    public const HITS_AKTIF = 2;

    /** Berapa koreksi terbaru yang ikut dikirim ke AI sebagai contoh. */
    public const CONTOH_AI = 30;

    /** "Toyota Raize" -> "toyotaraize": huruf kecil, tanpa spasi & tanda baca. */
    public static function rapat(?string $teks): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $teks));
    }

    /**
     * Catat bahwa kasir mengetik $ketikan lalu mencatat cucian $namaKendaraan.
     * Tidak pernah melempar galat ke pemanggilnya — mencatat cucian lebih
     * penting daripada mengingat ejaan.
     */
    public function ingatEjaan(?string $ketikan, ?string $namaKendaraan): void
    {
        $alias = self::rapat($ketikan);
        if (strlen($alias) < 3 || strlen($alias) > 60 || ! $namaKendaraan) {
            return;
        }

        $mobil = Vehicle::whereRaw('LOWER(name) = ?', [mb_strtolower($namaKendaraan)])->first();
        if (! $mobil) {
            return;   // dipilih manual per jenis, bukan dari katalog
        }

        // Sudah ketemu langsung lewat nama mobil mana pun — tidak perlu diingat.
        $ketemuLangsung = Vehicle::pluck('name')
            ->contains(fn ($nama) => str_contains(self::rapat($nama), $alias));
        if ($ketemuLangsung) {
            return;
        }

        $ingat = VehicleAlias::firstOrNew(['alias' => $alias]);
        if ($ingat->exists && $ingat->vehicle_id !== $mobil->id) {
            $ingat->hits = 0;   // ejaan ini sekarang dipakai untuk mobil lain
        }
        $ingat->vehicle_id = $mobil->id;
        $ingat->hits = (int) $ingat->hits + 1;
        $ingat->save();
    }

    /** Mobil yang sudah pasti dimaksud oleh ejaan ini, atau null. */
    public function cariEjaan(string $ketikan): ?Vehicle
    {
        $alias = self::rapat($ketikan);
        if ($alias === '') {
            return null;
        }

        return VehicleAlias::with('vehicle')
            ->where('alias', $alias)
            ->where('hits', '>=', self::HITS_AKTIF)
            ->first()?->vehicle;
    }

    /**
     * Koreksi owner terbaru untuk dijadikan contoh AI.
     *
     * @param  array<string>  $kategoriSah  slug jenis kendaraan yang masih ada
     * @return Collection<int, Vehicle>
     */
    public function contohKoreksi(array $kategoriSah): Collection
    {
        return Vehicle::whereNotNull('corrected_at')
            ->whereIn('category', $kategoriSah)
            ->orderByDesc('corrected_at')
            ->limit(self::CONTOH_AI)
            ->get(['name', 'category']);
    }
}

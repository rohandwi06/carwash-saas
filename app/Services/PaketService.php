<?php

namespace App\Services;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * Paket langganan cucian: kasir, usaha, juragan.
 *
 * Semua cucian memakai kode yang sama; paket hanya satu setelan, `paket`,
 * di tabel settings — tanpa tabel baru, jadi tanpa SQL untuk klien.
 *   - tidak ada / isi tak dikenal -> cucian lama: SEMUA modul. Sama seperti
 *     kunci langganan, cucian yang sudah jalan tidak tersentuh.
 *   - 'kasir' | 'usaha' | 'juragan' -> modul menurut config('carwash.paket').
 *
 * Turun paket tidak menghapus data: menunya hilang, rutenya menolak
 * (middleware `modul`), dan otomatisasinya (takaran barang, aturan harian)
 * berhenti. Naik lagi -> semuanya kembali.
 *
 * Pesan penolakan sengaja netral: tanpa harga dan tanpa tautan (aturan
 * Play Store), sama seperti LanggananService.
 */
class PaketService
{
    public const KEY = 'paket';

    public const PESAN = 'Fitur ini tidak termasuk paket cucian ini.';

    /** Paket tersimpan, atau null untuk cucian lama tanpa setelan paket. */
    public function paket(): ?string
    {
        $isi = Setting::get(self::KEY);

        return in_array($isi, $this->urutan(), true) ? $isi : null;
    }

    public function punya(string $modul): bool
    {
        $minimal = $this->daftarModul()[$modul] ?? null;
        $paket = $this->paket();
        if ($minimal === null || $paket === null) {
            return true;
        }

        return $this->tingkat($paket) >= $this->tingkat($minimal);
    }

    /** @return array<string, bool> semua modul yang bisa dikunci, nyala/mati */
    public function modul(): array
    {
        $hasil = [];
        foreach (array_keys($this->daftarModul()) as $m) {
            $hasil[$m] = $this->punya($m);
        }

        return $hasil;
    }

    /** @return array{paket: ?string, modul: array<string, bool>} */
    public function status(): array
    {
        return ['paket' => $this->paket(), 'modul' => $this->modul()];
    }

    public function ganti(string $paket): void
    {
        if (! in_array($paket, $this->urutan(), true)) {
            throw new InvalidArgumentException('Paket: '.implode(' | ', $this->urutan()).'.');
        }
        Setting::put(self::KEY, $paket);
    }

    /** Kembali ke "cucian lama": semua modul nyala. */
    public function lepas(): void
    {
        Setting::where('key', self::KEY)->delete();
    }

    /** @return list<string> */
    public function urutan(): array
    {
        return config('carwash.paket.urutan', []);
    }

    /** @return array<string, string> modul => paket minimal */
    private function daftarModul(): array
    {
        return config('carwash.paket.modul', []);
    }

    private function tingkat(string $paket): int
    {
        $i = array_search($paket, $this->urutan(), true);

        return $i === false ? PHP_INT_MAX : $i;
    }
}

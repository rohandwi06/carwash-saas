<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Jam operasional toko, berupa DAFTAR shift (mis. Pagi, Sore, Malam) yang
 * dikelola owner. Di luar semua shift, akun KASIR tidak bisa memakai
 * aplikasi — owner tetap bisa masuk kapan saja.
 *
 * Sakelar utama (aktif/nonaktif) dan pesan layar terkunci berlaku global,
 * jadi keduanya tetap tinggal di tabel settings.
 */
class ShiftService
{
    /** Semua shift, urut jam mulai. */
    public function all(): Collection
    {
        return Shift::urut()->get();
    }

    public function settings(): array
    {
        return [
            'enabled' => Setting::get('shift_enabled') === '1',
            // Pesan bebas dari owner untuk layar terkunci kasir.
            'message' => trim((string) Setting::get('shift_message')),
        ];
    }

    /** Sakelar utama + pesan. Jam-jamnya diatur lewat CRUD shift. */
    public function updateSettings(bool $enabled, string $message = ''): array
    {
        Setting::put('shift_enabled', $enabled ? '1' : '0');

        $message = trim($message);
        Setting::put('shift_message', $message !== '' ? $message : null);

        return $this->status();
    }

    /** Status lengkap untuk frontend: dipakai layar Pengaturan & layar terkunci. */
    public function status(): array
    {
        $shifts   = $this->all();
        $aktif    = $shifts->where('is_active', true);
        $sekarang = CarbonImmutable::now()->format('H:i');
        $berjalan = $this->shiftSaatIni($sekarang, $aktif);
        $berikut  = $this->shiftBerikutnya($sekarang, $aktif);

        return $this->settings() + [
            'is_open'       => $this->isOpen(),
            'now'           => $sekarang,
            'shifts'        => $shifts,
            // Kunci aktif tapi tidak ada satu pun shift aktif = salah setel.
            // Aplikasi memilih TIDAK mengunci (lihat isOpen) supaya kasir tidak
            // terjebak; frontend memakai tanda ini untuk memperingatkan owner.
            'no_shift'      => $aktif->isEmpty(),
            'current_shift' => $berjalan,
            'next_shift'    => $berikut,
        ];
    }

    /**
     * Toko sedang buka bila ada shift aktif yang mencakup jam sekarang.
     * Fitur dimatikan, atau belum ada shift aktif sama sekali, dianggap buka —
     * penguncian tanpa jadwal hanya akan mengurung kasir tanpa jalan keluar.
     */
    public function isOpen(?CarbonImmutable $saat = null): bool
    {
        if (! $this->settings()['enabled']) {
            return true;
        }

        $aktif = $this->all()->where('is_active', true);

        if ($aktif->isEmpty()) {
            return true;
        }

        return $this->shiftSaatIni(($saat ?? CarbonImmutable::now())->format('H:i'), $aktif) !== null;
    }

    /** Shift yang sedang berjalan pada jam tertentu, atau null. */
    private function shiftSaatIni(string $jam, Collection $aktif): ?Shift
    {
        return $aktif->first(fn (Shift $s) => $s->mencakup($jam));
    }

    /**
     * Shift yang mencakup suatu waktu — dipakai untuk MENGGOLONGKAN transaksi,
     * bukan untuk mengunci layar.
     *
     * Sengaja tidak melihat sakelar 'shift_enabled': penggolongan tetap
     * berjalan walau penguncian dimatikan. Kalau ikut mati, owner yang tidak
     * memakai penguncian tidak akan pernah punya rekap per shift sama sekali.
     *
     * Di luar semua shift (atau belum ada shift sama sekali) mengembalikan
     * null — transaksinya masuk kelompok "Di luar shift" di laporan, bukan
     * dipaksakan ke shift terdekat yang bukan tempatnya.
     */
    public function shiftPada(?CarbonImmutable $saat = null): ?Shift
    {
        return $this->shiftSaatIni(
            ($saat ?? CarbonImmutable::now())->format('H:i'),
            $this->all()->where('is_active', true),
        );
    }

    /**
     * Shift aktif berikutnya setelah jam tertentu. Kalau hari ini sudah
     * lewat semua, yang dipakai shift paling pagi (berarti besok).
     */
    private function shiftBerikutnya(string $jam, Collection $aktif): ?Shift
    {
        if ($aktif->isEmpty()) {
            return null;
        }

        return $aktif->first(fn (Shift $s) => $s->start_time > $jam)
            ?? $aktif->first();
    }
}

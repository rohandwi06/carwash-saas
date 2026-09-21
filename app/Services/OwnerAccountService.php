<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Hash;

/**
 * Kredensial akun OWNER.
 *
 * Asalnya hanya dari .env (OWNER_USERNAME/OWNER_PASSWORD). Itu tetap
 * dipertahankan sebagai NILAI AWAL supaya owner selalu bisa masuk walau
 * tabel database kosong — tapi begitu owner mengubah akunnya dari layar
 * Pengaturan, yang dipakai adalah simpanan di tabel settings.
 *
 * Password yang diubah dari aplikasi disimpan sebagai HASH, tidak pernah
 * sebagai teks polos. Password .env memang masih teks polos; itu sebabnya
 * mengganti lewat aplikasi lebih aman, dan setelah diganti nilai di .env
 * tidak dipakai lagi untuk login.
 */
class OwnerAccountService
{
    private const KEY_USERNAME = 'owner_username';
    private const KEY_PASSWORD = 'owner_password_hash';

    /** Username owner yang BERLAKU sekarang (simpanan aplikasi, jatuh ke .env). */
    public function username(): string
    {
        $tersimpan = trim((string) Setting::get(self::KEY_USERNAME));

        return $tersimpan !== '' ? $tersimpan : trim((string) config('carwash.owner.username'));
    }

    /** Sudah pernah diubah dari dalam aplikasi? (untuk pesan di layar Pengaturan) */
    public function diubahDariAplikasi(): bool
    {
        return trim((string) Setting::get(self::KEY_PASSWORD)) !== '';
    }

    /**
     * Akun owner sudah bisa dipakai? Kalau belum, login owner DITOLAK TOTAL —
     * disengaja, supaya aplikasi tidak pernah jalan tanpa kredensial.
     */
    public function siap(): bool
    {
        return $this->username() !== '' && $this->passwordTersimpan() !== '';
    }

    /** Cocokkan username + password calon owner. */
    public function cocok(string $username, string $password): bool
    {
        if (! $this->siap()) {
            return false;
        }

        if (! hash_equals($this->username(), $username)) {
            return false;
        }

        return $this->passwordCocok($password);
    }

    /**
     * Cocokkan HANYA password — dipakai saat owner mengubah akunnya sendiri
     * (wajib membuktikan dirinya dulu, bukan cukup bermodal sesi yang terbuka).
     */
    public function passwordCocok(string $password): bool
    {
        $tersimpan = $this->passwordTersimpan();

        if ($tersimpan === '') {
            return false;
        }

        // Hash hasil ubahan dari aplikasi diverifikasi lewat Hash::check;
        // password .env masih teks polos, jadi dibandingkan konstan-waktu.
        return $this->adalahHash($tersimpan)
            ? Hash::check($password, $tersimpan)
            : hash_equals($tersimpan, $password);
    }

    /**
     * Simpan username/password owner yang baru. Password disimpan sebagai hash.
     * Password kosong = tidak diganti (owner cukup mengubah username saja).
     */
    public function simpan(string $username, ?string $password = null): void
    {
        Setting::put(self::KEY_USERNAME, trim($username));

        if ($password !== null && $password !== '') {
            Setting::put(self::KEY_PASSWORD, Hash::make($password));
        } elseif (! $this->diubahDariAplikasi()) {
            // Username sudah pindah ke database sementara passwordnya masih di
            // .env. Ikut pindahkan sebagai hash, kalau tidak login akan
            // mencocokkan username baru dengan password .env yang teks polos.
            $dariEnv = (string) config('carwash.owner.password');
            if ($dariEnv !== '') {
                Setting::put(self::KEY_PASSWORD, Hash::make($dariEnv));
            }
        }
    }

    /** Password yang berlaku: hash simpanan aplikasi, atau teks polos .env. */
    private function passwordTersimpan(): string
    {
        $hash = trim((string) Setting::get(self::KEY_PASSWORD));

        return $hash !== '' ? $hash : (string) config('carwash.owner.password');
    }

    private function adalahHash(string $nilai): bool
    {
        return (Hash::info($nilai)['algoName'] ?? 'unknown') !== 'unknown';
    }
}

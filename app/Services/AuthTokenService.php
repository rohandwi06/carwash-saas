<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Token sesi sederhana untuk aplikasi kasir satu toko.
 * - Token acak 48 karakter, disimpan sebagai HASH di cache (driver database),
 *   jadi token asli tidak pernah tersimpan di server.
 * - Menyimpan role + NAMA pemilik sesi, supaya setiap input bisa
 *   dicatat atas nama siapa (jejak akuntabilitas di rekap harian).
 * - Berlaku 14 jam (cukup satu shift), setelah itu kasir login ulang.
 */
class AuthTokenService
{
    private const TTL_HOURS = 14;

    /** Buat token baru untuk sebuah role ('kasir' | 'owner') atas nama seseorang. */
    public function issue(string $role, string $name): array
    {
        $token = Str::random(48);

        Cache::put($this->key($token), ['role' => $role, 'name' => $name], now()->addHours(self::TTL_HOURS));

        return [
            'token'      => $token,
            'role'       => $role,
            'name'       => $name,
            'expires_at' => now()->addHours(self::TTL_HOURS)->toIso8601String(),
        ];
    }

    /**
     * ['role' => ..., 'name' => ...] pemilik token, atau null jika tidak
     * dikenal/kedaluwarsa. Token format lama (tanpa nama) sengaja DITOLAK
     * supaya tidak ada input anonim — pemiliknya cukup login ulang sekali.
     */
    public function dataFor(string $token): ?array
    {
        $data = Cache::get($this->key($token));

        return (is_array($data)
            && in_array($data['role'] ?? null, ['kasir', 'owner'], true)
            && is_string($data['name'] ?? null) && $data['name'] !== '')
            ? $data
            : null;
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'auth:token:'.hash('sha256', $token);
    }
}

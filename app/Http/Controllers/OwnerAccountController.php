<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OwnerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan akun OWNER — mengubah username & password owner sendiri.
 * Semua endpoint di grup owner-only (routes/api.php).
 *
 * Password lama WAJIB diisi. Sesi yang sudah terbuka saja tidak cukup:
 * tablet kasir sering ditinggal tidak terkunci, dan tanpa syarat ini siapa
 * pun yang menemukannya bisa mengambil alih akun owner.
 */
class OwnerAccountController extends Controller
{
    public function __construct(private OwnerAccountService $owner) {}

    /** GET /api/owner-account — username sekarang (password tidak pernah dikirim). */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'username'      => $this->owner->username(),
            'dari_aplikasi' => $this->owner->diubahDariAplikasi(),
        ]]);
    }

    /** PUT /api/owner-account {current_password, username, password?} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'username'         => ['required', 'string', 'min:3', 'max:64', 'alpha_dash'],
            'password'         => ['nullable', 'string', 'min:6', 'max:255'],
        ]);

        if (! $this->owner->passwordCocok($data['current_password'])) {
            return response()->json(['message' => 'Password owner sekarang salah.'], 422);
        }

        // Username owner tidak boleh menabrak akun kasir — kalau bentrok, login
        // jadi ambigu dan salah satunya tidak akan pernah bisa masuk.
        if (User::where('username', $data['username'])->exists()) {
            return response()->json(['message' => 'Username itu sudah dipakai akun kasir.'], 422);
        }

        $this->owner->simpan($data['username'], $data['password'] ?? null);

        return response()->json(['data' => [
            'username'         => $this->owner->username(),
            'password_diganti' => ! empty($data['password']),
        ]]);
    }
}

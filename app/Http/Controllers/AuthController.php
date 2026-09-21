<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthTokenService;
use App\Services\OwnerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private AuthTokenService $tokens,
        private OwnerAccountService $owner,
    ) {}

    /**
     * POST /api/login {username, password}
     * - Owner: dicocokkan lewat OwnerAccountService — simpanan aplikasi kalau
     *   owner pernah mengubah akunnya, kalau belum jatuh ke OWNER_USERNAME/
     *   OWNER_PASSWORD di .env. Belum diatur = owner TIDAK bisa login.
     * - Kasir: dicari di tabel users (akun dibuat owner lewat Pengaturan).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($this->owner->cocok($data['username'], $data['password'])) {
            return response()->json(['data' => $this->tokens->issue('owner', 'Owner')]);
        }

        $user = User::where('username', $data['username'])->where('role', 'kasir')->first();

        if ($user && Hash::check($data['password'], $user->password)) {
            return response()->json(['data' => $this->tokens->issue('kasir', $user->name)]);
        }

        $hint = $this->owner->siap()
            ? ''
            : ' Akun owner belum diatur — isi OWNER_USERNAME dan OWNER_PASSWORD di file .env server.';

        return response()->json(['message' => 'Username atau password salah.'.$hint], 401);
    }

    /** POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        if ($token = $request->bearerToken()) {
            $this->tokens->revoke($token);
        }

        return response()->json(['data' => null]);
    }

    /** GET /api/me — cek sesi masih hidup + role */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'role' => $request->attributes->get('auth_role'),
            'name' => $request->attributes->get('auth_name'),
        ]]);
    }
}

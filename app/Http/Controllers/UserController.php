<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OwnerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kelola akun KASIR — semua endpoint di grup owner-only (routes/api.php).
 * Akun owner tidak ada di tabel ini (hidup di .env / tabel settings), jadi
 * tidak bisa terhapus/terkunci dari sini.
 */
class UserController extends Controller
{
    public function __construct(private OwnerAccountService $owner) {}

    /** GET /api/users */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::where('role', 'kasir')
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'created_at']),
        ]);
    }

    /** POST /api/users — buat akun kasir baru */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:4', 'max:255'],
        ]);

        if ($data['username'] === $this->owner->username()) {
            return response()->json(['message' => 'Username itu dipakai akun owner.'], 422);
        }

        $user = User::create($data + ['role' => 'kasir']);

        return response()->json(['data' => $user->only('id', 'name', 'username')], 201);
    }

    /**
     * PATCH /api/users/{user} — ubah nama / username / password kasir.
     * Password kosong = tidak diganti (owner tidak perlu tahu password lama).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'kasir', 404);

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:64'],
            'username' => ['sometimes', 'string', 'min:3', 'max:64', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:4', 'max:255'],
        ]);

        if (isset($data['username']) && $data['username'] === $this->owner->username()) {
            return response()->json(['message' => 'Username itu dipakai akun owner.'], 422);
        }

        $user->update($data);

        return response()->json(['data' => $user->only('id', 'name', 'username')]);
    }

    /** DELETE /api/users/{user} */
    public function destroy(User $user): JsonResponse
    {
        abort_unless($user->role === 'kasir', 404);
        $user->delete();

        return response()->json(['data' => null]);
    }
}

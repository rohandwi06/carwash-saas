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
    public function __construct(
        private OwnerAccountService $owner,
        private \App\Services\DirektoriService $direktori,
    ) {}

    /** GET /api/owner-account — username sekarang (password tidak pernah dikirim). */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'username'      => $this->owner->username(),
            'email'         => $this->owner->email(),
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
            // Tidak dikirim = email tidak diubah; string kosong = dihapus.
            'email'            => ['sometimes', 'nullable', 'email:rfc', 'max:120'],
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

        // Email baru/diganti: daftarkan ke buku alamat supaya aplikasi bisa
        // login dengan email ini dan langsung tahu server cucian ini.
        $direktori = null;
        if ($request->has('email')) {
            $baru = mb_strtolower(trim((string) $data['email']));
            $lama = $this->owner->email();
            $this->owner->simpanEmail($baru);
            if ($baru !== '' && $baru !== $lama) {
                $direktori = $this->direktori->daftar($baru, $request->getSchemeAndHttpHost());
            }
        }

        return response()->json(['data' => [
            'username'         => $this->owner->username(),
            'email'            => $this->owner->email(),
            'password_diganti' => ! empty($data['password']),
            'direktori'        => $direktori,
        ]]);
    }

    /**
     * GET /api/direktori/cek?email=... — dipanggil buku alamat pusat untuk
     * memastikan email ini memang email owner cucian ini. Hanya menjawab
     * cocok/tidak (tidak pernah membocorkan email-nya), plus nama usaha.
     */
    public function cekDirektori(Request $request, \App\Services\BusinessProfileService $profil): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));
        $cocok = $email !== '' && $this->owner->email() !== '' && hash_equals($this->owner->email(), $email);

        return response()->json(['data' => ['cocok' => $cocok, 'nama' => $cocok ? $profil->name() : null]]);
    }
}

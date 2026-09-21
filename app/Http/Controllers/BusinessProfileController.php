<?php

namespace App\Http\Controllers;

use App\Services\BusinessProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil usaha yang tampil di header, layar login, resi, dan laporan CSV.
 * Semua role boleh membaca (kasir mencetak resi); hanya owner yang mengubah.
 */
class BusinessProfileController extends Controller
{
    public function __construct(private BusinessProfileService $profil) {}

    /** GET /api/business-profile */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->profil->profile()]);
    }

    /** PUT /api/business-profile {name, tagline?, address?, phone?} — khusus owner */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Lebar resi thermal 58mm ±32 karakter per baris; batas ini
            // menjaga nama tetap muat dua baris, bukan terpotong di tengah.
            'name'    => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:160'],
            'phone'   => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json(['data' => $this->profil->update($data)]);
    }
}

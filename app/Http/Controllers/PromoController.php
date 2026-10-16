<?php

namespace App\Http\Controllers;

use App\Services\PromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Promo pelanggan setia. Owner mengatur (GET/PUT /api/promo); kasir hanya
 * menanyakan status satu pelanggan (GET /api/promo/check) saat mengetik plat
 * atau nomor telepon. Potongannya sendiri dihitung server waktu transaksi
 * disimpan — layar kasir tidak pernah menentukan angkanya.
 */
class PromoController extends Controller
{
    public function __construct(private PromoService $promo) {}

    /** GET /api/promo — owner */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->promo->settings()]);
    }

    /** PUT /api/promo {aktif, jenis, nilai?, setiap, upah} — owner */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'aktif'  => ['required', 'boolean'],
            'jenis'  => ['required', Rule::in(PromoService::JENIS)],
            'nilai'  => ['exclude_if:jenis,gratis', 'required', 'integer', 'min:1',
                $request->input('jenis') === 'persen' ? 'max:100' : 'max:10000000'],
            'setiap' => ['required', 'integer', 'min:2', 'max:100'],
            'upah'   => ['required', Rule::in(PromoService::UPAH)],
        ], [
            'nilai.required' => 'Isi besar potongannya.',
            'nilai.max'      => 'Potongan persen paling besar 100.',
            'setiap.min'     => 'Promo paling sering setiap kedatangan ke-2.',
        ]);

        return response()->json(['data' => $this->promo->simpan($data)]);
    }

    /** GET /api/promo/check?plate=…&phone=… — kasir & owner */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $this->promo->cek($data['plate'] ?? null, $data['phone'] ?? null)]);
    }
}

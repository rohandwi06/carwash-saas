<?php

namespace App\Http\Controllers;

use App\Services\WageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cara menghitung upah satu cucian. Owner-only.
 *
 *   nominal  upah per mobil — nominal tetap per jenis kendaraan + layanan
 *            (wage_rates.amount, diatur di tab Kendaraan). Ini cara lama.
 *   persen   bagi hasil — sekian persen dari harga cuci + add-on untuk
 *            pekerja, sisanya owner. Satu angka untuk semua kendaraan.
 *
 * Yang diubah di sini hanya berlaku untuk transaksi BERIKUTNYA: upah tiap
 * transaksi dibekukan di transaction_worker.wage_share saat dicatat, jadi
 * laporan upah hari-hari yang sudah lewat tidak ikut bergerak. Hitungannya
 * sendiri ada di WageService::jatahFor().
 */
class WageSharingController extends Controller
{
    public function __construct(private WageService $wages) {}

    /** GET /api/wage-sharing */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->wages->sharing()]);
    }

    /** PUT /api/wage-sharing {mode, percent?} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode'    => ['required', Rule::in([WageService::MODE_NOMINAL, WageService::MODE_PERSEN])],
            // Bagian pekerja. Wajib saat persenan; di mode nominal angkanya
            // dibiarkan tersimpan supaya tidak hilang kalau owner bolak-balik.
            'percent' => ['required_if:mode,'.WageService::MODE_PERSEN, 'nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->wages->setSharing($data['mode'], $data['percent'] ?? null),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\SalaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gaji bulanan tiap pekerja — dipakai cara upah "per bulan"
 * (WageService::MODE_BULANAN, dipilih lewat WageSharingController).
 *
 * SEMUA endpoint di sini owner-only — lihat routes/api.php.
 *
 * Angka yang disimpan berlaku MULAI HARI INI: hari-hari sebelumnya tetap
 * memakai gaji lamanya (SalaryService::tetapkan). Angkanya boleh diisi kapan
 * saja, tapi baru dibebankan ke laporan selama mode bulanan berlaku.
 */
class WorkerSalaryController extends Controller
{
    public function __construct(private SalaryService $gaji) {}

    /** GET /api/worker-salaries — semua pekerja beserta gaji bulanannya sekarang. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->gaji->daftar()]);
    }

    /**
     * PUT /api/worker-salaries  {"salaries":[{"worker_id":3,"amount":3000000}, ...]}
     * Rp 0 = pekerja itu tidak digaji bulanan (mis. sudah berhenti).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'salaries'             => ['required', 'array', 'min:1', 'max:200'],
            'salaries.*.worker_id' => ['required', 'integer', 'distinct', 'exists:workers,id'],
            'salaries.*.amount'    => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $oleh = $request->attributes->get('auth_name');

        DB::transaction(function () use ($data, $oleh) {
            foreach ($data['salaries'] as $baris) {
                $this->gaji->tetapkan($baris['worker_id'], $baris['amount'], $oleh);
            }
        });

        return response()->json(['data' => $this->gaji->daftar()]);
    }
}

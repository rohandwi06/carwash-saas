<?php

namespace App\Http\Controllers;

use App\Models\WageRate;
use App\Services\WageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jatah karyawan training PER JENIS KENDARAAN + LAYANAN. Owner-only.
 *
 * Nyuci motor dan nyuci mobil ekstra beda beban, jadi porsi trainingnya juga
 * beda. Angkanya tinggal di kolom wage_rates.trainee_amount, bersebelahan
 * dengan upah senior, supaya jenis kendaraan baru otomatis punya tempat untuk
 * keduanya.
 *
 * Angka ini jatah BERSAMA semua anak training pada satu cucian, bukan per
 * orang — pembagiannya di WageService::bagiUpah.
 */
class TraineeWageController extends Controller
{
    public function __construct(private WageService $wages) {}

    /**
     * GET /api/trainee-wage
     * Mengirim jatah upah senior juga, karena tanpa angka pembanding itu owner
     * tidak punya dasar untuk menilai nominal training yang ia isi.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WageRate::query()
                // Urut mengikuti KATALOG (motor, mobil kecil, mobil besar),
                // bukan abjad slug — yang menaruh "kecil" di atas "motor".
                // Sumbernya sort_order yang sudah dipakai layar kasir, jadi
                // jenis kendaraan baru langsung masuk di posisi yang benar
                // tanpa daftar urutan kedua yang harus ikut dirawat.
                ->leftJoin('wash_categories', 'wash_categories.slug', '=', 'wage_rates.category')
                ->leftJoin('wash_services', 'wash_services.slug', '=', 'wage_rates.service')
                // Baris yatim (kategorinya sudah dihapus dari katalog) ditaruh
                // paling belakang, bukan paling depan seperti bawaan NULL.
                ->orderByRaw('COALESCE(wash_categories.sort_order, 9999)')
                ->orderByRaw('COALESCE(wash_services.sort_order, 9999)')
                ->select('wage_rates.*')
                ->get()
                ->map(fn (WageRate $r) => [
                    'category'       => $r->category,
                    'service'        => $r->service,
                    'wage'           => $r->amount,           // jatah pekerja utk cucian ini
                    'trainee_amount' => $r->trainee_amount,
                ]),
        ]);
    }

    /** PUT /api/trainee-wage {category, service, amount} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::exists('wash_categories', 'slug')],
            'service'  => ['required', Rule::exists('wash_services', 'slug')],
            'amount'   => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [
            'amount.integer' => 'Upah training harus berupa angka rupiah.',
            'amount.min'     => 'Upah training tidak boleh minus.',
        ]);

        $rate = $this->wages->setTraineeWageFor($data['category'], $data['service'], $data['amount']);

        return response()->json(['data' => [
            'category'       => $rate->category,
            'service'        => $rate->service,
            'wage'           => $rate->amount,
            'trainee_amount' => $rate->trainee_amount,
        ]]);
    }
}

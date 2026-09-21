<?php

namespace App\Http\Controllers;

use App\Models\Consignor;
use App\Models\ConsignmentMovement;
use App\Models\ConsignmentPayout;
use App\Models\Product;
use App\Services\ConsignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Titip jual (owner).
 *
 * Kasir tidak menyentuh layar ini sama sekali: barang titipan baginya sama
 * dengan barang lain di menu F&B. Yang owner-only adalah keputusannya —
 * siapa penitipnya, berapa bagi hasilnya, barang masuk berapa, dan kapan
 * uangnya disetorkan.
 */
class ConsignorController extends Controller
{
    public function __construct(private ConsignmentService $titipan) {}

    /** GET /api/consignors — daftar penitip + utang berjalan masing-masing */
    public function index(): JsonResponse
    {
        $rows = Consignor::orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (Consignor $c) => $c->toArray() + [
                'utang'        => $this->titipan->utang($c),
                'jumlah_barang' => Product::where('consignor_id', $c->id)->count(),
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['utang_total' => (int) $rows->sum('utang')],
        ]);
    }

    /** GET /api/consignors/{consignor} — rekap untuk dicocokkan saat setoran */
    public function show(Consignor $consignor): JsonResponse
    {
        return response()->json([
            'data' => $this->titipan->rekap($consignor) + [
                'riwayat_setoran' => ConsignmentPayout::where('consignor_id', $consignor->id)
                    ->orderByDesc('id')->limit(20)->get(),
                'riwayat_barang'  => ConsignmentMovement::with('product:id,name')
                    ->where('consignor_id', $consignor->id)
                    ->orderByDesc('id')->limit(30)->get(),
            ],
        ]);
    }

    /** POST /api/consignors */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validasi($request);

        return response()->json(['data' => Consignor::create($data)], 201);
    }

    /** PATCH /api/consignors/{consignor} */
    public function update(Request $request, Consignor $consignor): JsonResponse
    {
        $data = $this->validasi($request, $consignor);

        $consignor->update($data);

        return response()->json(['data' => $consignor]);
    }

    /**
     * DELETE /api/consignors/{consignor}
     *
     * Ditolak selama utangnya belum lunas ATAU barangnya masih ada di rak.
     * Menghapus penitip yang masih punya hak = menghilangkan bukti utang,
     * dan itu persis jenis kejadian yang membuat orang berhenti menitip.
     */
    public function destroy(Consignor $consignor): JsonResponse
    {
        $utang = $this->titipan->utang($consignor);

        if ($utang > 0) {
            throw ValidationException::withMessages(['consignor' =>
                'Tidak bisa dihapus: masih ada utang Rp '.number_format($utang, 0, ',', '.')
                .' ke '.$consignor->name.'. Setorkan dulu.']);
        }

        $sisa = Product::where('consignor_id', $consignor->id)->sum('stock');

        if ($sisa > 0) {
            throw ValidationException::withMessages(['consignor' =>
                'Tidak bisa dihapus: masih ada '.$sisa.' barang '.$consignor->name
                .' di rak. Catat returnya dulu.']);
        }

        $consignor->delete();

        return response()->json(['data' => null]);
    }

    /** POST /api/consignors/{consignor}/payouts — setor uang ke penitip */
    public function bayar(Request $request, Consignor $consignor): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note'   => ['nullable', 'string', 'max:200'],
        ]);

        $payout = $this->titipan->bayar(
            $consignor,
            $data['amount'],
            $data['note'] ?? null,
            $request->attributes->get('auth_name'),
        );

        return response()->json([
            'data' => $payout,
            'meta' => ['sisa_utang' => $this->titipan->utang($consignor)],
        ], 201);
    }

    /**
     * POST /api/consignment-movements — barang titipan masuk atau diretur.
     * Stok produk ikut bergerak; lihat ConsignmentService::gerak().
     */
    public function gerakBarang(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type'       => ['required', Rule::in(['masuk', 'retur'])],
            'qty'        => ['required', 'integer', 'min:1', 'max:100000'],
            'note'       => ['nullable', 'string', 'max:200'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $by      = $request->attributes->get('auth_name');

        $gerakan = $data['type'] === 'masuk'
            ? $this->titipan->terima($product, $data['qty'], $data['note'] ?? null, $by)
            : $this->titipan->retur($product, $data['qty'], $data['note'] ?? null, $by);

        return response()->json([
            'data' => $gerakan,
            'meta' => ['stock' => $product->refresh()->stock],
        ], 201);
    }

    private function validasi(Request $request, ?Consignor $abaikan = null): array
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:80',
                Rule::unique('consignors', 'name')->ignore($abaikan)],
            'phone'         => ['nullable', 'string', 'max:30'],
            'note'          => ['nullable', 'string', 'max:200'],
            'share_mode'    => ['required', Rule::in(['setor', 'persen'])],
            // Jatah CUCIAN dalam persen. Dibatasi 1-90: 0% berarti cucian
            // bekerja gratis (pakai mode setor kalau memang begitu), dan di
            // atas 90% penitipnya praktis tidak dapat apa-apa.
            'share_percent' => ['nullable', 'required_if:share_mode,persen', 'integer', 'min:1', 'max:90'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        // Mode setor tidak memakai persen — dikosongkan supaya angka lama
        // tidak tertinggal dan menyesatkan saat mode diganti bolak-balik.
        if ($data['share_mode'] === 'setor') {
            $data['share_percent'] = null;
        }

        return $data;
    }
}

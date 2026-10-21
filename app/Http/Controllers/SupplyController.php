<?php

namespace App\Http\Controllers;

use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Services\SupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Barang perlengkapan cuci. Kasir boleh mencatat pakai / beli / hitung sisa;
 * menambah, mengubah (termasuk takaran otomatis), dan menghapus jenis barang
 * khusus owner (lihat routes/api.php).
 */
class SupplyController extends Controller
{
    private const JUMLAH = ['required', 'numeric', 'min:0.001', 'max:1000000'];

    public function __construct(private SupplyService $service) {}

    /** GET /api/supplies */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Supply::urut()->with('rules')->get()]);
    }

    /**
     * GET /api/supplies/{supply}/movements
     * 60 gerak terakhir. Potongan otomatis dari cucian digabung per hari
     * ("40 cucian, -2 liter") — satu baris per mobil menenggelamkan catatan
     * yang ditulis orang.
     */
    public function movements(Supply $supply): JsonResponse
    {
        $manual = $supply->movements()->where('kind', '!=', 'cuci')
            ->orderByDesc('id')->limit(60)->get()
            ->map(fn ($m) => $m->only(['id', 'kind', 'qty', 'stock_after', 'price', 'note', 'created_by'])
                + ['date' => $m->date->toDateString(), 'time' => $m->created_at?->toIso8601String()]);

        $cuci = $supply->movements()->where('kind', 'cuci')
            ->select('date', DB::raw('SUM(qty) as qty'), DB::raw('COUNT(*) as washes'), DB::raw('MAX(created_at) as last_at'))
            ->groupBy('date')->orderByDesc('date')->limit(60)->get()
            ->map(fn ($m) => [
                'id' => null, 'kind' => 'cuci', 'qty' => round((float) $m->qty, 3),
                'washes' => (int) $m->washes, 'date' => substr((string) $m->date, 0, 10),
                'time' => $m->last_at ? \Carbon\Carbon::parse($m->last_at)->toIso8601String() : null,
            ]);

        $semua = $manual->concat($cuci)
            // Detik yang sama: gabungan cucian di atas, karena ia memuat cucian
            // TERAKHIR hari itu.
            ->sortByDesc(fn ($m) => $m['date'].' '.($m['time'] ?? '').($m['kind'] === 'cuci' ? '~' : ''))
            ->values()->take(60);

        return response()->json(['data' => $semua]);
    }

    /** POST /api/supplies/{supply}/use */
    public function use(Request $request, Supply $supply): JsonResponse
    {
        $data = $request->validate(['qty' => self::JUMLAH, 'note' => ['nullable', 'string', 'max:160']]);
        $this->service->pakai($supply, (float) $data['qty'], $data['note'] ?? null, $this->oleh($request));

        return $this->balas($supply);
    }

    /** POST /api/supplies/{supply}/purchase — harga diisi = sekalian pengeluaran. */
    public function purchase(Request $request, Supply $supply): JsonResponse
    {
        $data = $request->validate([
            'qty'   => self::JUMLAH,
            'price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note'  => ['nullable', 'string', 'max:100'],
        ]);
        $this->service->beli($supply, (float) $data['qty'], $data['price'] ?? null,
            $data['note'] ?? null, $this->oleh($request));

        return $this->balas($supply);
    }

    /** POST /api/supplies/{supply}/count — hitung sisa (opname). */
    public function count(Request $request, Supply $supply): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note'  => ['nullable', 'string', 'max:160'],
        ]);
        $this->service->hitung($supply, (float) $data['stock'], $data['note'] ?? null, $this->oleh($request));

        return $this->balas($supply);
    }

    /** POST /api/supplies — owner. Stok awal tercatat sebagai hitung sisa pertama. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->aturan(true));

        $supply = DB::transaction(function () use ($data, $request) {
            $supply = Supply::create([
                'name'       => $data['name'],
                'unit'       => $data['unit'],
                'min_stock'  => $data['min_stock'] ?? null,
                'sort_order' => (int) Supply::max('sort_order') + 1,
            ]);
            $this->simpanTakaran($supply, $data['rules'] ?? []);
            if (! empty($data['stock'])) {
                $this->service->hitung($supply, (float) $data['stock'], 'Stok awal', $this->oleh($request));
            }

            return $supply;
        });

        return $this->balas($supply, 201);
    }

    /** PATCH /api/supplies/{supply} — owner. `rules` dikirim = takaran diganti seluruhnya. */
    public function update(Request $request, Supply $supply): JsonResponse
    {
        $data = $request->validate($this->aturan(false));

        DB::transaction(function () use ($supply, $data) {
            $supply->update(array_intersect_key($data, array_flip(['name', 'unit', 'min_stock', 'sort_order'])));
            if (array_key_exists('rules', $data)) {
                $this->simpanTakaran($supply, $data['rules'] ?? []);
            }
        });

        return $this->balas($supply);
    }

    /** DELETE /api/supplies/{supply} — owner. Pengeluaran pembeliannya tetap ada. */
    public function destroy(Supply $supply): JsonResponse
    {
        $supply->delete();

        return response()->json(['data' => null]);
    }

    private function aturan(bool $baru): array
    {
        $wajib = $baru ? 'required' : 'sometimes';

        return [
            'name'                  => [$wajib, 'string', 'max:60'],
            'unit'                  => [$wajib, 'string', 'max:20'],
            'min_stock'             => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'sort_order'            => ['sometimes', 'integer', 'min:0'],
            'stock'                 => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rules'                 => ['sometimes', 'nullable', 'array', 'max:50'],
            'rules.*.qty'           => self::JUMLAH,
            'rules.*.category_slug' => ['nullable', 'string', 'exists:wash_categories,slug'],
            'rules.*.service_slug'  => ['nullable', 'string', 'exists:wash_services,slug'],
            'rules.*.addon_id'      => ['nullable', 'integer', 'exists:addons,id'],
        ];
    }

    private function simpanTakaran(Supply $supply, array $rules): void
    {
        $supply->rules()->delete();
        foreach ($rules as $r) {
            $addon = $r['addon_id'] ?? null;
            $supply->rules()->create([
                'qty'           => round((float) $r['qty'], 3),
                // Aturan add-on berlaku untuk semua jenis & layanan.
                'category_slug' => $addon ? null : ($r['category_slug'] ?? null),
                'service_slug'  => $addon ? null : ($r['service_slug'] ?? null),
                'addon_id'      => $addon,
            ]);
        }
    }

    private function oleh(Request $request): ?string
    {
        return $request->attributes->get('auth_name');
    }

    private function balas(Supply $supply, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $supply->fresh()->load('rules')], $status);
    }
}

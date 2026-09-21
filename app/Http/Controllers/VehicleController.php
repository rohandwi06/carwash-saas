<?php

namespace App\Http\Controllers;

use App\Models\FailedSearch;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\VehicleSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function __construct(private VehicleSearchService $search) {}

    /** GET /api/vehicles/search?q=penter */
    public function search(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return response()->json([
            'data' => $this->search->search($q),
        ]);
    }

    /** POST /api/vehicles/failed-search — kasir tidak menemukan kendaraan */
    public function logFailedSearch(Request $request): JsonResponse
    {
        $request->validate(['query' => ['required', 'string', 'max:100']]);

        $log = FailedSearch::create([
            'query' => $request->string('query'),
            'date'  => now()->toDateString(),
        ]);

        return response()->json(['data' => $log], 201);
    }

    /**
     * GET /api/vehicles?q=calya — katalog lengkap untuk layar Pengaturan (owner).
     *
     * Katalog ini menentukan harga tiap mobil yang dicari kasir, tapi sampai
     * sekarang tidak ada satu pun layar untuk mengubahnya: isinya ditanam
     * seeder saat pemasangan, lalu ditambahi tebakan AI dari layar kasir.
     * Akibatnya Toyota Calya tetap "mobil kecil" 35rb walau owner menagihnya
     * 40rb, dan satu-satunya cara membetulkan adalah SQL manual.
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        // Berapa kali nama ini benar-benar dipakai — owner perlu tahu mana
        // baris yang menyangkut uang dan mana yang cuma menuh-menuhi daftar.
        $pakai = Transaction::selectRaw('vehicle_name, COUNT(*) as n')
            ->whereNotNull('vehicle_name')
            ->whereNull('voided_at')
            ->groupBy('vehicle_name')
            ->pluck('n', 'vehicle_name');

        $rows = Vehicle::query()
            ->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            ->orderByDesc('needs_review')   // yang belum dicek owner naik ke atas
            ->orderBy('name')
            ->get()
            ->map(fn (Vehicle $v) => [
                'id'           => $v->id,
                'name'         => $v->name,
                'category'     => $v->category,
                'needs_review' => (bool) $v->needs_review,
                'used_count'   => (int) ($pakai[$v->name] ?? 0),
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['needs_review' => Vehicle::where('needs_review', true)->count()],
        ]);
    }

    /** POST /api/vehicles — owner menambah kendaraan sendiri (owner) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100', Rule::unique('vehicles', 'name')],
            'category' => ['required', Rule::exists('wash_categories', 'slug')],
        ]);

        $vehicle = Vehicle::create($data + ['needs_review' => false]);

        return response()->json(['data' => $vehicle], 201);
    }

    /**
     * PATCH /api/vehicles/{vehicle} — pindahkan kendaraan ke kategori lain (owner)
     *
     * Transaksi lampau sengaja TIDAK ikut berubah: itu catatan uang yang
     * sudah diterima, bukan daftar harga. Yang dikembalikan cuma jumlahnya,
     * supaya owner tahu ada yang perlu diperiksa di Pembukuan.
     */
    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:100', Rule::unique('vehicles', 'name')->ignore($vehicle)],
            'category' => ['sometimes', Rule::exists('wash_categories', 'slug')],
        ]);

        // Owner menyentuhnya = owner sudah memutuskan: tanda "perlu dicek" lunas.
        $vehicle->update($data + ['needs_review' => false]);

        $lampau = Transaction::where('vehicle_name', $vehicle->name)
            ->where('category', '!=', $vehicle->category)
            ->whereNull('voided_at')
            ->count();

        return response()->json(['data' => $vehicle, 'meta' => ['transaksi_beda_kategori' => $lampau]]);
    }

    /** DELETE /api/vehicles/{vehicle} — buang baris sampah (owner) */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();

        return response()->json(['data' => true]);
    }
}

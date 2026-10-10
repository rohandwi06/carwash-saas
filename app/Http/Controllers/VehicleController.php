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
     * GET /api/vehicles?q=calya&page=2 — katalog per halaman untuk layar
     * Pengaturan (owner), 20 mobil per halaman kecuali per_page diisi.
     *
     * Katalog ini menentukan harga tiap mobil yang dicari kasir, tapi sampai
     * sekarang tidak ada satu pun layar untuk mengubahnya: isinya ditanam
     * seeder saat pemasangan, lalu ditambahi tebakan AI dari layar kasir.
     * Akibatnya Toyota Calya tetap "mobil kecil" 35rb walau owner menagihnya
     * 40rb, dan satu-satunya cara membetulkan adalah SQL manual.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $q       = trim((string) ($data['q'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 20);

        $query = $this->saring($q)
            ->orderByDesc('needs_review')   // yang belum dicek owner naik ke atas
            ->orderBy('name')
            ->orderBy('id');                // nama kembar tidak berpindah halaman

        // Halaman di luar jangkauan (mis. owner menghapus mobil terakhir di
        // halaman terakhir) dijepit ke halaman terakhir yang ada, bukan
        // dijawab dengan daftar kosong yang terlihat seperti katalog hilang.
        $total    = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min((int) ($data['page'] ?? 1), $lastPage);

        $halaman = $query->forPage($page, $perPage)->get();

        // Berapa kali nama ini benar-benar dipakai — owner perlu tahu mana
        // baris yang menyangkut uang dan mana yang cuma menuh-menuhi daftar.
        // Dihitung hanya untuk mobil di halaman ini, bukan seluruh katalog.
        $pakai = Transaction::selectRaw('vehicle_name, COUNT(*) as n')
            ->whereIn('vehicle_name', $halaman->pluck('name'))
            ->whereNull('voided_at')
            ->groupBy('vehicle_name')
            ->pluck('n', 'vehicle_name');

        return response()->json([
            'data' => $halaman->map(fn (Vehicle $v) => [
                'id'           => $v->id,
                'name'         => $v->name,
                'category'     => $v->category,
                'needs_review' => (bool) $v->needs_review,
                'used_count'   => (int) ($pakai[$v->name] ?? 0),
            ]),
            'meta' => [
                // Seluruh katalog, bukan hasil saringan: peringatan "belum
                // dicek" tidak boleh hilang hanya karena owner sedang mencari.
                'needs_review' => Vehicle::where('needs_review', true)->count(),
                'page'         => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => $lastPage,
            ],
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

    /**
     * POST /api/vehicles/massal — hapus atau pindah jenis banyak mobil
     * sekaligus (owner).
     *
     * Sasarannya daftar id, atau semua=true (+ q) untuk "pilih semua" lintas
     * halaman: layar hanya memegang 20 mobil per halaman, jadi ia tidak bisa
     * mengirim id seluruh katalog. q-nya harus kata cari yang sedang tampil,
     * supaya yang kena persis mobil yang dilihat owner.
     *
     * Seperti hapus/ubah satuan, transaksi lampau tidak ikut berubah.
     */
    public function massal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'aksi'     => ['required', Rule::in(['hapus', 'pindah'])],
            'semua'    => ['sometimes', 'boolean'],
            'q'        => ['nullable', 'string', 'max:100'],
            'ids'      => ['sometimes', 'array', 'max:5000'],
            'ids.*'    => ['integer'],
            'category' => ['required_if:aksi,pindah', Rule::exists('wash_categories', 'slug')],
        ]);

        if (! empty($data['semua'])) {
            $query = $this->saring(trim((string) ($data['q'] ?? '')));
        } elseif (! empty($data['ids'])) {
            $query = Vehicle::whereKey($data['ids']);
        } else {
            return response()->json(['message' => 'Belum ada mobil yang dipilih.'], 422);
        }

        $jumlah = $data['aksi'] === 'hapus'
            ? $query->delete()
            // Owner menyentuhnya = owner sudah memutuskan: tanda "perlu dicek" lunas.
            : $query->update(['category' => $data['category'], 'needs_review' => false]);

        return response()->json(['data' => ['jumlah' => $jumlah]]);
    }

    /** Katalog yang namanya memuat kata cari; kosong = seluruh katalog. */
    private function saring(string $q)
    {
        return Vehicle::query()
            ->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']));
    }

    /** DELETE /api/vehicles/{vehicle} — buang baris sampah (owner) */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();

        return response()->json(['data' => true]);
    }
}

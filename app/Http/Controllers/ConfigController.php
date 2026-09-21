<?php

namespace App\Http\Controllers;

use App\Models\Addon;
use App\Models\Vehicle;
use App\Models\WageRate;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __construct(private PricingService $pricing)
    {
    }

    /** GET /api/config — kategori, harga, layanan, add-on, tarif upah untuk frontend */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'categories' => $this->pricing->categories(),
                // Dipakai layar kasir untuk memutuskan perlu-tidaknya AI ikut
                // ditanya walau pencarian lokal sudah mengembalikan sesuatu.
                'search_confident' => (float) config('carwash.search_confident'),
                'services'   => $this->pricing->services(),
                // Kendaraan yang masuk katalog dari tebakan AI dan belum
                // dibenarkan owner. Angkanya dipakai untuk menempelkan tanda
                // di menu Pengaturan: tanpa itu, tebakan AI mengendap jadi
                // harga permanen tanpa owner pernah punya alasan untuk
                // membuka layar katalog.
                'vehicles_need_review' => Vehicle::where('needs_review', true)->count(),
                'addons'     => Addon::urut()->where('is_active', true)->get(['id', 'name', 'price']),
                'wage_rates' => WageRate::all()
                    ->groupBy('category')
                    ->map(fn ($rows) => $rows->pluck('amount', 'service')->map(fn ($v) => (int) $v)->all()),
            ],
        ]);
    }
}

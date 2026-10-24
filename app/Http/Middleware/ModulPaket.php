<?php

namespace App\Http\Middleware;

use App\Services\PaketService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak rute modul yang tidak termasuk paket cucian ini, mis.
 * `->middleware('modul:barang')`. Layar web & aplikasi sudah menyembunyikan
 * menunya (lihat GET /api/langganan); ini penjaga sebenarnya, yang tetap
 * menolak walau aplikasi versi lama memanggilnya.
 *
 * Cucian lama (tanpa setelan paket) tidak pernah tersentuh.
 */
class ModulPaket
{
    public function __construct(private PaketService $paket) {}

    public function handle(Request $request, Closure $next, string $modul): Response
    {
        if ($this->paket->punya($modul)) {
            return $next($request);
        }

        return response()->json(['message' => PaketService::PESAN, 'modul' => $modul], 403);
    }
}

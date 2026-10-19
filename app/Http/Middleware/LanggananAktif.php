<?php

namespace App\Http\Middleware;

use App\Services\LanggananService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak PENCATATAN uang (cucian, F&B, pengeluaran, setoran) selama
 * langganan belum aktif — lihat LanggananService. Membaca & mengatur tetap
 * boleh, jadi middleware ini hanya dipasang di rute tulis yang mencatat.
 *
 * Cucian lama (tanpa setelan langganan) tidak pernah tersentuh.
 */
class LanggananAktif
{
    public function __construct(private LanggananService $langganan) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->langganan->aktif()) {
            return $next($request);
        }

        $owner = $request->attributes->get('auth_role') === 'owner';

        return response()->json([
            'message' => $owner ? LanggananService::PESAN_OWNER : LanggananService::PESAN_KASIR,
            'langganan' => $this->langganan->status(),
        ], 402);
    }
}

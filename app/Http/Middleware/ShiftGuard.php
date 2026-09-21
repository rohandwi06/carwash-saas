<?php

namespace App\Http\Middleware;

use App\Services\ShiftService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Di luar jam operasional, akun KASIR tidak boleh memakai aplikasi.
 * Owner sengaja dilewatkan: ia perlu bisa membuka laporan & memperbaiki
 * data kapan saja, termasuk setelah toko tutup.
 *
 * Dipasang setelah pin.auth (butuh auth_role) — lihat routes/api.php.
 */
class ShiftGuard
{
    public function __construct(private ShiftService $shift) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('auth_role') === 'owner' || $this->shift->isOpen()) {
            return $next($request);
        }

        $status = $this->shift->status();
        $next   = $status['next_shift'];

        return response()->json([
            'message' => $next
                ? "Shift sudah tutup. Aplikasi bisa dipakai lagi mulai jam {$next->start_time} ({$next->name})."
                : 'Shift sudah tutup.',
            'shift'   => $status,
        ], 423); // 423 Locked — dibedakan dari 401 supaya frontend tidak memaksa login ulang
    }
}

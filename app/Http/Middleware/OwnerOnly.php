<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint sensitif (hapus data, ubah tarif upah, reset antrean)
 * hanya boleh diakses sesi yang login dengan PIN OWNER.
 */
class OwnerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('auth_role') !== 'owner') {
            return response()->json([
                'message' => 'Aksi ini butuh PIN owner.',
            ], 403);
        }

        return $next($request);
    }
}

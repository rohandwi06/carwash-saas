<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup aksi yang di situs demo bisa mengunci pengunjung lain: mengganti
 * password owner, atau mengubah/menghapus akun kasir. Login demo dipakai
 * bersama semua calon klien; satu orang yang iseng mengganti password akan
 * membuat yang lain tidak bisa masuk sampai data direset malam harinya.
 *
 * Di luar APP_ENV=demo middleware ini tidak berbuat apa-apa.
 */
class BukanDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('demo')) {
            return response()->json([
                'message' => 'Tidak tersedia di versi demo — akun ini dipakai bersama semua pengunjung.',
            ], 403);
        }

        return $next($request);
    }
}

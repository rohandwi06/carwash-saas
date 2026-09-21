<?php

namespace App\Http\Middleware;

use App\Services\AuthTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Semua endpoint API wajib membawa token hasil login PIN.
 * Token dikirim via header:  Authorization: Bearer <token>
 * (atau ?token=... khusus untuk link unduh CSV yang tidak bisa set header).
 */
class PinAuth
{
    public function __construct(private AuthTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->query('token');

        $auth = $token ? $this->tokens->dataFor($token) : null;

        if ($auth === null) {
            return response()->json([
                'message' => 'Sesi berakhir atau belum login. Silakan login dulu.',
            ], 401);
        }

        $request->attributes->set('auth_role', $auth['role']);
        $request->attributes->set('auth_name', $auth['name']);

        return $next($request);
    }
}

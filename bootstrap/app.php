<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // <-- tambahkan ini
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Di belakang Cloudflare Tunnel, nginx/cloudflared berjalan di mesin
        // yang sama dan meneruskan X-Forwarded-Proto: https. Tanpa ini Laravel
        // mengira koneksinya http, lalu asset() menghasilkan URL http:// yang
        // diblokir browser sebagai mixed content — CSS & JS tidak termuat.
        // Hanya localhost yang dipercaya: header dari luar tidak boleh menipu.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->alias([
            'pin.auth' => \App\Http\Middleware\PinAuth::class,
            'owner'    => \App\Http\Middleware\OwnerOnly::class,
            'shift'    => \App\Http\Middleware\ShiftGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Aturan bisnis yang dilanggar (stok kurang, pengajuan dobel, dsb.)
        // dilempar sebagai exception biasa dari Service. Tanpa ini Laravel
        // membalas 500 "Server Error" dan kasir tidak tahu apa yang salah.
        $exceptions->render(function (\InvalidArgumentException|\RuntimeException $e, $request) {
            // Exception yang SUDAH membawa status codenya sendiri harus lewat
            // apa adanya. Symfony\...\HttpException kebetulan turunan
            // \RuntimeException, jadi tanpa saringan ini semuanya ikut tertangkap
            // dan dilaporkan 422 — termasuk 429 dari throttle (kasir jadi tidak
            // pernah tahu ia kena batas), 404 rute tidak dikenal, dan abort(403).
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;   // null = serahkan kembali ke penanganan bawaan Laravel
            }

            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();

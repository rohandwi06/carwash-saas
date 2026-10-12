<?php

use App\Http\Controllers\ReceiptController;
use App\Services\BusinessProfileService;
use Illuminate\Support\Facades\Route;

/*
| Root menyajikan aplikasi kasir dari resources/views (Blade),
| dipecah per layar di resources/views/kasir/partials/.
| CSS & JS statis tetap di public/css & public/js.
*/
Route::get('/', fn (BusinessProfileService $profil) => view('kasir.index', [
    'usaha' => $profil->profile(),
]));

/*
| Resi PDF yang tautannya dikirim kasir ke WhatsApp pelanggan. Dibuka tanpa
| login; 'signed' menolak alamat yang tanda tangannya tidak cocok atau sudah
| lewat masanya, jadi nomor nota lain tidak bisa dibuka dengan menebak angka.
*/
Route::get('/resi/{jenis}/{id}', [ReceiptController::class, 'pdf'])
    ->whereIn('jenis', ['c', 'f'])->whereNumber('id')
    ->middleware(['signed:relative', 'throttle:30,1'])
    ->name('resi.pdf');

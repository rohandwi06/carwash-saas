<?php

use Illuminate\Support\Facades\Route;

/*
| Root menyajikan aplikasi kasir dari resources/views (Blade),
| dipecah per layar di resources/views/kasir/partials/.
| CSS & JS statis tetap di public/css & public/js.
*/
Route::get('/', fn () => view('kasir.index'));

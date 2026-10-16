<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Backup pembukuan tiap hari jam 21:30 (setelah tutup toko).
| Butuh scheduler jalan: `php artisan schedule:work` (paralel dengan serve),
| atau cron `* * * * * php /path/artisan schedule:run` di server.
*/
Schedule::command('db:backup')->dailyAt('21:30');

/*
| Foto kondisi kendaraan disimpan 60 hari (FotoKendaraanService). Tanpa
| cron pun foto lama tetap terhapus sedikit demi sedikit setiap ada foto
| baru; perintah ini menyapu sisanya sekaligus.
*/
Artisan::command('photos:prune', function (\App\Services\FotoKendaraanService $foto) {
    $this->info($foto->bersihkan(100000).' foto kedaluwarsa dihapus.');
})->purpose('Hapus foto kondisi kendaraan yang lebih tua dari 60 hari');
Schedule::command('photos:prune')->dailyAt('03:30');

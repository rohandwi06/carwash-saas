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

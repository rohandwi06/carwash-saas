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

// Langganan cucian (LanggananService): lihat, kunci, aktifkan manual, lepas.
//   php artisan langganan              -> status
//   php artisan langganan kunci        -> server baru, belum bayar
//   php artisan langganan tambah 30    -> tambah 30 hari (mis. bayar transfer)
//   php artisan langganan lepas        -> kembali ke tagihan manual (tanpa kunci)
Artisan::command('langganan {aksi=status} {hari=30}', function (\App\Services\LanggananService $l, string $aksi, int $hari) {
    match ($aksi) {
        'kunci' => $l->mulaiTerkunci(),
        'tambah' => $l->perpanjang($hari),
        'lepas' => $l->lepas(),
        'status' => null,
        default => throw new \InvalidArgumentException('Aksi: status | kunci | tambah N | lepas'),
    };
    $s = $l->status();
    $this->info(! $s['wajib'] ? 'Tanpa kunci langganan (tagihan manual).'
        : ($s['aktif'] ? "Aktif sampai {$s['sampai']} (sisa {$s['sisa_hari']} hari)." : 'Belum aktif / habis: pencatatan terkunci.'));
})->purpose('Status & aktivasi langganan cucian');

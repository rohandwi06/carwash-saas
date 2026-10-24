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

// Langganan cucian (LanggananService) & paketnya (PaketService).
//   php artisan langganan              -> status
//   php artisan langganan kunci        -> server baru, belum bayar
//   php artisan langganan tambah 30    -> tambah 30 hari (mis. bayar transfer)
//   php artisan langganan lepas        -> kembali ke tagihan manual (tanpa kunci)
//   php artisan langganan paket usaha  -> ganti paket: kasir | usaha | juragan
//   php artisan langganan paket semua  -> tanpa setelan paket (semua modul)
Artisan::command('langganan {aksi=status} {nilai?}', function (\App\Services\LanggananService $l, \App\Services\PaketService $p, string $aksi, ?string $nilai = null) {
    match ($aksi) {
        'kunci' => $l->mulaiTerkunci(),
        'tambah' => $l->perpanjang((int) ($nilai ?? 30)),
        'lepas' => $l->lepas(),
        'paket' => $nilai === 'semua' ? $p->lepas() : $p->ganti((string) $nilai),
        'status' => null,
        default => throw new \InvalidArgumentException('Aksi: status | kunci | tambah N | lepas | paket NAMA'),
    };
    $s = $l->status();
    $this->info(! $s['wajib'] ? 'Tanpa kunci langganan (tagihan manual).'
        : ($s['aktif'] ? "Aktif sampai {$s['sampai']} (sisa {$s['sisa_hari']} hari)." : 'Belum aktif / habis: pencatatan terkunci.'));
    $mati = array_keys(array_filter($p->modul(), fn ($nyala) => ! $nyala));
    $this->info('Paket: '.($p->paket() ?? 'tanpa setelan (semua modul)')
        .($mati ? '. Modul mati: '.implode(', ', $mati).'.' : '.'));
})->purpose('Status & aktivasi langganan cucian');

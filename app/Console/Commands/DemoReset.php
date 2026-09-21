<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Bangun ulang data situs demo: kosongkan database, pasang katalog awal,
 * lalu isi 30 hari data contoh yang berakhir hari ini.
 *
 * Dijalankan cron cPanel tiap malam langsung (bukan lewat schedule:run),
 * karena scheduler Laravel menjalankan perintah sebagai proses terpisah
 * lewat proc_open — yang sering dimatikan shared hosting. Artisan::call di
 * bawah berjalan di proses yang sama.
 *
 * HANYA jalan bila APP_ENV=demo. Perintah ini MENGHAPUS SELURUH database;
 * di instalasi cucian sungguhan (production) ia menolak mentah-mentah.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Hapus semua data dan isi ulang data contoh 30 hari (hanya APP_ENV=demo)';

    public function handle(): int
    {
        if (! app()->environment('demo')) {
            $this->error('demo:reset hanya untuk situs demo (APP_ENV=demo). Lingkungan ini: '.app()->environment().'.');

            return self::FAILURE;
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true], $this->output);
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true], $this->output);

        return self::SUCCESS;
    }
}

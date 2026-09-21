<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Cadangkan database (seluruh pembukuan) secara aman.
 *
 * - MySQL  : pakai mysqldump --single-transaction (snapshot konsisten
 *            walau aplikasi sedang dipakai).
 * - SQLite : pakai `VACUUM INTO` bawaan SQLite.
 *
 * Jadwal harian ada di routes/console.php. Bisa juga manual:
 *   php artisan db:backup
 *
 * PENTING: backup ini masih di komputer yang sama. Minimal seminggu sekali
 * salin folder storage/backups ke flashdisk / Google Drive — kalau laptopnya
 * mati total, backup lokal ikut hilang.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : Jumlah backup terbaru yang disimpan}';

    protected $description = 'Backup database ke storage/backups (rotasi otomatis)';

    public function handle(): int
    {
        $dir = storage_path('backups');
        File::ensureDirectoryExists($dir);

        $driver = config('database.default');

        $target = match ($driver) {
            'mysql'  => $this->backupMysql($dir),
            'sqlite' => $this->backupSqlite($dir),
            default  => null,
        };

        if ($target === null) {
            $this->error('Backup gagal (driver: '.$driver.'). Cek pesan di atas.');

            return self::FAILURE;
        }

        $this->info('Backup tersimpan: '.$target.' ('.round(File::size($target) / 1024).' KB)');

        $this->rotate($dir, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    private function backupMysql(string $dir): ?string
    {
        $mysqldump = $this->findMysqldump();

        if ($mysqldump === null) {
            $this->error('mysqldump.exe tidak ditemukan. Pastikan XAMPP MySQL terpasang.');

            return null;
        }

        $conn = config('database.connections.mysql');
        $target = $dir.'/otin-'.now()->format('Y-m-d_His').'.sql';

        $cmd = [
            $mysqldump,
            '--host='.$conn['host'],
            '--port='.$conn['port'],
            '--user='.$conn['username'],
            '--single-transaction',
            '--routines',
            '--result-file='.$target,
            $conn['database'],
        ];

        // Password lewat env agar tidak terlihat di daftar proses.
        $env = ($conn['password'] ?? '') !== '' ? ['MYSQL_PWD' => $conn['password']] : null;
        $process = new Process($cmd, null, $env);

        $process->setTimeout(300)->run();

        if (! $process->isSuccessful() || ! File::exists($target)) {
            $this->error(trim($process->getErrorOutput()) ?: 'mysqldump gagal tanpa pesan.');
            File::delete($target);

            return null;
        }

        return $target;
    }

    private function backupSqlite(string $dir): ?string
    {
        $source = config('database.connections.sqlite.database');

        if (! is_string($source) || ! File::exists($source)) {
            $this->error('File database tidak ditemukan: '.$source);

            return null;
        }

        $target = $dir.'/otin-'.now()->format('Y-m-d_His').'.sqlite';

        $pdo = new \PDO('sqlite:'.$source);
        $pdo->exec("VACUUM INTO '".str_replace("'", "''", $target)."'");
        unset($pdo);

        return $target;
    }

    /** Cari mysqldump: path instalasi XAMPP/LAMPP yang umum, lalu PATH sistem. */
    private function findMysqldump(): ?string
    {
        $isWindows = PHP_OS_FAMILY === 'Windows';

        $candidates = $isWindows
            ? [
                dirname(PHP_BINARY, 2).'/mysql/bin/mysqldump.exe', // C:\xampp\mysql\bin
            ]
            : [
                '/opt/lampp/bin/mysqldump', // XAMPP/LAMPP Linux
                '/usr/bin/mysqldump',
                '/usr/local/bin/mysqldump',
            ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        $finder = $isWindows ? 'where.exe' : 'which';
        $check = new Process([$finder, 'mysqldump']);
        $check->run();

        if ($check->isSuccessful()) {
            return trim(explode("\n", $check->getOutput())[0]);
        }

        return null;
    }

    /** Rotasi: simpan hanya N backup terbaru. */
    private function rotate(string $dir, int $keep): void
    {
        $files = collect(File::files($dir))
            ->filter(fn ($f) => in_array($f->getExtension(), ['sql', 'sqlite'], true))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        $files->slice($keep)->each(function ($f) {
            File::delete($f->getPathname());
            $this->line('Backup lama dihapus: '.$f->getFilename());
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menggeser waktu yang sudah tersimpan dari UTC ke WIB (+7 jam).
 *
 * Aplikasi tadinya memakai timezone UTC bawaan Laravel padahal tokonya di
 * Indonesia, jadi setiap waktu ditulis 7 jam lebih lambat dari jam dinding —
 * cucian jam 16:14 WIB tersimpan sebagai 09:14. Bersamaan dengan migrasi ini,
 * config/app.php diubah ke Asia/Jakarta.
 *
 * Tanpa penggeseran ini, mengubah timezone saja justru MEMBUAT KACAU: nilai
 * lama yang ditulis sebagai UTC akan dibaca sebagai WIB, sehingga jam semua
 * transaksi lama tampil 7 jam lebih awal daripada kejadian sebenarnya.
 *
 * YANG TIDAK DIGESER, dan alasannya:
 * - Kolom 'date' (tanggal saja). Toko buka 07:00-21:00 WIB = 00:00-14:00 UTC,
 *   masih pada tanggal kalender yang sama, jadi tanggalnya sudah benar.
 *   Menggesernya justru akan memindahkan catatan sore ke tanggal berikutnya.
 * - Tabel bawaan framework (cache, jobs, sessions, migrations). Kadaluwarsa
 *   cache disimpan sebagai angka unix, tidak terpengaruh timezone.
 *
 * Migrasi ini mengubah data usaha yang nyata. down() mengembalikannya (-7 jam)
 * supaya bisa dibatalkan bila ternyata keliru.
 */
return new class extends Migration
{
    /** Tabel aplikasi -> kolom waktu yang perlu digeser. */
    private const KOLOM = [
        'transactions'      => ['created_at', 'updated_at', 'voided_at', 'void_requested_at', 'void_rejected_at'],
        'fnb_sales'         => ['created_at', 'updated_at'],
        'fnb_sale_items'    => ['created_at', 'updated_at'],
        'expenses'          => ['created_at', 'updated_at'],
        'worker_deposits'   => ['created_at', 'updated_at'],
        'transaction_drafts' => ['created_at', 'updated_at'],
        'failed_searches'   => ['created_at', 'updated_at'],
        'workers'           => ['created_at', 'updated_at'],
        'users'             => ['created_at', 'updated_at'],
        'vehicles'          => ['created_at', 'updated_at'],
        'products'          => ['created_at', 'updated_at'],
        'shifts'            => ['created_at', 'updated_at'],
        'settings'          => ['created_at', 'updated_at'],
        'wage_rates'        => ['created_at', 'updated_at'],
        'wash_categories'   => ['created_at', 'updated_at'],
        'wash_services'     => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->geser(7);
    }

    public function down(): void
    {
        $this->geser(-7);
    }

    private function geser(int $jam): void
    {
        $tanda = $jam >= 0 ? '+' : '-';
        $besar = abs($jam);
        // Produksi selalu MySQL; cabang sqlite di sini murni supaya migrasi
        // ini bisa jalan di test suite (:memory:) & dev lokal tanpa MySQL —
        // perilaku MySQL sendiri tidak berubah sama sekali.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach (self::KOLOM as $tabel => $kolom) {
            if (! Schema::hasTable($tabel)) {
                continue;   // tabel opsional/belum ada di pemasangan tertentu
            }

            $ada = array_values(array_filter(
                $kolom,
                fn ($c) => Schema::hasColumn($tabel, $c),
            ));

            if ($ada === []) {
                continue;
            }

            // Satu UPDATE per tabel. NULL dibiarkan NULL (mis. voided_at pada
            // transaksi yang tidak pernah dibatalkan).
            DB::table($tabel)->update(collect($ada)->mapWithKeys(fn ($c) => [
                $c => $sqlite
                    ? DB::raw("CASE WHEN `$c` IS NULL THEN NULL ELSE datetime(`$c`, '{$tanda}{$besar} hours') END")
                    : DB::raw("CASE WHEN `$c` IS NULL THEN NULL ELSE `$c` {$tanda} INTERVAL {$besar} HOUR END"),
            ])->all());
        }
    }
};

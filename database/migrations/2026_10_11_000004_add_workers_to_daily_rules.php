<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu aturan otomatis harian untuk BEBERAPA pekerja sekaligus (koreksi owner
 * 11/10: memilih pekerja satu per satu lewat dropdown berarti membuat aturan
 * yang sama berkali-kali).
 *
 * Sebelumnya: daily_rules.worker_id — satu pekerja, atau kosong = semua.
 * Sekarang:
 *   all_workers        true  = semua pekerja yang kerja hari itu;
 *                      false = hanya yang terdaftar di daily_rule_worker.
 *   daily_rule_worker  pekerja pilihan sebuah aturan.
 *
 * all_workers sengaja kolom tersendiri, BUKAN "daftarnya kosong = semua":
 * kalau begitu, aturan "potong kasbon Andi" diam-diam berubah jadi "potong
 * semua orang" begitu Andi dihapus dari daftar pekerja (barisnya di tabel
 * penghubung ikut terhapus). Dengan kolom ini aturan seperti itu tinggal tidak
 * mengenai siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_rules', function (Blueprint $table) {
            $table->boolean('all_workers')->default(true)->after('type');
        });

        Schema::create('daily_rule_worker', function (Blueprint $table) {
            $table->foreignId('daily_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->primary(['daily_rule_id', 'worker_id']);
        });

        // Aturan lama yang menunjuk satu pekerja: pindahkan ke tabel penghubung.
        foreach (DB::table('daily_rules')->whereNotNull('worker_id')->get(['id', 'worker_id']) as $a) {
            DB::table('daily_rule_worker')->insert(['daily_rule_id' => $a->id, 'worker_id' => $a->worker_id]);
        }
        DB::table('daily_rules')->whereNotNull('worker_id')->update(['all_workers' => false]);

        Schema::table('daily_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worker_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_rules', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->after('type')->constrained()->cascadeOnDelete();
        });

        // Bentuk lama hanya memuat satu pekerja per aturan: yang pertama saja.
        foreach (DB::table('daily_rule_worker')->orderBy('worker_id')->get() as $baris) {
            DB::table('daily_rules')->where('id', $baris->daily_rule_id)->whereNull('worker_id')
                ->update(['worker_id' => $baris->worker_id]);
        }

        Schema::dropIfExists('daily_rule_worker');

        Schema::table('daily_rules', function (Blueprint $table) {
            $table->dropColumn('all_workers');
        });
    }
};

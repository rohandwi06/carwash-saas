<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan otomatis harian untuk potongan upah & kompensasi (permintaan owner
 * 11/10): owner menyetelnya sekali — "uang makan Rp 10.000 per hari", "potong
 * kasbon Andi Rp 5.000 per hari" — lalu sistem mencatatnya sendiri tiap hari
 * untuk pekerja yang MENGERJAKAN CUCIAN hari itu. Yang tidak masuk tidak kena.
 *
 * Aturannya hanya "resep". Yang dihitung laporan tetap baris biasa di
 * wage_adjustments / worker_compensations, dibuat DailyRuleService::sinkron()
 * setiap kali cucian dicatat, dikoreksi, atau dibatalkan. Karena itu semua
 * laporan, kartu upah, dan aplikasi Android membacanya tanpa perlu tahu soal
 * aturan — dan mengubah aturan tidak pernah mengubah hari yang sudah lewat.
 *
 *  daily_rules        resepnya. worker_id kosong = semua pekerja. start_date
 *                     = hari aturan dibuat / dinyalakan lagi: tidak berlaku
 *                     mundur.
 *  rule_id            penanda di barisnya: "ini dibuat aturan nomor sekian".
 *                     Indeks unik (rule_id, worker_id, date) menjaga satu
 *                     aturan hanya mencatat sekali per pekerja per hari walau
 *                     dua kasir menyimpan cucian bersamaan. Baris manual
 *                     (rule_id kosong) tidak terikat indeks itu.
 *  daily_rule_skips   owner menghapus catatan otomatis satu hari ("hari ini
 *                     Andi tidak usah dipotong") — dicatat di sini supaya
 *                     cucian berikutnya tidak membuatnya lagi.
 *
 * rule_id dan tabel skips sengaja TANPA foreign key: migrasi ini juga dijalankan
 * tangan sebagai SQL di hosting tanpa Terminal, dan menambah constraint tidak
 * bisa dibuat aman-diulang. Kebersihannya dijaga DailyRuleService::hapus().
 */
return new class extends Migration
{
    private const BERBARIS = ['wage_adjustments', 'worker_compensations'];

    public function up(): void
    {
        Schema::create('daily_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                 // potongan | kompensasi
            // Pekerja dihapus -> aturan khusus untuknya ikut hilang.
            $table->foreignId('worker_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');          // per orang per hari
            $table->string('reason', 160);
            $table->boolean('is_active')->default(true);
            $table->date('start_date');
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_rule_skips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('worker_id');
            $table->date('date');
            $table->timestamps();

            $table->index(['rule_id', 'date']);
        });

        foreach (self::BERBARIS as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->unsignedBigInteger('rule_id')->nullable()->after('created_by');
                $table->unique(['rule_id', 'worker_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::BERBARIS as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropUnique(['rule_id', 'worker_id', 'date']);
                $table->dropColumn('rule_id');
            });
        }

        Schema::dropIfExists('daily_rule_skips');
        Schema::dropIfExists('daily_rules');
    }
};

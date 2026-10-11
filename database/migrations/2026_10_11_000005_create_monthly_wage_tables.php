<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara upah ketiga: UPAH PER BULAN (permintaan owner 11/10), di samping
 * persenan dan upah per kendaraan.
 *
 * Keputusan owner:
 *  - berlaku untuk semua pekerja (satu pilihan untuk seluruh cucian), dengan
 *    nominal gaji masing-masing;
 *  - gaji tetap berapa pun hari masuknya; di laporan harian dibebankan
 *    gaji / jumlah hari bulan itu, supaya laba harian tetap masuk akal;
 *  - potongan upah mengurangi gaji bulan berjalan.
 *
 * Upah per kendaraan dibekukan per transaksi (transaction_worker.wage_share),
 * jadi laporan lama tidak bergerak saat pengaturan diubah. Gaji bulanan tidak
 * menempel ke transaksi, jadi sejarahnya dijaga dengan TANGGAL BERLAKU:
 *
 *  monthly_wage_periods  kapan saja mode bulanan berlaku. end_date kosong =
 *                        masih berlaku. Hari di luar semua periode tidak
 *                        dibebani gaji, walau pekerjanya punya angka gaji.
 *  worker_salaries       gaji seorang pekerja mulai start_date. Gaji pada
 *                        suatu tanggal = baris terakhir yang start_date-nya
 *                        tidak melewati tanggal itu. Menaikkan gaji hari ini
 *                        menambah baris baru; hari-hari sebelumnya tetap
 *                        memakai angka lamanya.
 *
 * Tanpa cron dan tanpa baris per hari: beban harian dihitung saat dibaca
 * (SalaryService::harianRentang) dari dua tabel ini saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_wage_periods', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('worker_salaries', function (Blueprint $table) {
            $table->id();
            // Pekerja dihapus -> gajinya ikut hilang, sama seperti upah per
            // cuciannya (transaction_worker juga cascade).
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');          // per bulan
            $table->date('start_date');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['worker_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_salaries');
        Schema::dropIfExists('monthly_wage_periods');
    }
};

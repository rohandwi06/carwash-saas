<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian upah pekerja oleh owner — dipakai untuk memotong upah pekerja
 * yang melanggar aturan, atau membetulkan angka upah yang dirasa keliru.
 *
 * Dua jenis, sengaja disatukan dalam satu tabel supaya seluruh perubahan upah
 * satu orang pada satu hari terbaca dari satu tempat:
 *
 *  - 'potongan' : mengurangi upah. Boleh lebih dari satu per hari (mis. telat
 *                 + merusak alat). 'amount' adalah nominal yang dipotong.
 *  - 'timpa'    : mengganti angka upah hasil hitungan dengan nominal lain.
 *                 'amount' adalah upah barunya, BUKAN selisih. Cukup satu per
 *                 pekerja per hari — yang terbaru menang.
 *
 * Kalau keduanya ada untuk pekerja+tanggal yang sama, urutannya: 'timpa'
 * menetapkan angka dasarnya dulu, lalu 'potongan' menguranginya. Hasil akhir
 * tidak pernah minus. Lihat WageService::terapkanPenyesuaian().
 *
 * Uang yang tidak jadi dibayarkan SELALU menaikkan laba bersih, karena laba
 * dihitung "... - upah - pengeluaran" (BookkeepingService::dailyRecap) dan
 * upah yang dilaporkan sudah angka setelah penyesuaian. Tidak ada opsi untuk
 * mengecualikannya: uangnya memang tidak keluar dari kas.
 *
 * worker_name disalin seperti di worker_deposits — kalau pekerjanya dihapus,
 * catatan potongannya tetap bisa dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('worker_name');
            $table->string('type', 16);                 // potongan | timpa
            $table->unsignedInteger('amount');
            $table->string('reason', 160)->nullable();
            $table->date('date');
            // Hanya terisi untuk penyesuaian hari ini — memanggil
            // CashBookService::current() untuk tanggal lampau akan membuka
            // kembali buku yang sudah ditutup. Pola yang sama dipakai
            // ExpenseController::store().
            $table->foreignId('book_id')->nullable()->constrained('cash_books')->nullOnDelete();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['date', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_adjustments');
    }
};

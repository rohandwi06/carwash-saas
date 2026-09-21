<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit pekerja ke kas: pekerja menyetor uang, owner yang mencatatnya.
 *
 * worker_name DISALIN (bukan cuma worker_id) supaya catatan uang tetap
 * terbaca kalau pekerjanya kelak dihapus dari daftar. Tanpa itu, riwayat
 * setoran kehilangan pemiliknya — padahal justru itu inti fiturnya.
 * Karena itu pula relasinya nullOnDelete, bukan cascade: hapus pekerja
 * TIDAK boleh ikut menghapus jejak setorannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('worker_name');
            $table->unsignedInteger('amount');
            $table->string('note')->nullable();
            $table->date('date')->index();
            $table->string('created_by')->nullable();   // owner yang menginput
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_deposits');
    }
};

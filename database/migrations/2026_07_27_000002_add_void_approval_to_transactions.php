<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasir tidak lagi bisa membatalkan transaksi sendiri — ia hanya MENGAJUKAN,
 * lalu owner yang menyetujui atau menolak.
 *
 * Kolom voided_at/void_reason/voided_by yang lama tetap dipakai sebagai
 * keputusan AKHIR (batal beneran). Kolom di bawah ini menyimpan pengajuan
 * yang belum diputuskan, plus jejak penolakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('void_requested_at')->nullable()->after('voided_by');
            $table->string('void_requested_by', 255)->nullable()->after('void_requested_at');
            $table->string('void_request_reason', 120)->nullable()->after('void_requested_by');

            $table->timestamp('void_rejected_at')->nullable()->after('void_request_reason');
            $table->string('void_rejected_by', 255)->nullable()->after('void_rejected_at');
            $table->string('void_reject_reason', 120)->nullable()->after('void_rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'void_requested_at', 'void_requested_by', 'void_request_reason',
                'void_rejected_at', 'void_rejected_by', 'void_reject_reason',
            ]);
        });
    }
};

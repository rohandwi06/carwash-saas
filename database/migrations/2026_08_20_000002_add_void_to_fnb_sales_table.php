<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan penjualan makanan & minuman, mengikuti pola yang sudah dipakai
 * transaksi cuci (lihat add_void_to_transactions_table &
 * add_void_approval_to_transactions): kasir MENGAJUKAN, owner memutuskan.
 *
 * Barisnya tidak pernah dihapus, hanya ditandai. Alasannya sama dengan
 * transaksi cuci: rekap uang otomatis mengabaikannya lewat FnbSale::valid(),
 * tapi jejaknya tetap ada supaya laporan lama masih bisa ditelusuri ulang —
 * dan supaya penjualan yang hilang dari rekap selalu punya alasan tertulis
 * beserta nama orang yang membatalkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('date');
            $table->string('void_reason', 120)->nullable()->after('voided_at');
            $table->string('voided_by', 255)->nullable()->after('void_reason');

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
        Schema::table('fnb_sales', function (Blueprint $table) {
            $table->dropColumn([
                'voided_at', 'void_reason', 'voided_by',
                'void_requested_at', 'void_requested_by', 'void_request_reason',
                'void_rejected_at', 'void_rejected_by', 'void_reject_reason',
            ]);
        });
    }
};

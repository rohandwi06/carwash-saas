<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak koreksi transaksi cuci.
 *
 * Sampai sekarang satu-satunya cara membetulkan transaksi yang salah input
 * adalah membatalkannya (void) lalu mencatat ulang — dan void punya jejak
 * lengkap: kapan, oleh siapa, alasannya. Koreksi langsung lewat menu
 * Pembukuan tidak boleh lebih longgar dari itu, justru karena dampaknya
 * lebih halus: void terlihat jelas sebagai baris tercoret di riwayat,
 * sedangkan koreksi mengubah angka omzet tanpa meninggalkan apa pun di layar.
 *
 * edit_count disimpan terpisah dari edited_at karena yang menarik saat
 * memeriksa selisih uang bukan cuma "pernah dikoreksi", tapi "dikoreksi
 * berapa kali" — transaksi yang diutak-atik tiga kali layak dilihat lebih
 * teliti daripada yang sekali diperbaiki typo platnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('void_reject_reason');
            $table->string('edited_by')->nullable()->after('edited_at');
            // Sepanjang void_reason & void_request_reason, supaya alasan
            // koreksi tidak lebih pendek daripada alasan pembatalan.
            $table->string('edit_reason', 120)->nullable()->after('edited_by');
            $table->unsignedInteger('edit_count')->default(0)->after('edit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'edited_by', 'edit_reason', 'edit_count']);
        });
    }
};

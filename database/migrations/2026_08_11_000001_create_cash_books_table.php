<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Buku kas" — pengganti shift sebagai satuan laporan & setoran.
 *
 * Shift itu jadwal JAM (dipakai ShiftGuard untuk mengunci aplikasi kasir di
 * luar jam operasional) dan tetap dipertahankan untuk itu. Tapi jam bukan
 * satuan yang cocok untuk "sudah disetor belum" — satu shift bisa berisi
 * beberapa kali setoran, atau owner mengubah jam shift dan riwayat lama ikut
 * berantakan. Buku kas satuannya PERISTIWA: dibuka, diisi transaksi, ditutup
 * dan diajukan setoran, disetujui owner. Tidak terikat jam sama sekali.
 *
 * Alur status: open -> pending -> deposited
 *                            \-> rejected (owner menolak; TIDAK dibuka lagi
 *                                otomatis — lihat catatan panjang di
 *                                CashBookService::rejectDeposit)
 *
 * number berurutan PER TANGGAL, mulai dari 1 — bukan id global — supaya
 * label "Buku 1", "Buku 2" masuk akal dibaca kasir/owner tanpa embel-embel
 * tanggal di tiap penyebutan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_books', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->unsignedTinyInteger('number');

            $table->string('status')->default('open');
            // open|pending|deposited|rejected — bukan enum kolom supaya nilai
            // baru kelak tidak perlu migrasi skema, cukup ditambah di kode.

            $table->dateTime('opened_at');
            $table->string('opened_by')->nullable();

            // Diisi saat kasir menekan "Simpan & Ajukan Setoran".
            $table->dateTime('requested_at')->nullable();
            $table->string('requested_by')->nullable();

            // Uang CASH yang harus disetor: omzet cash (cuci+F&B) dikurangi
            // pengeluaran yang dicatat di buku ini. TF tidak dihitung — uang
            // itu sudah otomatis masuk rekening, tidak ada fisik untuk
            // diserahkan. Dihitung SEKALI saat diajukan lalu dibekukan di
            // sini (bukan dihitung ulang saat dibaca), supaya angka yang
            // sudah disepakati kasir & owner tidak diam-diam berubah kalau
            // ada koreksi data di kemudian hari.
            $table->unsignedInteger('amount')->nullable();

            $table->dateTime('approved_at')->nullable();
            $table->string('approved_by')->nullable();

            $table->dateTime('rejected_at')->nullable();
            $table->string('rejected_by')->nullable();
            $table->string('reject_reason')->nullable();

            $table->timestamps();

            $table->unique(['date', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_books');
    }
};

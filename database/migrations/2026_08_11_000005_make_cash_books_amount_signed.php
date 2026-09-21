<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * cash_books.amount HARUS boleh negatif.
 *
 * Rumusnya (CashBookService::cashAmount): omzet cash dikurangi pengeluaran
 * di buku itu. Kalau kasir mencatat pengeluaran cash sebelum ada pemasukan
 * cash sama sekali di buku baru (mis. beli sabun pakai uang kas duluan),
 * hasilnya negatif — itu bukan kesalahan, artinya OWNER yang berhutang ke
 * kasir, bukan kasir yang menyetor. Kolom unsignedInteger di migrasi
 * pembuatan tabel bertentangan dengan aturan ini dan membuat "Simpan &
 * Ajukan Setoran" gagal dengan error database, ditemukan saat pengujian.
 */
return new class extends Migration
{
    // SQL mentah, bukan Schema::change(): proyek ini tidak memasang
    // doctrine/dbal (dicek di vendor/ sebelum menulis ini), yang biasanya
    // dibutuhkan Schema::change() untuk mengubah tipe kolom.
    //
    // SQLite di-skip: UNSIGNED di sana cuma advisory, tidak pernah benar-benar
    // ditegakkan (nilai negatif sudah bisa tersimpan tanpa migrasi ini) — jadi
    // ALTER MODIFY (sintaks MySQL) tidak relevan sekaligus tidak dibutuhkan.
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE cash_books MODIFY amount INT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE cash_books MODIFY amount INT UNSIGNED NULL');
    }
};

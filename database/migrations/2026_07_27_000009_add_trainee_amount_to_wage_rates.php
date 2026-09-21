<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upah karyawan training jadi PER JENIS KENDARAAN + LAYANAN.
 *
 * Sebelumnya satu angka untuk semua cucian (settings.trainee_wage). Itu keliru:
 * mencuci motor dan mencuci mobil ekstra jelas beda beban, jadi porsi
 * trainingnya juga harus bisa beda. Kolomnya ditaruh bersebelahan dengan
 * 'amount' di wage_rates karena keduanya menjawab pertanyaan yang sama —
 * "cucian ini menghasilkan upah berapa, untuk siapa" — dan dengan begitu
 * menambah jenis kendaraan baru otomatis menyediakan tempat untuk keduanya.
 *
 * Nilai lama disalin ke SEMUA baris supaya owner tidak mulai dari nol, lalu
 * settings.trainee_wage dihapus. Baris itu tidak lagi dibaca siapa pun;
 * membiarkannya hanya akan menyesatkan orang yang membacanya nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wage_rates', function (Blueprint $table) {
            $table->unsignedInteger('trainee_amount')->default(0)->after('amount');
        });

        $lama = (int) (DB::table('settings')->where('key', 'trainee_wage')->value('value') ?? 5000);

        // Tidak melebihi jatah barisnya sendiri: nominal yang lebih besar dari
        // upah cucian itu tetap akan dipotong saat dihitung, jadi menyimpannya
        // apa adanya hanya membuat angka di layar berbeda dari yang dibayarkan.
        //
        // CASE, bukan LEAST(): LEAST tidak ada di SQLite, sehingga migrasi ini
        // menggagalkan SELURUH Feature test yang pakai RefreshDatabase
        // (:memory:). CASE dimengerti MySQL maupun SQLite dengan hasil yang
        // persis sama, jadi tidak perlu bercabang per driver.
        DB::table('wage_rates')->update([
            'trainee_amount' => DB::raw('CASE WHEN amount < '.$lama.' THEN amount ELSE '.$lama.' END'),
        ]);

        DB::table('settings')->where('key', 'trainee_wage')->delete();
    }

    public function down(): void
    {
        Schema::table('wage_rates', function (Blueprint $table) {
            $table->dropColumn('trainee_amount');
        });
    }
};

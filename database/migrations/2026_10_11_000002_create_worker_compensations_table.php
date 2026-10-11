<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kompensasi karyawan — "sangu" dari owner di luar upah cucian: uang makan,
 * uang bensin, dan sejenisnya (permintaan owner 11/10).
 *
 * Sengaja tabel sendiri, BUKAN jenis ketiga di wage_adjustments: semua
 * pembaca tabel itu (WageService, layar Upah & potongan, aplikasi Android)
 * menganggap apa pun yang bukan 'timpa' sebagai potongan, jadi kompensasi
 * yang dititipkan di sana akan MENGURANGI upah di layar yang belum diperbarui.
 *
 * Aturannya (keputusan owner 11/10):
 *  - memotong laba bersih, di barisnya sendiri — tidak dicampur ke upah;
 *  - uangnya dari owner, bukan dari laci: TIDAK mengubah omzet, setoran cash,
 *    maupun buku kas. Karena itu tidak ada book_id.
 *
 * worker_id boleh kosong dan worker_name disalin: kalau pekerjanya dihapus,
 * uangnya tetap sudah keluar dan catatannya harus tetap memotong laba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('worker_name');
            $table->unsignedInteger('amount');
            $table->string('reason', 160)->nullable();
            $table->date('date');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_compensations');
    }
};

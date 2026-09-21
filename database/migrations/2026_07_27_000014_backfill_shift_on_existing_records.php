<?php

use App\Models\Shift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengisi shift pada catatan lama yang terlanjur kosong.
 *
 * Transaksi yang dibuat sebelum fitur shift ada — dan yang dibuat saat jam
 * aplikasi masih UTC — punya shift_name kosong, sehingga semuanya menumpuk di
 * kelompok "Di luar shift" pada Rekap Hari Ini. Padahal jamnya jelas jatuh di
 * salah satu shift yang sudah diatur owner.
 *
 * Baru bisa dikerjakan SETELAH migrasi 2026_07_27_000013 membetulkan jam ke
 * WIB. Kalau dijalankan saat jam masih UTC, cucian sore hari akan dicocokkan
 * memakai jam pagi dan masuk shift yang salah — itu sebabnya urutan kedua
 * migrasi ini penting.
 *
 * Hanya menyentuh baris yang shift-nya MASIH KOSONG. Baris yang sudah punya
 * shift dibiarkan apa adanya: nilainya dibubuhkan saat transaksi dibuat dan
 * merupakan catatan sejarah, bukan sesuatu yang boleh dihitung ulang.
 *
 * Waktu di luar semua shift tetap dibiarkan kosong — dipaksakan ke shift
 * terdekat justru membuat rekapnya berbohong.
 */
return new class extends Migration
{
    private const TABEL = ['transactions', 'fnb_sales', 'expenses'];

    public function up(): void
    {
        $shifts = Shift::where('is_active', true)->orderBy('start_time')->get();

        if ($shifts->isEmpty()) {
            return;   // belum ada shift: tidak ada yang bisa dicocokkan
        }

        foreach (self::TABEL as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'shift_name')) {
                continue;
            }

            DB::table($tabel)
                ->whereNull('shift_name')
                ->orderBy('id')
                ->chunkById(500, function ($baris) use ($tabel, $shifts) {
                    foreach ($baris as $row) {
                        $jam = substr((string) $row->created_at, 11, 5);   // "HH:MM"
                        if ($jam === '') {
                            continue;
                        }

                        $cocok = $shifts->first(fn (Shift $s) => $s->mencakup($jam));
                        if (! $cocok) {
                            continue;
                        }

                        DB::table($tabel)->where('id', $row->id)->update([
                            'shift_id'   => $cocok->id,
                            'shift_name' => $cocok->name,
                        ]);
                    }
                });
        }
    }

    /**
     * Tidak bisa dibalik dengan tepat: setelah terisi, tidak ada lagi penanda
     * mana yang hasil pengisian ini dan mana yang memang dibubuhkan saat
     * transaksi dibuat. Mengosongkan semuanya akan menghapus data yang benar.
     */
    public function down(): void
    {
        // sengaja dibiarkan kosong
    }
};

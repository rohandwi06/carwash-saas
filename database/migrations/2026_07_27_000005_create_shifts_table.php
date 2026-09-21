<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jam operasional naik kelas dari satu pasang jam di tabel settings
 * menjadi daftar shift yang bisa ditambah/ubah/hapus owner —
 * satu hari boleh punya berapa pun shift (mis. Pagi, Sore, Malam).
 *
 * Jam disimpan sebagai teks "HH:MM" (bukan kolom TIME) supaya bentuknya
 * persis sama dengan yang dikirim/diterima frontend, tanpa konversi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pindahkan jam yang sudah terlanjur diatur owner supaya tidak hilang.
        $ambil = fn (string $k) => DB::table('settings')->where('key', $k)->value('value');
        $jam   = fn (?string $v, string $default) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) === 1
            ? $v : $default;

        DB::table('shifts')->insert([
            'name'       => 'Shift Utama',
            'start_time' => $jam($ambil('shift_open'), '07:00'),
            'end_time'   => $jam($ambil('shift_close'), '21:00'),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // shift_enabled & shift_message tetap di settings (berlaku global).
        DB::table('settings')->whereIn('key', ['shift_open', 'shift_close'])->delete();
    }

    public function down(): void
    {
        // Kembalikan shift paling awal ke bentuk lama, supaya rollback tidak
        // meninggalkan aplikasi tanpa jam operasional sama sekali.
        $pertama = DB::table('shifts')->orderBy('start_time')->first();

        foreach ([
            'shift_open'  => $pertama->start_time ?? '07:00',
            'shift_close' => $pertama->end_time ?? '21:00',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        Schema::dropIfExists('shifts');
    }
};

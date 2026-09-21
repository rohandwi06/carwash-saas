<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login diganti dari PIN bersama ke akun per orang:
 * - username unik (dipakai login, bukan email)
 * - role: 'kasir' (dibuat owner lewat Pengaturan) — akun owner sendiri
 *   tetap hidup di .env (OWNER_USERNAME/OWNER_PASSWORD), bukan di tabel ini,
 *   supaya selalu ada jalan masuk owner meski tabel kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('name');
            $table->string('role')->default('kasir')->after('password');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role']);
            $table->string('email')->nullable(false)->change();
        });
    }
};

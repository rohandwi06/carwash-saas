<?php

/*
|-----------------------------------------------------------------------------
| Entry point untuk shared hosting cPanel (ArenHost).
|
| Tidak perlu disalin tangan: buat-paket.ps1 memasukkannya ke public.zip
| sebagai ~/public_html/index.php, dengan $app_base diganti sesuai
| -FolderApp. Nilai di bawah hanya bawaan.
|
| Bedanya dengan public/index.php bawaan: di cPanel isi folder public/ tinggal
| di public_html, sedangkan SISA proyek (vendor/, .env, storage/, app/) ada di
| ~/<folder aplikasi> — DI LUAR public_html, supaya .env dan pembukuan tidak pernah
| bisa diunduh lewat browser. Karena itu path-nya naik ke folder induk, bukan
| '__DIR__/../'.
|
|-----------------------------------------------------------------------------
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_base = __DIR__.'/../rapiin-app';

if (file_exists($maintenance = $app_base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_base.'/bootstrap/app.php';

// Folder publik TIDAK berada di dalam folder aplikasi di sini - isinya ada di
// public_html, tempat berkas ini sendiri berada. Tanpa baris ini public_path()
// menunjuk ke folder yang tidak pernah ada, dan tampilan kasir mati dengan
// "filemtime(): stat failed" saat menghitung penanda versi css/js
// (lihat resources/views/kasir/index.blade.php baris 7 & 71).
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());

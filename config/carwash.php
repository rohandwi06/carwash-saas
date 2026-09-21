<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi bisnis OTIN CARWASH
|--------------------------------------------------------------------------
| PERHATIAN: 'categories', 'services', dan 'default_wages' di bawah ini
| hanya NILAI AWAL saat aplikasi pertama dipasang — isinya sudah disalin ke
| database oleh migrasi 2026_07_19_000001_create_wash_catalog_tables.
|
| Sumber kebenaran sekarang ada di tabel wash_categories / wash_services /
| wash_prices / wage_rates, yang dikelola owner lewat layar Pengaturan.
| Mengubah angka di file ini TIDAK berpengaruh pada aplikasi yang sudah jalan.
*/

return [
    'categories' => [
        'motor'  => ['label' => 'Motor',        'price' => 15000],
        'kecil'  => ['label' => 'Mobil Kecil',  'price' => 35000],
        'sedang' => ['label' => 'Mobil',        'price' => 40000],
        'besar'  => [
            'label' => 'Mobil Ekstra',
            'price' => 30000,
            // Harga layanan khusus Mobil Ekstra (beda dari kecil/sedang yang pakai 'services.*.extra').
            'service_extras' => ['dalam' => 10000, 'hidro' => 50000],
        ],
    ],

    'services' => [
        'reguler' => ['label' => 'Cuci Reguler',    'extra' => 0],
        'dalam'   => ['label' => 'Cuci + Interior', 'extra' => 20000],
        'hidro'   => ['label' => 'Cuci Komplit',    'extra' => 50000],
    ],

    // Upah default per kendaraan (dioverride tabel wage_rates)
    'default_wages' => [
        'motor'  => 5000,
        'kecil'  => 10000,
        'sedang' => 10000,
        'besar'  => 12000,
    ],

    // Batas bawah agar sebuah kendaraan LAYAK DITAMPILKAN sebagai hasil.
    'search_threshold' => 0.45,

    // Batas agar hasil teratas dianggap BENAR-BENAR yang dimaksud, bukan
    // sekadar mirip. Di bawah angka ini AI ikut ditanya sebagai pendamping —
    // hasil lokalnya tetap ditampilkan.
    //
    // Angkanya dari pengukuran katalog nyata (98 kendaraan):
    //   tebakan benar  -> "mazdarx7" 2.00, "avanza" 1.46, "penter" 0.94
    //   tebakan meleset -> "masda 3" 0.75, "masda tiga" 0.52  (dua-duanya
    //                      mengunci kata "mazda" lalu menyodorkan Mazda 2,
    //                      padahal Mazda 3 memang tidak ada di katalog)
    // 0.9 memisahkan keduanya dengan jarak aman.
    'search_confident' => 0.9,

    // Akun OWNER hidup di .env (bukan database) supaya selalu bisa masuk
    // meski tabel users kosong. WAJIB diisi — kosong = owner tidak bisa login.
    // Akun kasir dibuat owner lewat menu Pengaturan (tersimpan di tabel users).
    'owner' => [
        'username' => env('OWNER_USERNAME', ''),
        'password' => env('OWNER_PASSWORD', ''),
    ],

    // Integrasi Gemini (untuk kendaraan yang tidak ada di database)
    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],
];

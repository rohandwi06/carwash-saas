<?php
// POST {email} — dipanggil aplikasi saat owner login: email -> server cucian.
require __DIR__ . '/inti.php';

$in = masukan();
batasi('temukan', 30);

$email = emailNormal($in['email'] ?? '');
if ($email === null) {
    jawab(422, ['ok' => false, 'pesan' => 'Tulis email yang benar.']);
}

$isi = bacaPeta()[$email] ?? null;
if ($isi === null) {
    jawab(404, ['ok' => false, 'pesan' => 'Email ini belum terdaftar. Owner perlu mengisi email di Pengaturan > Akun lewat web cucian.']);
}

jawab(200, ['ok' => true, 'url' => $isi['url'], 'nama' => $isi['nama'] ?? '']);

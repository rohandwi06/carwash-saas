<?php
// POST {email, server} — dipanggil server cucian saat owner mengisi email.
require __DIR__ . '/inti.php';

$in = masukan();
batasi('daftar', 20);

$email = emailNormal($in['email'] ?? '');
$server = trim((string) ($in['server'] ?? ''));
$bagian = parse_url($server);
if ($email === null) {
    jawab(422, ['ok' => false, 'pesan' => 'Email tidak sah.']);
}
$lokalUji = modeUji() && ($bagian['host'] ?? '') === '127.0.0.1';
if ($lokalUji) {
    $server = 'http://127.0.0.1' . (isset($bagian['port']) ? ':' . $bagian['port'] : '');
} elseif (($bagian['scheme'] ?? '') !== 'https' || empty($bagian['host']) || ! hostDiizinkan($bagian['host'])) {
    jawab(422, ['ok' => false, 'pesan' => 'Server ini tidak terdaftar sebagai cucian layanan ini.']);
} else {
    $server = 'https://' . strtolower($bagian['host']);
}

// Server cucian harus membenarkan bahwa email ini memang email ownernya.
$nama = konfirmasiServer($server, $email);
if ($nama === null) {
    jawab(422, ['ok' => false, 'pesan' => 'Server cucian tidak membenarkan email ini.']);
}

ubahPeta(function (array $peta) use ($email, $server, $nama): array {
    // Satu email owner per server: email lama server ini dilepas.
    foreach ($peta as $e => $isi) {
        if (($isi['url'] ?? '') === $server && $e !== $email) {
            unset($peta[$e]);
        }
    }
    $peta[$email] = ['url' => $server, 'nama' => $nama, 'waktu' => date('c')];
    return $peta;
});

jawab(200, ['ok' => true]);

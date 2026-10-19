<?php
/*
 * Pendaftaran cucian SEMENTARA (19/10): menyimpan calon klien ke
 * data/pendaftar.csv (tertutup dari luar) dan, bila diatur di pengaturan.php,
 * memberi tahu lewat email. Belum membuat server otomatis dan belum ada
 * pembayaran — lihat carwash-saas/docs/REGISTER.md.
 */

declare(strict_types=1);

// Waktu tercatat dalam WIB (server hosting memakai UTC).
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');

function jawab(int $kode, array $isi): never
{
    http_response_code($kode);
    echo json_encode($isi, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jawab(405, ['ok' => false, 'pesan' => 'Pakai POST.']);
}

$dir = __DIR__ . '/data';
if (! is_dir($dir)) {
    @mkdir($dir, 0700, true);
}

// Batas: 5 kiriman per IP per jam.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$batas = $dir . '/batas-' . md5($ip) . '-' . date('YmdH');
$n = (int) @file_get_contents($batas) + 1;
@file_put_contents($batas, (string) $n, LOCK_EX);
if ($n > 5) {
    jawab(429, ['ok' => false, 'pesan' => 'Terlalu banyak kiriman. Coba lagi nanti.']);
}
if (random_int(1, 30) === 1) {
    foreach (glob($dir . '/batas-*') ?: [] as $f) {
        if (filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($in)) {
    $in = $_POST;
}

// Kolom penjebak robot terisi = robot. Dijawab "berhasil" supaya tidak mencoba lagi.
if (trim((string) ($in['situs'] ?? '')) !== '') {
    jawab(200, ['ok' => true]);
}

$ambil = fn (string $k, int $maks) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? ''))), 0, $maks);
$data = [
    'waktu'   => date('Y-m-d H:i:s'),
    'cucian'  => $ambil('cucian', 80),
    'kota'    => $ambil('kota', 60),
    'mobil'   => $ambil('mobil', 30),
    'pemilik' => $ambil('pemilik', 80),
    'email'   => strtolower($ambil('email', 120)),
    'wa'      => $ambil('wa', 20),
    'catatan' => $ambil('catatan', 500),
];

foreach (['cucian' => 'Nama cucian', 'kota' => 'Kota', 'pemilik' => 'Nama pemilik', 'wa' => 'No. WhatsApp'] as $k => $nama) {
    if ($data[$k] === '') {
        jawab(422, ['ok' => false, 'pesan' => "{$nama} wajib diisi."]);
    }
}
if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    jawab(422, ['ok' => false, 'pesan' => 'Email belum benar.']);
}
if (strlen(preg_replace('/\D/', '', $data['wa'])) < 9) {
    jawab(422, ['ok' => false, 'pesan' => 'No. WhatsApp belum benar.']);
}

// CSV: aman dibuka di Excel. Sel yang diawali = + - @ diberi petik supaya
// tidak dijalankan sebagai rumus.
$sel = fn (string $s) => preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
$berkas = $dir . '/pendaftar.csv';
$baru = ! is_file($berkas);
$f = fopen($berkas, 'a');
flock($f, LOCK_EX);
if ($baru) {
    fputcsv($f, array_keys($data));
}
fputcsv($f, array_map($sel, array_values($data)));
flock($f, LOCK_UN);
fclose($f);

// Beri tahu lewat email bila diatur (pengaturan.php -> 'email_pemberitahuan').
$atur = is_file(__DIR__ . '/pengaturan.php') ? (require __DIR__ . '/pengaturan.php') : [];
$ke = trim((string) ($atur['email_pemberitahuan'] ?? ''));
if ($ke !== '' && filter_var($ke, FILTER_VALIDATE_EMAIL)) {
    $isi = "Pendaftar cucian baru:\n\n";
    foreach ($data as $k => $v) {
        $isi .= str_pad($k, 9) . ": {$v}\n";
    }
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    @mail($ke, 'Pendaftar baru: ' . $data['cucian'], $isi,
        "From: noreply@{$host}\r\nContent-Type: text/plain; charset=utf-8");
}

jawab(200, ['ok' => true]);

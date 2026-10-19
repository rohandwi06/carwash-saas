<?php
/*
 * Permintaan HAPUS AKUN & seluruh data cucian (wajib Play Store untuk
 * aplikasi yang punya akun). Disimpan ke data/permintaan.csv (tertutup dari
 * luar) dan diberitahukan lewat email (pengaturan.php). Penghapusannya
 * dikerjakan penyedia setelah dikonfirmasi lewat email owner — halaman ini
 * sengaja TIDAK menghapus apa pun sendiri, supaya orang lain yang tahu email
 * owner tidak bisa menghapus cucian orang.
 */

declare(strict_types=1);

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

// Kolom penjebak robot terisi = robot.
if (trim((string) ($in['situs'] ?? '')) !== '') {
    jawab(200, ['ok' => true]);
}

$ambil = fn (string $k, int $maks) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? ''))), 0, $maks);
$data = [
    'waktu'  => date('Y-m-d H:i:s'),
    'email'  => strtolower($ambil('email', 120)),
    'cucian' => $ambil('cucian', 80),
    'alasan' => $ambil('alasan', 500),
    'status' => 'menunggu konfirmasi',
];

if ($data['cucian'] === '') {
    jawab(422, ['ok' => false, 'pesan' => 'Nama cucian wajib diisi.']);
}
if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    jawab(422, ['ok' => false, 'pesan' => 'Email owner belum benar.']);
}
if (($in['paham'] ?? false) !== true && ($in['paham'] ?? '') !== 'on') {
    jawab(422, ['ok' => false, 'pesan' => 'Centang dulu pernyataan bahwa data akan terhapus permanen.']);
}

$sel = fn (string $s) => preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
$berkas = $dir . '/permintaan.csv';
$baru = ! is_file($berkas);
$f = fopen($berkas, 'a');
flock($f, LOCK_EX);
if ($baru) {
    fputcsv($f, array_keys($data));
}
fputcsv($f, array_map($sel, array_values($data)));
flock($f, LOCK_UN);
fclose($f);

$atur = is_file(__DIR__ . '/pengaturan.php') ? (require __DIR__ . '/pengaturan.php') : [];
$ke = trim((string) ($atur['email_pemberitahuan'] ?? ''));
if ($ke !== '' && filter_var($ke, FILTER_VALIDATE_EMAIL)) {
    $isi = "Permintaan HAPUS AKUN:\n\n";
    foreach ($data as $k => $v) {
        $isi .= str_pad($k, 7) . ": {$v}\n";
    }
    $isi .= "\nKonfirmasi dulu ke email owner di atas sebelum menghapus.\n";
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    @mail($ke, 'Hapus akun: ' . $data['cucian'], $isi,
        "From: noreply@{$host}\r\nContent-Type: text/plain; charset=utf-8");
}

jawab(200, ['ok' => true]);

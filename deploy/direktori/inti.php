<?php
/*
 * Buku alamat email owner -> server cucian, untuk login aplikasi Android
 * (permintaan owner 19/10). Diunggah ke byrapiin.my.id/direktori/.
 *
 *   POST daftar.php  {email, server}  — dipanggil server cucian saat owner
 *        mengisi/mengganti email. Pusat menelepon balik
 *        {server}/api/direktori/cek?email=... untuk memastikannya; tanpa
 *        rahasia apa pun di server cucian.
 *   POST temukan.php {email}          — dipanggil aplikasi: email -> {url, nama}.
 *
 * Data: data/peta.json (folder data/ ditutup .htaccess). Satu email owner per
 * server: email baru menggantikan email lama server yang sama.
 */

declare(strict_types=1);

// Waktu tercatat dalam WIB (server hosting memakai UTC).
date_default_timezone_set('Asia/Jakarta');

const DIREKTORI_DATA = __DIR__ . '/data';

/**
 * Mode uji di laptop: hanya bila variabel lingkungan DIREKTORI_UJI=1 (tidak
 * ada di hosting). Mengizinkan http://127.0.0.1:<port> sebagai server cucian.
 */
function modeUji(): bool
{
    return getenv('DIREKTORI_UJI') === '1';
}

/** Server yang boleh mendaftar: hanya domain milik layanan ini. */
function hostDiizinkan(string $host): bool
{
    $host = strtolower($host);
    $izin = require __DIR__ . '/izin.php';
    foreach ($izin as $pola) {
        $pola = strtolower($pola);
        if (str_starts_with($pola, '*.')) {
            if (str_ends_with($host, substr($pola, 1)) && strlen($host) > strlen($pola) - 1) {
                return true;
            }
        } elseif ($host === $pola) {
            return true;
        }
    }
    return false;
}

function jawab(int $kode, array $isi): never
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Badan permintaan JSON (atau form biasa). */
function masukan(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Allow-Methods: POST');
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jawab(405, ['ok' => false, 'pesan' => 'Pakai POST.']);
    }
    $json = json_decode((string) file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function emailNormal(mixed $email): ?string
{
    $e = strtolower(trim((string) $email));
    return filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 120 ? $e : null;
}

/** Batas sederhana per alamat IP: [$maks] permintaan per menit per jenis. */
function batasi(string $jenis, int $maks): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $dir = DIREKTORI_DATA . '/batas';
    if (! is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $berkas = $dir . '/' . $jenis . '-' . md5($ip) . '-' . date('YmdHi');
    $n = (int) @file_get_contents($berkas) + 1;
    @file_put_contents($berkas, (string) $n, LOCK_EX);
    if ($n > $maks) {
        jawab(429, ['ok' => false, 'pesan' => 'Terlalu banyak percobaan. Tunggu sebentar.']);
    }
    // Sapu berkas menit-menit lalu sesekali.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (filemtime($f) < time() - 300) {
                @unlink($f);
            }
        }
    }
}

/**
 * Baca-ubah-tulis peta.json di bawah kunci berkas, supaya dua pendaftaran
 * bersamaan tidak saling menimpa.
 *
 * @param  callable(array): array  $ubah
 */
function ubahPeta(callable $ubah): array
{
    if (! is_dir(DIREKTORI_DATA)) {
        @mkdir(DIREKTORI_DATA, 0700, true);
    }
    $f = fopen(DIREKTORI_DATA . '/peta.json', 'c+');
    flock($f, LOCK_EX);
    $isi = stream_get_contents($f);
    $peta = json_decode($isi ?: '{}', true) ?: [];
    $peta = $ubah($peta);
    ftruncate($f, 0);
    rewind($f);
    fwrite($f, json_encode($peta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($f);
    flock($f, LOCK_UN);
    fclose($f);
    return $peta;
}

function bacaPeta(): array
{
    $isi = @file_get_contents(DIREKTORI_DATA . '/peta.json');
    return json_decode($isi ?: '{}', true) ?: [];
}

/** Tanya server cucian: benarkah [$email] email owner-mu? -> nama usaha / null. */
function konfirmasiServer(string $server, string $email): ?string
{
    $url = rtrim($server, '/') . '/api/direktori/cek?email=' . rawurlencode($email);
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 10,
        'header' => "Accept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $isi = @file_get_contents($url, false, $ctx);
    $data = json_decode((string) $isi, true)['data'] ?? null;
    if (! is_array($data) || ($data['cocok'] ?? false) !== true) {
        return null;
    }
    return mb_substr(trim((string) ($data['nama'] ?? '')), 0, 80);
}

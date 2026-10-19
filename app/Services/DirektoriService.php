<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Buku alamat email -> server di byrapiin.my.id (permintaan owner 19/10):
 * aplikasi Android login dengan email owner, lalu tahu sendiri server
 * cucian mana yang dipakai. Lihat deploy/direktori/ untuk sisi pusatnya.
 *
 * Server cucian TIDAK memegang rahasia apa pun: pusat memastikan pendaftaran
 * ini sah dengan menelepon balik GET {server}/api/direktori/cek?email=...,
 * yang hanya menjawab "cocok / tidak" untuk email owner cucian ini.
 */
class DirektoriService
{
    /**
     * Daftarkan [$email] sebagai email owner [$server] (alamat cucian ini,
     * mis. https://kembar99-carwash.byrapiin.my.id).
     *
     * @return array{ok:bool, pesan:string}
     */
    public function daftar(string $email, string $server): array
    {
        $pusat = rtrim((string) config('carwash.direktori.url'), '/');
        if ($pusat === '') {
            return ['ok' => false, 'pesan' => 'Buku alamat belum diatur di server ini.'];
        }

        try {
            $r = Http::timeout(20)->connectTimeout(8)->acceptJson()
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->post("{$pusat}/daftar.php", ['email' => $email, 'server' => $server]);
        } catch (ConnectionException) {
            return ['ok' => false, 'pesan' => 'Buku alamat tidak bisa dihubungi. Coba simpan lagi nanti.'];
        }

        if ($r->successful() && $r->json('ok') === true) {
            return ['ok' => true, 'pesan' => 'Email terdaftar: aplikasi bisa login dengan email ini.'];
        }

        return ['ok' => false, 'pesan' => (string) ($r->json('pesan') ?: 'Buku alamat menolak pendaftaran ('.$r->status().').')];
    }
}

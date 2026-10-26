<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LanggananService;
use App\Services\PaketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LanggananController extends Controller
{
    public function __construct(private LanggananService $langganan, private PaketService $paket) {}

    /**
     * Status untuk aplikasi & web: pita "belum aktif" (atau pengingat tujuh
     * hari terakhir, khusus owner), dan paket + modul yang menentukan menu
     * mana yang tampil. Modul yang tidak ada di `modul` (atau server lama
     * tanpa kunci ini) dianggap nyala.
     */
    public function show(Request $request): JsonResponse
    {
        $status = $this->langganan->status();
        $owner = $request->attributes->get('auth_role') === 'owner';

        return response()->json(['data' => $status + [
            'pesan' => $status['aktif'] ? null : ($owner ? LanggananService::PESAN_OWNER : LanggananService::PESAN_KASIR),
            'pengingat' => $owner ? $this->langganan->pengingat() : null,
        ] + $this->paket->status()]);
    }

    /**
     * Dipanggil PUSAT pendaftaran (byrapiin.my.id) setelah pembayaran lunas.
     *
     * Badan JSON {pesanan, hari, waktu, paket?} ditandatangani HMAC-SHA256
     * dengan LANGGANAN_KUNCI (sama di pusat & server ini) di header X-Tanda.
     * Tanpa kunci di .env, rute ini pura-pura tidak ada. `pesanan` (nomor
     * order gateway) dicatat supaya notifikasi yang terkirim dua kali tidak
     * menambah masa aktif dua kali. `paket` (kasir|usaha|juragan) ikut
     * diganti bila dikirim; hari 0 + paket = ganti paket di tengah masa aktif.
     */
    public function perpanjang(Request $request): JsonResponse
    {
        $kunci = (string) config('carwash.langganan.kunci');
        if ($kunci === '') {
            abort(404);
        }

        $badan = $request->getContent();
        $tanda = (string) $request->header('X-Tanda');
        if ($tanda === '' || ! hash_equals(hash_hmac('sha256', $badan, $kunci), $tanda)) {
            return response()->json(['message' => 'Tanda tangan tidak sah.'], 403);
        }

        $isi = json_decode($badan, true);
        $pesanan = is_array($isi) ? trim((string) ($isi['pesanan'] ?? '')) : '';
        $hari = is_array($isi) ? (int) ($isi['hari'] ?? 0) : 0;
        $waktu = is_array($isi) ? (int) ($isi['waktu'] ?? 0) : 0;
        $paket = is_array($isi) && isset($isi['paket']) ? (string) $isi['paket'] : null;

        if ($pesanan === '' || strlen($pesanan) > 80 || abs(time() - $waktu) > 600) {
            return response()->json(['message' => 'Permintaan tidak sah atau kedaluwarsa.'], 422);
        }
        // Diperiksa sebelum apa pun diubah: paket salah ketik tidak boleh
        // sempat menambah masa aktif lalu gagal di tengah.
        if (($paket !== null && ! in_array($paket, $this->paket->urutan(), true)) || ($hari < 1 && $paket === null)) {
            return response()->json(['message' => 'Paket atau jumlah hari tidak sah.'], 422);
        }

        $tercatat = json_decode((string) Setting::get('langganan_pesanan'), true) ?: [];
        if (in_array($pesanan, $tercatat, true)) {
            return response()->json(['data' => $this->langganan->status() + $this->paket->status() + ['ulang' => true]]);
        }

        if ($hari > 0) {
            $this->langganan->perpanjang($hari);
        }
        if ($paket !== null) {
            $this->paket->ganti($paket);
        }
        $tercatat[] = $pesanan;
        Setting::put('langganan_pesanan', json_encode(array_slice($tercatat, -50)));

        return response()->json(['data' => $this->langganan->status() + $this->paket->status() + ['ulang' => false]]);
    }
}

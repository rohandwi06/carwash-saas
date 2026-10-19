<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LanggananService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LanggananController extends Controller
{
    public function __construct(private LanggananService $langganan) {}

    /** Status untuk aplikasi & web: menampilkan pita "belum aktif". */
    public function show(Request $request): JsonResponse
    {
        $status = $this->langganan->status();
        $owner = $request->attributes->get('auth_role') === 'owner';

        return response()->json(['data' => $status + [
            'pesan' => $status['aktif'] ? null : ($owner ? LanggananService::PESAN_OWNER : LanggananService::PESAN_KASIR),
        ]]);
    }

    /**
     * Dipanggil PUSAT pendaftaran (byrapiin.my.id) setelah pembayaran lunas.
     *
     * Badan JSON {pesanan, hari, waktu} ditandatangani HMAC-SHA256 dengan
     * LANGGANAN_KUNCI (sama di pusat & server ini) di header X-Tanda. Tanpa
     * kunci di .env, rute ini pura-pura tidak ada. `pesanan` (nomor order
     * gateway) dicatat supaya notifikasi yang terkirim dua kali tidak
     * menambah masa aktif dua kali.
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

        if ($pesanan === '' || strlen($pesanan) > 80 || abs(time() - $waktu) > 600) {
            return response()->json(['message' => 'Permintaan tidak sah atau kedaluwarsa.'], 422);
        }

        $tercatat = json_decode((string) Setting::get('langganan_pesanan'), true) ?: [];
        if (in_array($pesanan, $tercatat, true)) {
            return response()->json(['data' => $this->langganan->status() + ['ulang' => true]]);
        }

        $this->langganan->perpanjang($hari);
        $tercatat[] = $pesanan;
        Setting::put('langganan_pesanan', json_encode(array_slice($tercatat, -50)));

        return response()->json(['data' => $this->langganan->status() + ['ulang' => false]]);
    }
}

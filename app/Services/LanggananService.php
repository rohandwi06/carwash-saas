<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Langganan cucian yang mendaftar sendiri (keputusan Rohan 19/10): akun &
 * server langsung jadi, owner boleh MENGATUR (harga, pekerja, kasir, menu),
 * tapi belum boleh MENCATAT uang (cucian, F&B, pengeluaran) sebelum membayar.
 *
 * Satu setelan, `langganan_sampai`:
 *   - tidak ada        -> cucian lama yang ditagih manual: tidak pernah dikunci.
 *   - '' (kosong)      -> baru daftar, belum bayar: terkunci.
 *   - 'YYYY-MM-DD'     -> aktif sampai akhir tanggal itu (zona waktu aplikasi).
 *
 * Pesan kunci sengaja netral: tanpa harga dan tanpa tautan bayar (aturan
 * Play Store). Tautan pembayaran dikirim lewat email oleh pusat pendaftaran.
 */
class LanggananService
{
    public const KEY = 'langganan_sampai';

    public const PESAN_OWNER = 'Langganan cucian ini belum aktif. Pengaturan tetap bisa diubah; '
        . 'informasi aktivasi sudah dikirim ke email owner.';

    public const PESAN_KASIR = 'Langganan cucian ini belum aktif. Hubungi owner cucian.';

    /** @return array{wajib: bool, aktif: bool, sampai: ?string, sisa_hari: ?int} */
    public function status(): array
    {
        $isi = Setting::get(self::KEY);
        if ($isi === null) {
            return ['wajib' => false, 'aktif' => true, 'sampai' => null, 'sisa_hari' => null];
        }

        $sampai = $this->tanggal($isi);
        if (! $sampai) {
            return ['wajib' => true, 'aktif' => false, 'sampai' => null, 'sisa_hari' => null];
        }

        $hariIni = CarbonImmutable::today();
        $sisa = (int) $hariIni->diffInDays($sampai, false);

        return [
            'wajib' => true,
            'aktif' => $sisa >= 0,
            'sampai' => $sampai->toDateString(),
            'sisa_hari' => max($sisa, 0),
        ];
    }

    public function aktif(): bool
    {
        return $this->status()['aktif'];
    }

    /** Server baru dari pendaftaran mandiri: terkunci sampai dibayar. */
    public function mulaiTerkunci(): void
    {
        Setting::put(self::KEY, '');
    }

    /**
     * Tambah masa aktif. Dihitung dari hari ini kalau sudah lewat/belum
     * pernah aktif, atau disambung dari tanggal akhir kalau masih berjalan —
     * membayar lebih awal tidak menghanguskan sisa hari.
     */
    public function perpanjang(int $hari): string
    {
        if ($hari < 1 || $hari > 3660) {
            throw new \InvalidArgumentException('Jumlah hari tidak masuk akal.');
        }

        $hariIni = CarbonImmutable::today();
        $lama = $this->tanggal(Setting::get(self::KEY));
        $dasar = $lama && $lama->greaterThanOrEqualTo($hariIni) ? $lama : $hariIni->subDay();
        $baru = $dasar->addDays($hari)->toDateString();

        Setting::put(self::KEY, $baru);

        return $baru;
    }

    /** Cucian kembali ke tagihan manual: kunci langganan dilepas. */
    public function lepas(): void
    {
        Setting::where('key', self::KEY)->delete();
    }

    private function tanggal(?string $isi): ?CarbonImmutable
    {
        $isi = trim((string) $isi);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $isi)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $isi);
        } catch (\Throwable) {
            return null;
        }
    }
}

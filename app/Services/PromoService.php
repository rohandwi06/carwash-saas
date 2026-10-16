<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;

/**
 * Promo pelanggan setia (permintaan owner 16/10).
 *
 * Aturannya satu untuk seluruh cucian, diatur owner di Pengaturan -> Promo:
 * setiap kedatangan ke-N (mis. ke-10) berhak cuci gratis, potongan persen,
 * atau potongan rupiah. Potongannya hanya untuk harga LAYANAN — add-on dan
 * makanan/minuman tetap dibayar.
 *
 * Pelanggan dikenali dari plat ATAU nomor telepon: transaksi lama yang platnya
 * sama atau nomornya sama dihitung sebagai kedatangan orang yang sama. Tidak
 * ada daftar pelanggan; hitungannya selalu dibaca ulang dari transaksi.
 *
 * Hak promo seperti kartu stempel: hak = (kedatangan ini / N, dibulatkan ke
 * bawah) - promo yang sudah dipakai. Kasir yang lupa menekan "Pakai promo" di
 * kedatangan ke-10 tidak menghanguskan haknya — di kedatangan ke-11 masih ada.
 *
 * Yang dihitung hanya transaksi SAH sejak promo dinyalakan. Tanpa batas ini,
 * pelanggan lama OTIN dengan 30 kedatangan langsung punya tiga cuci gratis
 * terutang begitu fitur ini dinyalakan.
 */
class PromoService
{
    public const JENIS = ['gratis', 'persen', 'rupiah'];

    public const UPAH = ['penuh', 'ikut'];

    private const KUNCI = [
        'aktif'  => 'promo_active',
        'jenis'  => 'promo_type',
        'nilai'  => 'promo_value',
        'setiap' => 'promo_every',
        'upah'   => 'promo_wage',
        'mulai'  => 'promo_start',
    ];

    /** @return array{aktif:bool, jenis:string, nilai:int, setiap:int, upah:string, mulai:?string} */
    public function settings(): array
    {
        $s = Setting::whereIn('key', array_values(self::KUNCI))->pluck('value', 'key');
        $jenis = $s[self::KUNCI['jenis']] ?? null;
        $upah = $s[self::KUNCI['upah']] ?? null;

        return [
            'aktif'  => ($s[self::KUNCI['aktif']] ?? '0') === '1',
            'jenis'  => in_array($jenis, self::JENIS, true) ? $jenis : 'gratis',
            'nilai'  => max(0, (int) ($s[self::KUNCI['nilai']] ?? 0)),
            'setiap' => max(2, (int) ($s[self::KUNCI['setiap']] ?? 10)),
            'upah'   => in_array($upah, self::UPAH, true) ? $upah : 'penuh',
            'mulai'  => $s[self::KUNCI['mulai']] ?? null,
        ];
    }

    /**
     * Simpan pengaturan. Menyalakan promo (dari mati) memulai hitungan dari
     * HARI INI; mematikan lalu menyalakan lagi berarti mulai dari nol lagi.
     *
     * @param  array{aktif:bool, jenis:string, nilai?:int, setiap:int, upah:string}  $data
     */
    public function simpan(array $data): array
    {
        $lama = $this->settings();

        Setting::put(self::KUNCI['aktif'], $data['aktif'] ? '1' : '0');
        Setting::put(self::KUNCI['jenis'], $data['jenis']);
        Setting::put(self::KUNCI['nilai'], (string) ($data['jenis'] === 'gratis' ? 0 : (int) ($data['nilai'] ?? 0)));
        Setting::put(self::KUNCI['setiap'], (string) $data['setiap']);
        Setting::put(self::KUNCI['upah'], $data['upah']);
        if ($data['aktif'] && (! $lama['aktif'] || $lama['mulai'] === null)) {
            Setting::put(self::KUNCI['mulai'], now()->toDateString());
        }

        return $this->settings();
    }

    /** Aturan potongan yang berlaku sekarang, dalam bentuk yang disalin ke transaksi. */
    public function aturan(): string
    {
        $s = $this->settings();

        return $s['jenis'] === 'gratis' ? 'gratis' : $s['jenis'].':'.$s['nilai'];
    }

    /** Rupiah yang dipotong dari harga layanan menurut sebuah aturan. */
    public static function potongan(?string $aturan, int $hargaLayanan): int
    {
        $harga = max(0, $hargaLayanan);
        if ($aturan === null || $aturan === '') {
            return 0;
        }
        if ($aturan === 'gratis') {
            return $harga;
        }
        [$jenis, $nilai] = array_pad(explode(':', $aturan, 2), 2, '0');
        $nilai = max(0, (int) $nilai);

        return match ($jenis) {
            'persen' => min($harga, intdiv($harga * min(100, $nilai), 100)),
            'rupiah' => min($harga, $nilai),
            default  => 0,
        };
    }

    /** Teks singkat untuk layar & resi: "Cuci gratis", "Potongan 20%", "Potongan Rp 5.000". */
    public static function label(?string $aturan): string
    {
        if ($aturan === 'gratis') {
            return 'Cuci gratis';
        }
        [$jenis, $nilai] = array_pad(explode(':', (string) $aturan, 2), 2, '0');

        return match ($jenis) {
            'persen' => 'Potongan '.(int) $nilai.'%',
            'rupiah' => 'Potongan Rp '.number_format((int) $nilai, 0, ',', '.'),
            default  => 'Promo',
        };
    }

    /** "b 1234 xy" dan "B1234XY" adalah plat yang sama. */
    public static function kunciPlat(?string $plat): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $plat));
    }

    /** "0812-3456 7890", "+62 812 3456 7890", dan "6281234567890" adalah nomor yang sama. */
    public static function kunciTelepon(?string $telepon): string
    {
        $d = (string) preg_replace('/\D/', '', (string) $telepon);
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        } elseif (str_starts_with($d, '8')) {
            $d = '62'.$d;
        }

        return strlen($d) >= 9 ? $d : '';
    }

    /**
     * Status promo untuk pelanggan yang SEDANG dilayani (belum tercatat).
     *
     * @return array{aktif:bool, dikenali:bool, kunjungan:int, setiap:int, hak:int, berhak:bool, sisa:int,
     *               aturan:string, label:string, upah:string}
     */
    public function cek(?string $plat, ?string $telepon): array
    {
        $s = $this->settings();
        $kp = self::kunciPlat($plat);
        $kt = self::kunciTelepon($telepon);
        $hasil = [
            'aktif' => $s['aktif'], 'dikenali' => $kp !== '' || $kt !== '',
            'kunjungan' => 0, 'setiap' => $s['setiap'], 'hak' => 0, 'berhak' => false, 'sisa' => $s['setiap'],
            'aturan' => $this->aturan(), 'label' => self::label($this->aturan()), 'upah' => $s['upah'],
        ];
        if (! $s['aktif'] || ! $hasil['dikenali']) {
            return $hasil;
        }

        // Kunci plat/telepon dinormalkan di PHP: cara kasir menulisnya
        // (spasi, strip, 0812 vs +62) terlalu beragam untuk dicocokkan SQL.
        // Yang dibaca hanya transaksi sejak promo dinyalakan.
        $lalu = Transaction::valid()
            ->when($s['mulai'], fn ($q) => $q->whereDate('date', '>=', $s['mulai']))
            ->where(fn ($q) => $q->whereNotNull('plate')->orWhereNotNull('customer_phone'))
            ->get(['id', 'plate', 'customer_phone', 'promo_rule'])
            ->filter(fn ($t) => ($kp !== '' && self::kunciPlat($t->plate) === $kp)
                || ($kt !== '' && self::kunciTelepon($t->customer_phone) === $kt));

        $kunjungan = $lalu->count() + 1;
        $dipakai = $lalu->whereNotNull('promo_rule')->count();
        $hak = max(0, intdiv($kunjungan, $s['setiap']) - $dipakai);

        return array_merge($hasil, [
            'kunjungan' => $kunjungan,
            'hak'       => $hak,
            'berhak'    => $hak > 0,
            // Berapa kedatangan lagi sampai hak berikutnya.
            'sisa'      => $s['setiap'] - ($kunjungan % $s['setiap']),
        ]);
    }
}

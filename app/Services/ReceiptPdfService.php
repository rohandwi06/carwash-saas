<?php

namespace App\Services;

use App\Models\FnbSale;
use App\Models\Transaction;
use App\Models\WashCategory;
use App\Models\WashService;

/**
 * Resi dalam bentuk PDF, untuk dikirim ke pelanggan lewat tautan WhatsApp.
 *
 * Isinya mengikuti resi di layar kasir (public/js/kasir.js: dataResiCucian,
 * dataResiFnb, isiResi) — kalau susunan di sana berubah, samakan di sini.
 *
 * PDF-nya ditulis langsung, tanpa pustaka: resi hanya teks rata kiri/kanan
 * dan garis putus-putus, dan Courier adalah huruf bawaan setiap pembaca PDF
 * sehingga tidak ada yang perlu disematkan. Hosting-nya tanpa composer, jadi
 * setiap pustaka baru berarti folder vendor/ harus diunggah ulang.
 */
class ReceiptPdfService
{
    private const LEBAR  = 226.77;  // 80 mm, selebar kertas struk
    private const TEPI   = 12.0;
    private const UKURAN = 9.0;     // huruf biasa; Courier: lebar huruf = 0,6 x ukuran
    private const LABEL_BAYAR = ['cash' => 'CASH', 'tf' => 'TRANSFER', 'qris' => 'QRIS'];

    public function __construct(private BusinessProfileService $profil) {}

    public function nomorNota(string $jenis, int $id): string
    {
        return strtoupper($jenis).'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function untukCucian(Transaction $trx): string
    {
        $trx->loadMissing('addons', 'fnbSales.items');
        $kategori = WashCategory::where('slug', $trx->category)->first();
        $layanan  = WashService::where('slug', $trx->service)->first();
        $motor    = $kategori?->shape === WashCategory::BENTUK_MOTOR;
        // Makanan/minuman yang dibatalkan sendiri tidak ikut ditagihkan.
        $fnb      = $trx->fnbSales->whereNull('voided_at');

        $atas = [
            ['baris', 'Kendaraan', (string) $trx->vehicle_name],
            ['baris', 'Plat', $trx->plate ?: '-'],
        ];
        if ($trx->customer_name) {
            $atas[] = ['baris', 'Pelanggan', $trx->customer_name];
        }
        if ($trx->customer_phone) {
            $atas[] = ['baris', 'Telepon', $trx->customer_phone];
        }
        if ($kategori && $kategori->label !== $trx->vehicle_name) {
            $atas[] = ['baris', 'Jenis', $kategori->label];
        }

        // Harga cucian dasar tidak disimpan terpisah: total = cucian + add-on.
        $hargaAddon = $trx->addons->sum(fn ($a) => (int) $a->pivot->price);
        // Dengan promo, layanan ditulis dengan harga aslinya lalu potongannya
        // di baris sendiri — pelanggan melihat apa yang ia hemat.
        $rincian = [['baris', $motor ? 'Cuci Motor' : ($layanan?->label ?? (string) $trx->service),
            $this->rp($trx->total + (int) $trx->promo_discount - $hargaAddon)]];
        if ((int) $trx->promo_discount > 0) {
            $rincian[] = ['baris', 'Promo setia', '-'.$this->rp((int) $trx->promo_discount)];
        }
        foreach ($trx->addons as $a) {
            $rincian[] = ['baris', '+ '.$a->pivot->name, $this->rp((int) $a->pivot->price)];
        }
        foreach ($fnb as $jual) {
            foreach ($jual->items as $i) {
                $rincian[] = ['baris', $i->product_name.' x'.$i->qty, $this->rp($i->subtotal)];
            }
        }

        return $this->susun(
            nomor: $this->nomorNota('c', $trx->id),
            waktu: $trx->created_at,
            batal: $trx->voided_at !== null,
            atas: $atas,
            rincian: $rincian,
            belanja: (int) $trx->total + (int) $fnb->sum('total'),
            tip: (int) $trx->tip + (int) $fnb->sum('tip'),
            bayar: $trx->payment_method,
            kasir: $trx->created_by,
            catatan: 'Simpan resi untuk ambil kendaraan',
        );
    }

    public function untukFnb(FnbSale $jual): string
    {
        $jual->loadMissing('items', 'transaction');
        $rincian = [];
        foreach ($jual->items as $i) {
            $rincian[] = ['baris', $i->product_name.' x'.$i->qty, $this->rp($i->subtotal)];
        }

        return $this->susun(
            nomor: $this->nomorNota('f', $jual->id),
            waktu: $jual->created_at,
            // Jajanan yang menempel pada cucian ikut gugur bersama cuciannya.
            batal: $jual->voided_at !== null || $jual->transaction?->voided_at !== null,
            atas: [],
            rincian: $rincian,
            belanja: (int) $jual->total,
            tip: (int) $jual->tip,
            bayar: $jual->payment_method,
            kasir: $jual->created_by,
            catatan: '',
        );
    }

    private function rp(int|float $n): string
    {
        return 'Rp '.number_format((float) $n, 0, ',', '.');
    }

    /** Menyusun unsur resi dari atas ke bawah, lalu menjadikannya PDF. */
    private function susun(string $nomor, $waktu, bool $batal, array $atas, array $rincian,
        int $belanja, int $tip, ?string $bayar, ?string $kasir, string $catatan): string
    {
        $usaha = $this->profil->profile();
        $garis = [['garis']];

        $u = [['tengah', $usaha['name'], true, 13.0]];
        foreach ([$usaha['tagline'], $usaha['address'], $usaha['phone'] !== '' ? 'Telp. '.$usaha['phone'] : ''] as $t) {
            if ($t !== '') {
                $u[] = ['tengah', $t];
            }
        }
        if ($batal) {
            // Tautan resi tetap hidup setelah transaksinya dibatalkan — tanpa
            // tanda ini resi lama bisa dipakai seolah transaksinya masih sah.
            $u[] = ['tengah', '*** DIBATALKAN ***', true, 11.0];
        }
        $u = array_merge($u, $garis, [
            ['baris', 'No. Nota', $nomor],
            ['baris', $waktu ? $waktu->format('j/n/Y') : '-', $waktu ? $waktu->format('H.i') : ''],
        ], $garis);
        if ($atas) {
            $u = array_merge($u, $atas, $garis);
        }
        $u = array_merge($u, $rincian, $garis);
        if ($tip > 0) {
            $u[] = ['baris', 'Subtotal', $this->rp($belanja)];
            $u[] = ['baris', 'Tip', $this->rp($tip)];
        }
        $u[] = ['baris', 'TOTAL', $this->rp($belanja + $tip), true, 11.0];
        $u[] = ['baris', 'Bayar', self::LABEL_BAYAR[$bayar] ?? 'CASH'];
        if ($kasir) {
            $u[] = ['baris', 'Kasir', $kasir];
        }
        $u = array_merge($u, $garis, [['tengah', 'Terima kasih!']]);
        if ($catatan !== '') {
            $u[] = ['tengah', $catatan];
        }

        return $this->pdf($u, 'Resi '.$nomor.' - '.$usaha['name']);
    }

    /**
     * @param  array<int, array>  $unsur  ['tengah', teks, tebal?, ukuran?] | ['baris', kiri, kanan, tebal?, ukuran?] | ['garis']
     */
    private function pdf(array $unsur, string $judul): string
    {
        $isiLebar = self::LEBAR - 2 * self::TEPI;
        $baris = [];   // [x, teks, tebal, ukuran] atau null untuk garis
        foreach ($unsur as $e) {
            if ($e[0] === 'garis') {
                $baris[] = null;

                continue;
            }
            $tebal  = $e[0] === 'tengah' ? ($e[2] ?? false) : ($e[3] ?? false);
            $ukuran = $e[0] === 'tengah' ? ($e[3] ?? self::UKURAN) : ($e[4] ?? self::UKURAN);
            $huruf  = 0.6 * $ukuran;
            $kolom  = (int) floor($isiLebar / $huruf);

            if ($e[0] === 'tengah') {
                foreach ($this->potong($this->latin($e[1]), $kolom) as $t) {
                    $baris[] = [self::TEPI + ($isiLebar - strlen($t) * $huruf) / 2, $t, $tebal, $ukuran];
                }

                continue;
            }

            $kiri  = $this->latin($e[1]);
            $kanan = $this->latin($e[2]);
            // Yang panjang dilipat, yang pendek tetap sebaris: nama kendaraan
            // melipat di kanan (labelnya pendek), nama menu melipat di kiri.
            if (strlen($kiri) + strlen($kanan) + 1 <= $kolom) {
                $kiriL = [$kiri];
                $kananL = [$kanan];
            } elseif (strlen($kiri) <= $kolom * 0.4) {
                $kiriL = [$kiri];
                $kananL = $this->potong($kanan, $kolom - strlen($kiri) - 2);
            } else {
                $lebarKanan = min(strlen($kanan), intdiv($kolom, 2));
                $kananL = $this->potong($kanan, $lebarKanan);
                $kiriL = $this->potong($kiri, $kolom - $lebarKanan - 2);
            }
            for ($i = 0; $i < max(count($kiriL), count($kananL)); $i++) {
                if (($kiriL[$i] ?? '') !== '') {
                    $baris[] = [self::TEPI, $kiriL[$i], $tebal, $ukuran, 'sambung' => ($kananL[$i] ?? '') !== ''];
                }
                if (($kananL[$i] ?? '') !== '') {
                    $baris[] = [self::LEBAR - self::TEPI - strlen($kananL[$i]) * $huruf, $kananL[$i], $tebal, $ukuran];
                }
            }
        }

        // Tinggi halaman mengikuti isi, seperti kertas struk yang dipotong.
        $tinggi = 2 * self::TEPI;
        foreach ($baris as $b) {
            $tinggi += $b === null ? 9.0 : (($b['sambung'] ?? false) ? 0.0 : $b[3] * 1.35);
        }

        $y = $tinggi - self::TEPI;
        $isi = '';
        foreach ($baris as $b) {
            if ($b === null) {
                $isi .= sprintf("[2 2] 0 d 0.7 w %.2F %.2F m %.2F %.2F l S\n",
                    self::TEPI, $y - 4.5, self::LEBAR - self::TEPI, $y - 4.5);
                $y -= 9.0;

                continue;
            }
            $isi .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
                $b[2] ? 'F2' : 'F1', $b[3], $b[0], $y - $b[3],
                strtr($b[1], ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']));
            if (! ($b['sambung'] ?? false)) {
                $y -= $b[3] * 1.35;
            }
        }

        $judulPdf = strtr($this->latin($judul), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $objek = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R '
                .'/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>', self::LEBAR, $tinggi),
            '<< /Length '.strlen($isi)." >>\nstream\n".$isi."endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Title ('.$judulPdf.') >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $letak = [];
        foreach ($objek as $i => $o) {
            $letak[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$o."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objek) + 1)."\n0000000000 65535 f \n";
        foreach ($letak as $l) {
            $pdf .= sprintf("%010d 00000 n \n", $l);
        }

        return $pdf.'trailer << /Size '.(count($objek) + 1)." /Root 1 0 R /Info 7 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /**
     * Huruf bawaan PDF hanya mengenal Latin (Windows-1252); yang di luar itu
     * (emoji, aksara lain) jadi tanda tanya daripada merusak berkasnya.
     */
    private function latin(string $teks): string
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', $teks));

        return mb_convert_encoding($teks, 'Windows-1252', 'UTF-8');
    }

    /** @return string[] */
    private function potong(string $teks, int $lebar): array
    {
        if ($teks === '') {
            return [''];
        }

        return explode("\n", wordwrap($teks, max(4, $lebar), "\n", true));
    }
}

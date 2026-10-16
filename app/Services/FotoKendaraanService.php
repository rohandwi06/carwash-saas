<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\VehiclePhoto;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Foto kondisi kendaraan (permintaan owner 16/10).
 *
 * Layar kasir sudah memperkecil fotonya (sisi terpanjang 1280 px, JPEG) sebelum
 * dikirim, jadi satu foto sekitar 100–200 KB. Server tidak mengolah ulang
 * gambarnya — hosting tidak dijamin punya GD — cukup memastikan isinya memang
 * gambar dan ukurannya wajar.
 *
 * Disimpan 60 hari lalu dihapus (bersihkan()): komplain lecet datang dalam
 * hitungan hari, sedangkan ruang hosting cucian yang ramai bisa penuh dalam
 * beberapa bulan kalau foto disimpan selamanya.
 *
 * Berkasnya ditulis dengan fungsi berkas PHP biasa, BUKAN Storage (Flysystem):
 * per 16/10 folder vendor/ di laptop — dan karena itu juga paket server —
 * kehilangan league/flysystem/src/Config.php, sehingga Storage mati begitu
 * dipakai. Folder dasarnya bisa diganti lewat config('carwash.foto_dir')
 * (dipakai tes).
 */
class FotoKendaraanService
{
    public const SIMPAN_HARI = 60;

    public const MAKS_PER_TRANSAKSI = 6;

    private const MAKS_BYTE = 3 * 1024 * 1024;

    private const JENIS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Isi gambar dari data URL ("data:image/jpeg;base64,...") atau base64 polos.
     *
     * @return array{byte:string, mime:string, ext:string}
     */
    public static function urai(string $data): array
    {
        $base64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', trim($data));
        $byte = base64_decode((string) $base64, true);
        if ($byte === false || $byte === '') {
            throw new InvalidArgumentException('Foto tidak terbaca. Coba potret ulang.');
        }
        if (strlen($byte) > self::MAKS_BYTE) {
            throw new InvalidArgumentException('Foto terlalu besar (maksimal 3 MB).');
        }
        $info = @getimagesizefromstring($byte);
        $mime = $info['mime'] ?? '';
        if (! isset(self::JENIS[$mime]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) {
            throw new InvalidArgumentException('Berkas ini bukan foto JPG, PNG, atau WebP.');
        }

        return ['byte' => $byte, 'mime' => $mime, 'ext' => self::JENIS[$mime]];
    }

    public function simpan(Transaction $trx, string $data, ?string $oleh = null): VehiclePhoto
    {
        if ($trx->photos()->count() >= self::MAKS_PER_TRANSAKSI) {
            throw new RuntimeException('Satu transaksi paling banyak '.self::MAKS_PER_TRANSAKSI.' foto.');
        }
        $gambar = self::urai($data);

        // Nama acak, dikelompokkan per bulan supaya satu folder tidak berisi
        // puluhan ribu berkas.
        $path = 'photos/'.now()->format('Y-m').'/'.Str::random(32).'.'.$gambar['ext'];
        $penuh = self::lokasi($path);
        if (! is_dir(dirname($penuh))) {
            @mkdir(dirname($penuh), 0755, true);
        }
        if (@file_put_contents($penuh, $gambar['byte']) === false) {
            throw new RuntimeException('Foto gagal disimpan di server. Periksa izin folder storage.');
        }

        $foto = VehiclePhoto::create([
            'transaction_id' => $trx->id,
            'path'           => $path,
            'size'           => strlen($gambar['byte']),
            'created_by'     => $oleh,
        ]);

        // Hosting tidak dijamin menjalankan cron: foto yang kedaluwarsa juga
        // ikut dibersihkan sedikit demi sedikit setiap ada foto baru.
        $this->bersihkan(20);

        return $foto;
    }

    /** Hapus foto (berkas + barisnya) yang lebih tua dari SIMPAN_HARI. */
    public function bersihkan(int $batas = 1000): int
    {
        $lama = VehiclePhoto::where('created_at', '<', now()->subDays(self::SIMPAN_HARI))
            ->orderBy('id')->limit($batas)->get();
        foreach ($lama as $foto) {
            $this->hapus($foto);
        }

        return $lama->count();
    }

    /** Lokasi berkas di disk; di luar folder publik. */
    public static function lokasi(string $path): string
    {
        $dasar = config('carwash.foto_dir') ?: storage_path('app/private');

        return rtrim($dasar, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    public function hapus(VehiclePhoto $foto): void
    {
        @unlink(self::lokasi($foto->path));
        $foto->delete();
    }

    /** @return array{byte:string, mime:string}|null */
    public function isi(VehiclePhoto $foto): ?array
    {
        $penuh = self::lokasi($foto->path);
        $byte = is_file($penuh) ? file_get_contents($penuh) : false;
        if ($byte === false) {
            return null;
        }
        $mime = array_search(pathinfo($foto->path, PATHINFO_EXTENSION), self::JENIS, true) ?: 'image/jpeg';

        return ['byte' => $byte, 'mime' => $mime];
    }
}

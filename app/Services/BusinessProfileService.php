<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Identitas usaha: nama, keterangan, alamat, telepon.
 *
 * Tiap cucian memasang aplikasi yang sama di hosting-nya sendiri, jadi nama
 * usaha tidak boleh tertulis di kode (docs/AUDIT-MULTITENANT.md temuan 1.1).
 * Owner mengaturnya dari Pengaturan; sebelum pernah diatur, nama diambil
 * dari APP_NAME di .env — itu sebabnya OTIN, yang .env produksinya sudah
 * berisi APP_NAME="OTIN CARWASH", tetap bernama sama tanpa migrasi data.
 */
class BusinessProfileService
{
    private const KEYS = [
        'name'    => 'business_name',
        'tagline' => 'business_tagline',
        'address' => 'business_address',
        'phone'   => 'business_phone',
    ];

    private const TAGLINE_BAWAAN = 'Cuci Mobil & Motor';

    /** @return array{name: string, tagline: string, address: string, phone: string} */
    public function profile(): array
    {
        try {
            $tersimpan = Setting::whereIn('key', array_values(self::KEYS))->pluck('value', 'key');
        } catch (QueryException) {
            // Halaman kasir memanggil ini sebelum apa pun. Kalau database
            // belum siap (MySQL mati, migrasi belum jalan), layar login tetap
            // tampil dengan nama dari APP_NAME; errornya muncul nanti di API,
            // tempat pesan kesalahannya memang ditangani.
            $tersimpan = collect();
        }
        $nilai = fn (string $k) => trim((string) ($tersimpan[self::KEYS[$k]] ?? ''));

        return [
            'name'    => $nilai('name') !== '' ? $nilai('name') : $this->namaBawaan(),
            // Keterangan boleh sengaja dikosongkan owner, jadi bawaannya hanya
            // dipakai selama baris setting-nya belum pernah ada.
            'tagline' => $tersimpan->has(self::KEYS['tagline']) ? $nilai('tagline') : self::TAGLINE_BAWAAN,
            'address' => $nilai('address'),
            'phone'   => $nilai('phone'),
        ];
    }

    /** @param array{name: string, tagline?: ?string, address?: ?string, phone?: ?string} $data */
    public function update(array $data): array
    {
        foreach (self::KEYS as $field => $key) {
            if (array_key_exists($field, $data)) {
                Setting::put($key, trim((string) $data[$field]));
            }
        }

        return $this->profile();
    }

    public function name(): string
    {
        return $this->profile()['name'];
    }

    /** Nama usaha dalam bentuk aman untuk nama berkas: "OTIN CARWASH" -> "otin-carwash". */
    public function slug(): string
    {
        return Str::slug($this->name()) ?: 'carwash';
    }

    private function namaBawaan(): string
    {
        $nama = trim((string) config('app.name'));

        // "Laravel" adalah isi .env.example bawaan framework — lebih baik
        // tampil generik daripada memajang nama framework di layar kasir.
        return $nama !== '' && $nama !== 'Laravel' ? $nama : 'CARWASH';
    }
}

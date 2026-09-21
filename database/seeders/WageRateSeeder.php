<?php

namespace Database\Seeders;

use App\Models\WageRate;
use App\Models\WashPrice;
use Illuminate\Database\Seeder;

/**
 * Tarif upah awal: satu angka per jenis kendaraan (config carwash.default_wages),
 * dipasang ke SETIAP layanan yang punya harga untuk jenis itu.
 *
 * Tarif memang per jenis + layanan sejak migrasi 2026_07_20. Di database baru
 * migrasi itu sudah membuat baris-barisnya dengan nilai 0 (tidak ada tarif lama
 * untuk disalin), jadi seeder ini mengisi baris yang masih 0. Dulu seeder
 * mencari per jenis saja, menemukan baris bernilai 0 itu, dan membiarkannya —
 * pemasangan baru mulai dengan upah Rp 0 di semua layanan.
 *
 * Tarif yang sudah diisi owner (bukan 0) tidak pernah disentuh.
 */
class WageRateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('carwash.default_wages') as $category => $amount) {
            $layanan = WashPrice::where('category_slug', $category)->pluck('service_slug')->all() ?: ['reguler'];

            foreach ($layanan as $service) {
                $rate = WageRate::firstOrNew(['category' => $category, 'service' => $service]);
                if (! $rate->exists || (int) $rate->amount === 0) {
                    $rate->amount = $amount;
                    $rate->trainee_amount ??= 0;
                    $rate->save();
                }
            }
        }
    }
}

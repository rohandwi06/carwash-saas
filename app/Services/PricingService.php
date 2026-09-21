<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\WashCategory;
use App\Models\WashPrice;
use App\Models\WashService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Satu-satunya tempat harga dihitung.
 * Client TIDAK PERNAH mengirim total — server yang menghitung,
 * supaya kasir/perangkat tidak bisa memanipulasi harga.
 *
 * Jenis kendaraan, jenis layanan, dan matriks harganya hidup di database
 * (tabel wash_categories / wash_services / wash_prices) sehingga owner bisa
 * menambah & menghapus, bukan cuma mengubah angka.
 */
class PricingService
{
    /** Harga satu kali cuci (tanpa add-on). */
    public function total(string $category, string $service): int
    {
        $price = WashPrice::where('category_slug', $category)
            ->where('service_slug', $service)
            ->value('price');

        if ($price === null) {
            throw new InvalidArgumentException(
                "Layanan '{$service}' tidak tersedia untuk jenis kendaraan '{$category}'."
            );
        }

        return (int) $price;
    }

    /**
     * Kategori beserta harga tiap layanan yang tersedia untuknya:
     * ['kecil' => ['label'=>..,'shape'=>..,'price'=>25000,'prices'=>['reguler'=>25000,...]]]
     * 'price' = harga termurah (dipakai sebagai harga pajangan di layar kasir).
     */
    public function categories(): array
    {
        $harga = WashPrice::all()->groupBy('category_slug');

        return WashCategory::urut()->get()
            ->mapWithKeys(function (WashCategory $c) use ($harga) {
                $map = $harga->get($c->slug, collect())->pluck('price', 'service_slug')
                    ->map(fn ($p) => (int) $p)->all();

                return [$c->slug => [
                    'id'       => $c->id,
                    'label'    => $c->label,
                    'shape'    => $c->shape,
                    'examples' => $c->examples,
                    'price'    => $map['reguler'] ?? (count($map) ? min($map) : 0),
                    'prices'   => $map,
                ]];
            })->all();
    }

    /** Semua jenis layanan: ['reguler' => ['label' => 'Cuci Reguler'], ...] */
    public function services(): array
    {
        return WashService::urut()->get()
            ->mapWithKeys(fn (WashService $s) => [$s->slug => [
                'id'    => $s->id,
                'label' => $s->label,
            ]])->all();
    }

    /** Simpan harga TOTAL satu sel (kategori x layanan). */
    public function setCellPrice(string $category, string $service, int $total): void
    {
        if (! WashCategory::where('slug', $category)->exists()) {
            throw new InvalidArgumentException("Jenis kendaraan tidak dikenal: {$category}");
        }
        if (! WashService::where('slug', $service)->exists()) {
            throw new InvalidArgumentException("Jenis layanan tidak dikenal: {$service}");
        }

        WashPrice::updateOrCreate(
            ['category_slug' => $category, 'service_slug' => $service],
            ['price' => $total],
        );
    }

    /**
     * Ambil add-on aktif berdasarkan id, sebagai SALINAN (nama + harga saat ini).
     * Dipakai TransactionService supaya riwayat lama tidak ikut berubah
     * ketika harga add-on diubah owner di kemudian hari.
     */
    public function resolveAddons(array $addonIds): Collection
    {
        if (empty($addonIds)) {
            return collect();
        }

        return Addon::whereIn('id', array_unique($addonIds))
            ->where('is_active', true)
            ->urut()->get()
            ->map(fn (Addon $a) => [
                'id'    => $a->id,
                'name'  => $a->name,
                'price' => (int) $a->price,
            ]);
    }
}

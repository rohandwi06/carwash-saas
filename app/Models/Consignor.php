<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penitip: orang yang menitipkan barang untuk dijualkan cucian.
 */
class Consignor extends Model
{
    protected $fillable = ['name', 'phone', 'note', 'share_mode', 'share_percent', 'is_active'];

    protected $casts = [
        'is_active'     => 'boolean',
        'share_percent' => 'integer',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ConsignmentMovement::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ConsignmentPayout::class);
    }

    /**
     * Hak penitip untuk SATU barang terjual pada harga $price.
     *
     * Dua mode, dua cara hitung:
     *   setor  — penitip minta angka tetap ($payoutPrice), sisanya milik cucian.
     *   persen — cucian mengambil share_percent dari harga jual, sisanya hak
     *            penitip. Jatah cucian yang dibulatkan (bukan jatah penitip),
     *            supaya pembulatan tidak pernah memakan hak orang.
     *
     * Tidak pernah melebihi harga jual: harga setor yang ketinggalan zaman
     * (mis. barang didiskon di bawah harga setornya) tidak boleh membuat
     * cucian menombok tanpa disadari.
     */
    public function hakPerBarang(int $price, ?int $payoutPrice): int
    {
        $hak = $this->share_mode === 'persen'
            ? $price - (int) round($price * (int) $this->share_percent / 100)
            : (int) $payoutPrice;

        return max(0, min($hak, $price));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['name', 'type', 'price', 'stock', 'track_stock', 'is_active',
        'consignor_id', 'payout_price'];

    protected $casts = [
        'is_active'    => 'boolean',
        'stock'        => 'integer',
        'track_stock'  => 'boolean',
        'payout_price' => 'integer',
    ];

    /** Null = barang milik cucian sendiri. */
    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    /**
     * Apakah stok menu ini dihitung. false = menu tanpa stok (dibuat saat
     * dipesan: kopi tubruk, teh) — tidak dicek, dipotong, atau dikembalikan.
     *
     * Dibandingkan dengan `=== false`, bukan `! $this->track_stock`: bila kode
     * ini sudah naik tapi kolomnya belum ada di server (SQL belum dijalankan),
     * atributnya null — dan itu harus dibaca "masih berstok", bukan diam-diam
     * berhenti memotong stok semua menu.
     */
    public function pakaiStok(): bool
    {
        return $this->track_stock !== false;
    }

    public function isTitipan(): bool
    {
        return $this->consignor_id !== null;
    }

    /** Hak penitip untuk $qty barang ini. 0 untuk barang milik sendiri. */
    public function hakPenitip(int $qty): int
    {
        if (! $this->isTitipan() || $this->consignor === null) {
            return 0;
        }

        return $this->consignor->hakPerBarang((int) $this->price, $this->payout_price) * $qty;
    }
}

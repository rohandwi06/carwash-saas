<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['name', 'type', 'price', 'stock', 'is_active',
        'consignor_id', 'payout_price'];

    protected $casts = [
        'is_active'    => 'boolean',
        'stock'        => 'integer',
        'payout_price' => 'integer',
    ];

    /** Null = barang milik cucian sendiri. */
    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
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

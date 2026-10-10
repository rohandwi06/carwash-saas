<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis kendaraan (motor, mobil kecil, ...). Diacu transaksi lewat `slug`. */
class WashCategory extends Model
{
    protected $fillable = ['slug', 'label', 'shape', 'examples', 'sort_order'];

    /** Bentuk siluet di layar kasir. 'moto' juga menentukan kolom Cash Motor. */
    public const BENTUK = ['moto', 'hatch', 'mpv', 'van', 'pickup'];

    public const BENTUK_MOTOR = 'moto';

    public function scopeUrut($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

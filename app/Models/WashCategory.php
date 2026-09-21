<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis kendaraan (motor, mobil kecil, ...). Diacu transaksi lewat `slug`. */
class WashCategory extends Model
{
    protected $fillable = ['slug', 'label', 'shape', 'examples', 'sort_order'];

    public function scopeUrut($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

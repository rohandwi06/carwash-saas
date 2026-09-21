<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis layanan cuci (reguler, + interior, komplit, ...). */
class WashService extends Model
{
    protected $fillable = ['slug', 'label', 'sort_order'];

    public function scopeUrut($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

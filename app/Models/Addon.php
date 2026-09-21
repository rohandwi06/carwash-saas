<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Layanan tambahan: semir ban, poles, anti jamur kaca, dst. */
class Addon extends Model
{
    protected $fillable = ['name', 'price', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeUrut($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

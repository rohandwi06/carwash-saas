<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Barang perlengkapan cuci (sabun, semir, lap). Lihat migrasi create_supplies_tables. */
class Supply extends Model
{
    protected $fillable = ['name', 'unit', 'stock', 'min_stock', 'sort_order'];

    // float, bukan decimal: cast decimal mengirim string "3.500" ke frontend.
    protected $casts = [
        'stock'     => 'float',
        'min_stock' => 'float',
    ];

    /** Dikirim ke frontend supaya batas "menipis" dihitung di satu tempat. */
    protected $appends = ['is_low'];

    public function getIsLowAttribute(): bool
    {
        return $this->min_stock !== null && $this->stock <= $this->min_stock;
    }

    public function scopeUrut($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SupplyMovement::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(SupplyUsageRule::class);
    }
}

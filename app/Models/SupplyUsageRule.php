<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Takaran otomatis: sekian barang terpakai tiap cucian yang cocok.
 * category_slug / service_slug null = semua; addon_id terisi = hanya cucian
 * yang memakai add-on itu (jenis & layanan diabaikan).
 */
class SupplyUsageRule extends Model
{
    protected $fillable = ['supply_id', 'qty', 'category_slug', 'service_slug', 'addon_id'];

    protected $casts = [
        'qty'      => 'float',
        'addon_id' => 'integer',
    ];

    public function cocok(Transaction $trx, array $addonIds): bool
    {
        if ($this->addon_id !== null) {
            return in_array($this->addon_id, $addonIds, true);
        }

        return ($this->category_slug === null || $this->category_slug === $trx->category)
            && ($this->service_slug === null || $this->service_slug === $trx->service);
    }
}

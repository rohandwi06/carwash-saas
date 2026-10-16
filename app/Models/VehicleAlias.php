<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ejaan kasir yang diingat untuk satu mobil di katalog. Lihat VehicleMemoryService. */
class VehicleAlias extends Model
{
    protected $fillable = ['vehicle_id', 'alias', 'hits'];

    protected $casts = ['hits' => 'integer'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}

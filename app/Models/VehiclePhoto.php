<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto kondisi kendaraan pada satu transaksi. Lihat FotoKendaraanService. */
class VehiclePhoto extends Model
{
    protected $fillable = ['transaction_id', 'path', 'size', 'created_by'];

    // Lokasi berkas tidak perlu diketahui layar; foto dibuka lewat /api/photos/{id}.
    protected $hidden = ['path'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

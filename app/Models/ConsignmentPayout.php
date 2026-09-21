<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Setoran uang hasil penjualan titipan ke penitipnya. */
class ConsignmentPayout extends Model
{
    protected $fillable = ['consignor_id', 'amount', 'date', 'note', 'created_by', 'expense_id'];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'integer',
    ];

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }
}

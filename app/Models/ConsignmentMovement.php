<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barang titipan masuk ke rak, atau diambil kembali penitipnya (retur).
 * Yang TERJUAL tidak dicatat di sini — itu sudah ada di fnb_sale_items.
 */
class ConsignmentMovement extends Model
{
    protected $fillable = ['consignor_id', 'product_id', 'type', 'qty', 'date', 'note', 'created_by'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date' => 'date:Y-m-d',
        'qty'  => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

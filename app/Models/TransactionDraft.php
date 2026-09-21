<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan kendaraan yang sudah masuk tapi belum dibayar.
 * Bukan transaksi: tidak punya total, tidak masuk rekap uang mana pun.
 */
class TransactionDraft extends Model
{
    protected $fillable = [
        'vehicle_name', 'category', 'service', 'plate', 'note',
        'worker_ids', 'addon_ids', 'fnb_items', 'tip', 'date', 'created_by',
    ];

    protected $casts = [
        'worker_ids' => 'array',
        'addon_ids'  => 'array',
        // Isinya {product_id, qty} saja — harga sengaja tidak ikut disimpan,
        // sama seperti kolom draft yang lain.
        'fnb_items'  => 'array',
        'tip'        => 'integer',
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'       => 'date:Y-m-d',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pesanan makanan/minuman yang sudah dicatat tapi belum dibayar.
 * Bukan penjualan: tidak punya total, tidak memotong stok, dan tidak masuk
 * rekap uang mana pun sampai benar-benar disimpan lewat FnbService::create().
 */
class FnbDraft extends Model
{
    protected $fillable = ['label', 'items', 'tip', 'note', 'date', 'created_by'];

    protected $casts = [
        'items' => 'array',
        'tip'   => 'integer',
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'  => 'date:Y-m-d',
    ];
}

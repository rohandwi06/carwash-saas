<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = ['description', 'amount', 'date', 'book_id', 'created_by',
        'is_consignment'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'   => 'date:Y-m-d',
        'amount' => 'integer',
        // Setoran ke penitip: mengurangi KAS tapi tidak mengurangi LABA —
        // uang itu tidak pernah diakui sebagai laba sejak barangnya laku.
        'is_consignment' => 'boolean',
    ];
}

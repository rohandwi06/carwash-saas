<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedSearch extends Model
{
    protected $fillable = ['query', 'date'];

    // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
    // lihat catatan lengkap di App\Models\CashBook.
    protected $casts = ['date' => 'date:Y-m-d'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu gerak stok barang: beli | pakai | hitung | cuci. */
class SupplyMovement extends Model
{
    public const JENIS = ['beli', 'pakai', 'hitung', 'cuci'];

    protected $fillable = ['supply_id', 'kind', 'qty', 'stock_after', 'price',
        'expense_id', 'transaction_id', 'note', 'date', 'created_by'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'        => 'date:Y-m-d',
        'qty'         => 'float',
        'stock_after' => 'float',
        'price'       => 'integer',
    ];

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }
}

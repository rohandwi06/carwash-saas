<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyesuaian upah seorang pekerja pada satu tanggal — potongan (hukuman)
 * atau penimpaan angka upah oleh owner. Aturan pemakaiannya ada di
 * WageService::terapkanPenyesuaian(); tabelnya dijelaskan di migrasi
 * create_wage_adjustments_table.
 */
class WageAdjustment extends Model
{
    public const POTONGAN = 'potongan';
    public const TIMPA    = 'timpa';

    protected $fillable = ['worker_id', 'worker_name', 'type', 'amount',
        'reason', 'date', 'book_id', 'created_by'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'   => 'date:Y-m-d',
        'amount' => 'integer',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}

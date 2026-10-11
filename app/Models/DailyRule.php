<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan otomatis harian: potongan upah atau kompensasi yang dicatat sendiri
 * tiap hari untuk pekerja yang mengerjakan cucian hari itu. Cara kerjanya
 * dijelaskan di migrasi create_daily_rules_tables dan DailyRuleService.
 */
class DailyRule extends Model
{
    public const POTONGAN   = 'potongan';
    public const KOMPENSASI = 'kompensasi';

    protected $fillable = ['type', 'worker_id', 'amount', 'reason', 'is_active', 'start_date', 'created_by'];

    protected $casts = [
        'amount'     => 'integer',
        'is_active'  => 'boolean',
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'start_date' => 'date:Y-m-d',
    ];

    /** Pekerja yang dituju; null = semua pekerja yang kerja hari itu. */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}

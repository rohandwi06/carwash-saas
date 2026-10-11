<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Aturan otomatis harian: potongan upah atau kompensasi yang dicatat sendiri
 * tiap hari untuk pekerja yang mengerjakan cucian hari itu. Cara kerjanya
 * dijelaskan di migrasi create_daily_rules_tables dan DailyRuleService.
 */
class DailyRule extends Model
{
    public const POTONGAN   = 'potongan';
    public const KOMPENSASI = 'kompensasi';

    protected $fillable = ['type', 'all_workers', 'amount', 'reason', 'is_active', 'start_date', 'created_by'];

    protected $casts = [
        'all_workers' => 'boolean',
        'amount'      => 'integer',
        'is_active'   => 'boolean',
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'start_date'  => 'date:Y-m-d',
    ];

    /**
     * Pekerja pilihan aturan ini — hanya berarti bila all_workers = false.
     * Kenapa all_workers kolom tersendiri dan bukan "daftar kosong = semua":
     * lihat migrasi add_workers_to_daily_rules.
     */
    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'daily_rule_worker');
    }
}

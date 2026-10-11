<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kompensasi ("sangu") seorang karyawan pada satu tanggal — uang makan, uang
 * bensin, dsb. Aturannya dijelaskan di migrasi create_worker_compensations_table
 * dan dihitung lewat CompensationService.
 */
class WorkerCompensation extends Model
{
    // Ditulis terang: Laravel menganggap "compensation" tak berbentuk jamak,
    // jadi tanpa ini ia mencari tabel worker_compensation.
    protected $table = 'worker_compensations';

    protected $fillable = ['worker_id', 'worker_name', 'amount', 'reason', 'date', 'created_by',
        // Terisi bila barisnya dibuat aturan otomatis harian (DailyRuleService).
        'rule_id'];

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

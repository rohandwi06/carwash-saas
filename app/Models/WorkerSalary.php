<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gaji bulanan seorang pekerja MULAI sebuah tanggal. Gaji pada suatu tanggal
 * = baris terakhir yang start_date-nya tidak melewati tanggal itu. Lihat
 * migrasi create_monthly_wage_tables dan SalaryService.
 */
class WorkerSalary extends Model
{
    protected $fillable = ['worker_id', 'amount', 'start_date', 'created_by'];

    protected $casts = [
        'amount'     => 'integer',
        'start_date' => 'date:Y-m-d',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}

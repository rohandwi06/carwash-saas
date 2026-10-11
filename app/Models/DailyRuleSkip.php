<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Aturan ini jangan dicatat untuk pekerja ini pada tanggal ini" — dibuat saat
 * owner menghapus sebuah catatan otomatis, supaya cucian berikutnya di hari
 * yang sama tidak membuatnya lagi. Lihat DailyRuleService::tandaiDilewati().
 */
class DailyRuleSkip extends Model
{
    protected $fillable = ['rule_id', 'worker_id', 'date'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}

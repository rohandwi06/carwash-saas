<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu rentang tanggal ketika cara upah "per bulan" berlaku. end_date kosong
 * = masih berlaku. Lihat migrasi create_monthly_wage_tables dan SalaryService.
 */
class MonthlyWagePeriod extends Model
{
    protected $fillable = ['start_date', 'end_date'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'start_date' => 'date:Y-m-d',
        'end_date'   => 'date:Y-m-d',
    ];
}

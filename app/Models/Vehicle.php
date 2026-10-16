<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = ['name', 'category', 'needs_review', 'corrected_at'];

    // corrected_at: kapan owner terakhir mengganti jenisnya — mobil seperti
    // ini dipakai sebagai contoh untuk AI (VehicleMemoryService).
    protected $casts = ['needs_review' => 'boolean', 'corrected_at' => 'datetime'];
}

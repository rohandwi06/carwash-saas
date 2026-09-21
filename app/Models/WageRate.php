<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WageRate extends Model
{
    protected $fillable = ['category', 'service', 'amount', 'trainee_amount'];

    protected $casts = [
        'amount'         => 'integer',
        'trainee_amount' => 'integer',
    ];
}

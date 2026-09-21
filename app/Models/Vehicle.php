<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = ['name', 'category', 'needs_review'];

    protected $casts = ['needs_review' => 'boolean'];
}

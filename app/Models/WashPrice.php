<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Harga TOTAL satu sel: kategori x layanan.
 * Ada barisnya = layanan itu tersedia untuk kategori tsb.
 */
class WashPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['category_slug', 'service_slug', 'price'];
}

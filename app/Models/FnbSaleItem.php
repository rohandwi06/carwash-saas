<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FnbSaleItem extends Model
{
    public $timestamps = false;

    // consignor_share = hak penitip untuk baris ini, DIBEKUKAN saat penjualan
    // terjadi. Harga setor & persentase boleh berubah besok; angka yang sudah
    // terjadi tidak boleh ikut bergerak. Alasan yang sama dengan product_name
    // dan price yang juga disalin ke sini.
    protected $fillable = ['product_id', 'product_name', 'price', 'qty', 'subtotal',
        'consignor_id', 'consignor_share'];
}

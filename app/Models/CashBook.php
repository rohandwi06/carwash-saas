<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu "buku kas" — pengganti shift sebagai satuan laporan & setoran.
 * Lihat migrasi 2026_08_11_000001 untuk penjelasan lengkap alur statusnya.
 */
class CashBook extends Model
{
    protected $fillable = [
        'date', 'number', 'status', 'opening_balance',
        'opened_at', 'opened_by',
        'requested_at', 'requested_by', 'amount',
        'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'reject_reason',
    ];

    protected $casts = [
        // Format eksplisit ':Y-m-d' — BUKAN sekadar gaya penulisan. Sejak
        // timezone aplikasi WIB (bukan UTC lagi), cast 'date' polos membuat
        // Carbon meng-UTC-kan tengah malam WIB saat dikirim sebagai JSON,
        // sehingga "2026-08-11" terbaca "2026-08-10T17:00:00.000000Z" di
        // frontend — tanggalnya mundur satu hari kalau cuma diambil 10
        // karakter pertama. Format eksplisit membuat serialisasinya memakai
        // format itu apa adanya, tanpa konversi UTC, sementara sisi PHP
        // (->toDateString(), dst.) tetap dapat objek Carbon utuh seperti
        // biasa. Lihat catatan yang sama di Expense/FnbSale/Transaction/dst.
        'date'         => 'date:Y-m-d',
        'opened_at'    => 'datetime',
        'requested_at' => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'amount'       => 'integer',
        'opening_balance' => 'integer',
    ];

    /** "Buku 1", "Buku 2" — dipakai di layar tanpa perlu ulang tulis format. */
    public function label(): string
    {
        return 'Buku '.$this->number;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'book_id');
    }

    public function fnbSales(): HasMany
    {
        return $this->hasMany(FnbSale::class, 'book_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'book_id');
    }
}

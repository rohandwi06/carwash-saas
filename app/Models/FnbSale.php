<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FnbSale extends Model
{
    protected $fillable = ['transaction_id', 'payment_method', 'total', 'tip', 'date',
        'book_id', 'created_by',
        'voided_at', 'void_reason', 'voided_by',
        'void_requested_at', 'void_requested_by', 'void_request_reason',
        'void_rejected_at', 'void_rejected_by', 'void_reject_reason'];

    protected $casts = [
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'              => 'date:Y-m-d',
        'tip'               => 'integer',
        'voided_at'         => 'datetime',
        'void_requested_at' => 'datetime',
        'void_rejected_at'  => 'datetime',
    ];

    protected $appends = ['void_status'];

    public function items(): HasMany
    {
        return $this->hasMany(FnbSaleItem::class);
    }

    /** Terisi bila F&B dipesan dari kasir cuci; null bila penjualan berdiri sendiri. */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Hanya penjualan sah — dipakai semua rekap uang, sejalan dengan
     * Transaction::valid(). Dua hal menggugurkan sebuah penjualan F&B:
     *  1. penjualan ITU SENDIRI dibatalkan (voided_at terisi);
     *  2. transaksi cuci yang menaunginya dibatalkan — F&B yang menempel
     *     ikut gugur, karena resinya memang satu.
     * Penjualan berdiri sendiri (transaction_id NULL) hanya tunduk pada (1).
     *
     * Pengajuan pembatalan yang belum disetujui owner TETAP dihitung di sini:
     * angka rekap baru berubah setelah owner memutuskan.
     */
    public function scopeValid($query)
    {
        return $query->whereNull('voided_at')
            ->whereDoesntHave(
                'transaction',
                fn ($q) => $q->whereNotNull('voided_at')
            );
    }

    /** Pengajuan pembatalan yang menunggu keputusan owner. */
    public function scopePendingVoid($query)
    {
        return $query->whereNull('voided_at')
            ->whereNotNull('void_requested_at')
            ->whereNull('void_rejected_at');
    }

    /** aktif | menunggu | batal | ditolak */
    public function getVoidStatusAttribute(): string
    {
        if ($this->voided_at !== null) {
            return 'batal';
        }
        if ($this->void_rejected_at !== null) {
            return 'ditolak';
        }

        return $this->void_requested_at !== null ? 'menunggu' : 'aktif';
    }
}

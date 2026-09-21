<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    // 'is_bonus' sengaja TIDAK di sini: fitur cuci gratis sudah dihapus,
    // jadi kolomnya tidak boleh diisi lagi — hanya dibaca untuk riwayat lama.
    protected $fillable = [
        'queue_no', 'vehicle_name', 'category', 'service',
        'payment_method', 'plate', 'tip', 'total', 'date',
        'book_id',
        'voided_at', 'void_reason', 'voided_by', 'created_by',
        'void_requested_at', 'void_requested_by', 'void_request_reason',
        'void_rejected_at', 'void_rejected_by', 'void_reject_reason',
        'edited_at', 'edited_by', 'edit_reason', 'edit_count',
    ];

    protected $casts = [
        'is_bonus'          => 'boolean', // hanya untuk membaca transaksi lama
        // ':Y-m-d' mencegah tanggal mundur satu hari saat dibaca frontend —
        // lihat catatan lengkap di App\Models\CashBook.
        'date'              => 'date:Y-m-d',
        'voided_at'         => 'datetime',
        'void_requested_at' => 'datetime',
        'void_rejected_at'  => 'datetime',
        'edited_at'         => 'datetime',
    ];

    /** Dikirim ke frontend supaya tidak perlu menebak status dari 3 kolom. */
    protected $appends = ['void_status'];

    /**
     * Hanya transaksi sah (belum dibatalkan) — dipakai semua rekap uang.
     * Pengajuan pembatalan yang belum disetujui owner TETAP dihitung di sini:
     * angka rekap baru berubah setelah owner memutuskan.
     */
    public function scopeValid($query)
    {
        return $query->whereNull('voided_at');
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

    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class)->withPivot('wage_share');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(CashBook::class, 'book_id');
    }

    /** Add-on yang dipilih; nama & harga diambil dari pivot (salinan saat transaksi dibuat). */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class)->withPivot('name', 'price');
    }

    /** Makanan/minuman yang dipesan bersamaan dengan cucian ini. */
    public function fnbSales(): HasMany
    {
        return $this->hasMany(FnbSale::class);
    }
}

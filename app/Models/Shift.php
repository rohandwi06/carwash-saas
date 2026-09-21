<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu blok jam kerja toko, mis. "Pagi 07:00–14:00".
 * Satu hari boleh punya beberapa shift; owner mengelolanya di Pengaturan.
 */
class Shift extends Model
{
    protected $fillable = ['name', 'start_time', 'end_time', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /** Urutan tampil & pencarian shift berikutnya selalu dari jam mulai. */
    public function scopeUrut($query)
    {
        return $query->orderBy('start_time')->orderBy('id');
    }

    /**
     * Apakah jam "HH:MM" berada di dalam shift ini?
     * Shift yang melewati tengah malam (mis. 20:00–02:00) jamnya "membungkus",
     * jadi syaratnya OR, bukan AND.
     */
    public function mencakup(string $jam): bool
    {
        return $this->start_time <= $this->end_time
            ? ($jam >= $this->start_time && $jam < $this->end_time)
            : ($jam >= $this->start_time || $jam < $this->end_time);
    }

    /** "Pagi (07:00–14:00)" — dipakai di pesan error & layar terkunci. */
    public function label(): string
    {
        return $this->name.' ('.$this->start_time.'–'.$this->end_time.')';
    }
}

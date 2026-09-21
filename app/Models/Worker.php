<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worker extends Model
{
    protected $fillable = [
        'name', 'is_present', 'is_trainee',
        'nik', 'birth_date', 'birth_place', 'phone', 'address',
    ];

    protected $casts = [
        'is_present' => 'boolean',
        'is_trainee' => 'boolean',
        // ':Y-m-d' mencegah tanggal lahir mundur satu hari saat dibaca
        // frontend — lihat catatan lengkap di App\Models\CashBook. ->age di
        // bawah tetap berfungsi karena PHP tetap menerima objek Carbon utuh;
        // yang berubah hanya bentuknya saat dikirim sebagai JSON.
        'birth_date' => 'date:Y-m-d',
    ];

    /** Umur ikut terkirim ke layar tanpa perlu diminta terpisah. */
    protected $appends = ['age'];

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class)->withPivot('wage_share');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(WorkerDeposit::class);
    }

    /**
     * Umur dalam tahun — DIHITUNG, bukan disimpan. Kolom umur akan membusuk
     * diam-diam setiap kali seseorang berulang tahun; tanggal lahir tidak.
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birth_date?->age);
    }
}

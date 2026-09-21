<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Hanya nama yang wajib — pekerja baru sering didaftarkan buru-buru saat
     * hari sibuk, biodatanya menyusul. Memaksa semuanya terisi di depan malah
     * membuat pencatatan cucian tertunda.
     */
    public function rules(): array
    {
        return self::aturan();
    }

    /**
     * Dipakai bersama store & update supaya barisnya tidak ditulis dua kali
     * lalu melenceng satu sama lain.
     *
     * @param  int|null  $abaikanId  id yang dikecualikan dari cek unik (saat update)
     */
    public static function aturan(?int $abaikanId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:60',
                Rule::unique('workers', 'name')->ignore($abaikanId)],

            // NIK KTP = 16 digit. Divalidasi supaya salah ketik ketahuan saat
            // diinput, bukan saat dipakai untuk urusan administrasi.
            'nik' => ['nullable', 'digits:16',
                Rule::unique('workers', 'nik')->ignore($abaikanId)],

            'is_trainee'  => ['sometimes', 'boolean'],
            'birth_date'  => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:60'],
            'phone'       => ['nullable', 'string', 'max:24'],
            'address'     => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return self::pesan();
    }

    /**
     * Statik seperti aturan(), karena update memakai $request->validate()
     * yang TIDAK membaca messages() milik FormRequest. Tanpa ini, menambah
     * pekerja berbalas bahasa Indonesia sedangkan mengubahnya berbalas
     * bahasa Inggris — untuk aturan yang persis sama.
     */
    public static function pesan(): array
    {
        return [
            'name.required'     => 'Nama pekerja tidak boleh kosong.',
            'name.unique'       => 'Sudah ada pekerja dengan nama itu.',
            'nik.digits'        => 'NIK harus 16 angka sesuai KTP.',
            'nik.unique'        => 'NIK itu sudah terdaftar atas nama pekerja lain.',
            'birth_date.before' => 'Tanggal lahir tidak boleh hari ini atau nanti.',
            'birth_date.date_format' => 'Tanggal lahir tidak terbaca.',
        ];
    }
}

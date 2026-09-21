<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Koreksi transaksi cuci dari menu Pembukuan — owner saja.
 *
 * Aturannya sengaja kembar dengan StoreTransactionRequest, hanya saja semua
 * ruas jadi 'sometimes' (koreksi boleh menyentuh satu ruas saja) dan ada
 * satu tambahan yang tidak ada saat mencatat: alasan.
 *
 * Sama seperti saat mencatat, TIDAK ADA ruas "total" — server yang menghitung
 * dari katalog harga. Kalau ada, layar bisa menetapkan omzet sendiri.
 */
class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_name'   => ['sometimes', 'string', 'max:100'],
            'category'       => ['sometimes', Rule::exists('wash_categories', 'slug')],
            'service'        => ['sometimes', Rule::exists('wash_services', 'slug')],
            'payment_method' => ['sometimes', Rule::in(['cash', 'tf'])],
            'plate'          => ['sometimes', 'nullable', 'string', 'max:20'],
            'tip'            => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'worker_ids'     => ['sometimes', 'array'],
            'worker_ids.*'   => ['integer', 'exists:workers,id'],
            'addon_ids'      => ['sometimes', 'array'],
            'addon_ids.*'    => ['integer', 'exists:addons,id'],
            // Wajib, sepanjang alasan pembatalan. Koreksi mengubah angka omzet
            // tanpa meninggalkan bekas yang terlihat di riwayat (tidak seperti
            // void yang barisnya tercoret), jadi alasannya justru lebih perlu.
            'edit_reason'    => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'edit_reason.required' => 'Alasan koreksi wajib diisi.',
        ];
    }
}

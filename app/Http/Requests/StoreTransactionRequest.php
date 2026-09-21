<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_name'   => ['required', 'string', 'max:100'],
            'category'       => ['required', Rule::exists('wash_categories', 'slug')],
            'service'        => ['sometimes', Rule::exists('wash_services', 'slug')],
            'payment_method' => ['required', Rule::in(['cash', 'tf'])],
            'plate'          => ['nullable', 'string', 'max:20'],
            'tip'            => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'worker_ids'     => ['sometimes', 'array'],
            'worker_ids.*'   => ['integer', 'exists:workers,id'],
            'addon_ids'      => ['sometimes', 'array'],
            'addon_ids.*'    => ['integer', 'exists:addons,id'],
            // Makanan/minuman yang dipesan bareng cucian (opsional).
            'fnb_items'              => ['sometimes', 'array'],
            'fnb_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'fnb_items.*.qty'        => ['required', 'integer', 'min:1', 'max:100'],
            // Draft yang dipakai; dihapus otomatis setelah transaksi tersimpan.
            'draft_id'       => ['sometimes', 'integer', 'exists:transaction_drafts,id'],
            // Perhatikan: TIDAK ADA field "total" — server yang menghitung.
        ];
    }
}

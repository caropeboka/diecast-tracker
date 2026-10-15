<?php

namespace App\Http\Requests;

use App\Models\Diecast;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiecastRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:999999999999'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'series' => ['nullable', 'string', 'max:255'],
            'collection_number' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'scale' => ['nullable', 'string', 'max:20'],
            'color' => ['nullable', 'string', 'max:100'],
            'rarity' => ['required', Rule::in(Diecast::RARITIES)],
            'packaging' => ['required', Rule::in(Diecast::PACKAGINGS)],
            'condition' => ['nullable', Rule::in(Diecast::CONDITIONS)],
            'status' => ['required', Rule::in(Diecast::STATUSES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'purchase_price' => $money,
            'market_value' => $money,
            'target_price' => $money,
            'purchase_date' => ['nullable', 'date'],
            'purchase_place' => ['nullable', 'string', 'max:255'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'ai_scanned' => ['boolean'],
            'photos' => ['array', 'max:8'],
            'photos.*' => ['string', 'max:255'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'scan_item_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Diecast attributes only (no photos/tags/scan item).
     *
     * @return array<string, mixed>
     */
    public function diecastData(): array
    {
        return collect($this->validated())->except(['photos', 'tags', 'scan_item_id'])->all();
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'quantity' => 'jumlah',
            'purchase_price' => 'harga beli',
            'market_value' => 'harga pasaran',
            'target_price' => 'target harga',
            'year' => 'tahun',
            'photos' => 'foto',
        ];
    }
}

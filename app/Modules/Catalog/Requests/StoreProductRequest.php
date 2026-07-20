<?php

namespace App\Modules\Catalog\Requests;

use App\Shared\Enums\PostingTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // Vendor enters naira in the form; the controller converts to kobo.
            'price_naira' => ['required', 'numeric', 'min:100', 'max:100000000'],
            'stock_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'submit' => ['nullable', 'boolean'],
            // Posting tier chosen at submission; ignored while paid mode is off.
            'tier' => ['nullable', Rule::enum(PostingTier::class)],
        ];
    }

    public function priceKobo(): int
    {
        return (int) round(((float) $this->input('price_naira')) * 100);
    }

    public function tier(): PostingTier
    {
        return PostingTier::tryFrom((string) $this->input('tier')) ?? PostingTier::Free;
    }
}

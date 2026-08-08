<?php

namespace App\Modules\Catalog\Requests;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Modules\Catalog\Support\VideoLink;
use App\Shared\Enums\PostingTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A listing has to sit in the most specific category there is.
     *
     * "Electronics" with a Smartphones sub-category underneath it is a
     * heading, not a shelf — a phone filed on the parent never appears when a
     * shopper narrows to Smartphones, and the whole point of having the
     * sub-category is that narrowing works.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $category = Category::query()->find($this->input('category_id'));

            if ($category === null || ! $category->children()->where('is_active', true)->exists()) {
                return;
            }

            $validator->errors()->add(
                'category_id',
                'Choose the sub-category this belongs to — “'.$category->name
                    .'” has more specific ones under it.',
            );
        });

        /*
         * A video link is only worth taking if the product page can play it.
         * Accepting any URL would leave vendors with a link that silently
         * shows nothing, so the field takes exactly what VideoLink can embed
         * and says so when it cannot.
         */
        $validator->after(function ($validator) {
            $url = trim((string) $this->input('video_url'));

            if ($url === '' || VideoLink::isValid($url)) {
                return;
            }

            $validator->errors()->add(
                'video_url',
                'Paste a '.VideoLink::supportedProviders().' link — for example '
                    .'https://www.youtube.com/watch?v=xxxxxxxxxxx',
            );
        });

        /*
         * A struck-through price that is not actually higher is a made-up
         * discount. The product page draws the line through it and prints a
         * saving, so the claim has to be true before it is stored — not
         * quietly dropped at render time where nobody would notice.
         */
        $validator->after(function ($validator) {
            $compareAt = $this->input('compare_at_naira');

            if ($compareAt === null || $compareAt === '' || ! is_numeric($compareAt)) {
                return;
            }

            if ((float) $compareAt > (float) $this->input('price_naira')) {
                return;
            }

            $validator->errors()->add(
                'compare_at_naira',
                'The regular price has to be higher than the price you are selling at.',
            );
        });
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // Optional. withValidator() narrows this to the providers the
            // product page can actually embed.
            'video_url' => ['nullable', 'string', 'max:2048'],
            // Vendor enters naira in the form; the controller converts to kobo.
            'price_naira' => ['required', 'numeric', 'min:100', 'max:100000000'],
            // The "was" price shown struck through. Optional, and
            // withValidator() refuses one that is not actually higher.
            'compare_at_naira' => ['nullable', 'numeric', 'min:100', 'max:100000000'],
            'stock_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'submit' => ['nullable', 'boolean'],
            // Posting tier chosen at submission; ignored while paid mode is off.
            'tier' => ['nullable', Rule::enum(PostingTier::class)],
            'attributes' => ['nullable', 'array'],
            // The per-field rules are merged in below, once the chosen
            // category tells us which fields actually apply.
            ...$this->attributeRules(),
        ];
    }

    /**
     * Validation for the admin-defined fields belonging to the submitted
     * category.
     *
     * Built from the definitions rather than declared here, so a field staff
     * add in the admin form builder is enforced on the very next submission
     * with no deploy. A category the vendor has not chosen yet contributes
     * nothing, so creating a product before picking one still validates.
     *
     * @return array<string, mixed>
     */
    private function attributeRules(): array
    {
        return app(ProductAttributeService::class)->rules($this->attributeDefinitions());
    }

    /** @return Collection<int, ProductAttribute> */
    public function attributeDefinitions(): Collection
    {
        $category = Category::query()->find($this->input('category_id'));

        return app(ProductAttributeService::class)->forCategory($category);
    }

    /**
     * "Colour is required" rather than "attributes.colour is required".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(ProductAttributeService::class)->attributeNames($this->attributeDefinitions());
    }

    /**
     * The vendor's answers, keyed by field.
     *
     * @return array<string, mixed>
     */
    public function attributeValues(): array
    {
        return (array) $this->input('attributes', []);
    }

    public function priceKobo(): int
    {
        return (int) round(((float) $this->input('price_naira')) * 100);
    }

    /** The "was" price, or null when the vendor is not claiming a discount. */
    public function compareAtKobo(): ?int
    {
        $value = $this->input('compare_at_naira');

        return $value === null || $value === '' || ! is_numeric($value)
            ? null
            : (int) round(((float) $value) * 100);
    }

    public function tier(): PostingTier
    {
        return PostingTier::tryFrom((string) $this->input('tier')) ?? PostingTier::Free;
    }
}

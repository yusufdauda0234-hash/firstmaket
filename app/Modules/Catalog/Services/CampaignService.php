<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Campaign;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\HomeDataService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    /** @return Collection<int, Campaign> */
    public function list(): Collection
    {
        return Campaign::query()->withCount('products')->latest('starts_at')->get();
    }

    public function create(User $actor, array $data): Campaign
    {
        return DB::transaction(function () use ($actor, $data) {
            $campaign = Campaign::query()->create([
                'name' => $data['name'],
                'type' => 'flash',
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_active' => (bool) ($data['is_active'] ?? false),
                'created_by' => $actor->id,
            ]);

            $this->syncProducts($campaign, $data['products'] ?? []);

            HomeDataService::forgetCampaigns();

            return $campaign->load('products');
        });
    }

    public function update(Campaign $campaign, array $data): Campaign
    {
        return DB::transaction(function () use ($campaign, $data) {
            $campaign->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            $this->syncProducts($campaign, $data['products'] ?? []);

            HomeDataService::forgetCampaigns();

            return $campaign->load('products');
        });
    }

    public function deactivate(Campaign $campaign): void
    {
        $campaign->forceFill(['is_active' => false])->save();
        HomeDataService::forgetCampaigns();
    }

    /**
     * A ready-made campaign built from real, currently-approved products —
     * the campaign equivalent of the other screens' StarterTemplates.
     * Unlike those, this cannot ship as static rows: there is nothing to
     * template until a vendor has an approved listing, so this reads the
     * live catalog instead of a fixture.
     */
    public function quickStart(User $actor, int $discountPercent = 10, int $productCount = 4, int $days = 7): Campaign
    {
        $products = Product::query()
            ->approved()
            ->whereDoesntHave('campaigns', fn ($query) => $query->live())
            ->latest('approved_at')
            ->limit($productCount)
            ->get();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'products' => 'No approved products are available for a starter campaign — either none exist yet, or every approved product is already in a live campaign.',
            ]);
        }

        return $this->create($actor, [
            'name' => 'Quick Flash Sale',
            'description' => 'Auto-generated starter campaign. Edit the prices, swap products, or extend it any time.',
            'starts_at' => now(),
            'ends_at' => now()->addDays($days),
            'is_active' => true,
            'products' => $products->map(fn (Product $product) => [
                'product_id' => $product->id,
                // Always at least 1 kobo below the sticker price, whatever
                // the discount math rounds to on a very cheap listing.
                'sale_price_kobo' => max(1, min(
                    $product->price_kobo - 1,
                    (int) round($product->price_kobo * (100 - $discountPercent) / 100),
                )),
                'stock_cap' => null,
            ])->all(),
        ]);
    }

    /** @return array{product: Product, unitPriceKobo: int, campaignProductId: int|null} */
    public function priceFor(Product $product, int $quantity = 1): array
    {
        $entry = DB::table('campaign_products')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_products.campaign_id')
            ->where('campaign_products.product_id', $product->id)
            ->where('campaigns.is_active', true)
            ->where('campaigns.starts_at', '<=', now())
            ->where('campaigns.ends_at', '>', now())
            ->where(function ($query) {
                $query->whereNull('campaign_products.stock_cap')
                    ->orWhereColumn('campaign_products.sold_quantity', '<', 'campaign_products.stock_cap');
            })
            ->orderBy('campaign_products.sale_price_kobo')
            ->select('campaign_products.*')
            ->first();

        if ($entry === null || ($entry->stock_cap !== null && $entry->sold_quantity + $quantity > $entry->stock_cap)) {
            return ['product' => $product, 'unitPriceKobo' => $product->price_kobo, 'campaignProductId' => null];
        }

        return ['product' => $product, 'unitPriceKobo' => (int) $entry->sale_price_kobo, 'campaignProductId' => $entry->id];
    }

    /**
     * Commit campaign stock atomically. Returns false rather than throwing
     * when the cap has been reached: the only caller runs inside the
     * signature-verified webhook transaction, and an uncaught exception
     * there rolls back the whole transaction — including the
     * `webhook_verified_at` write that makes the webhook idempotent, which
     * would leave Paystack retrying a paid session forever. A lost race just
     * drops that line the same way an ordinary stock-out does.
     */
    public function reserve(int $campaignProductId, int $quantity): bool
    {
        $quantity = max(1, $quantity);
        $updated = DB::table('campaign_products')
            ->where('id', $campaignProductId)
            ->where(function ($query) {
                $query->whereNull('stock_cap')
                    ->orWhereColumn('sold_quantity', '<', 'stock_cap');
            })
            ->whereRaw('(stock_cap IS NULL OR sold_quantity + ? <= stock_cap)', [$quantity])
            ->update(['sold_quantity' => DB::raw('sold_quantity + '.$quantity), 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        HomeDataService::forgetCampaigns();

        return true;
    }

    /** @param array<int, array{product_id: int, sale_price_kobo: int, stock_cap?: int|null}> $products */
    private function syncProducts(Campaign $campaign, array $products): void
    {
        $ids = collect($products)->pluck('product_id')->all();
        $approved = Product::query()->approved()->whereIn('id', $ids)->pluck('id')->all();
        if (count($approved) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['products' => 'Campaigns may contain approved products only.']);
        }

        $soldQuantities = DB::table('campaign_products')
            ->where('campaign_id', $campaign->id)
            ->pluck('sold_quantity', 'product_id');
        $payload = [];
        foreach ($products as $product) {
            $price = (int) $product['sale_price_kobo'];
            $base = Product::query()->whereKey($product['product_id'])->value('price_kobo');
            if ($price <= 0 || $price >= $base) {
                throw ValidationException::withMessages(['products' => 'Every campaign price must be below the product price.']);
            }
            $payload[$product['product_id']] = [
                'sale_price_kobo' => $price,
                'stock_cap' => $product['stock_cap'] ?? null,
                'sold_quantity' => (int) ($soldQuantities[$product['product_id']] ?? 0),
            ];
        }
        $campaign->products()->sync($payload);
    }
}
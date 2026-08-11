<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Campaign;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CampaignService;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(CampaignService $service): Response
    {
        // How many approved products a quick-start would actually pick up
        // right now — read live rather than templated, because there is
        // nothing to template until a vendor has an approved listing.
        $quickStartCount = min(4, Product::query()
            ->approved()
            ->whereDoesntHave('campaigns', fn ($query) => $query->live())
            ->count());

        return Inertia::render('Admin/Merchandising/Campaigns', [
                'campaigns' => $service->list()->load('products')->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'startsAt' => $campaign->starts_at->toDateTimeString(),
                'endsAt' => $campaign->ends_at->toDateTimeString(),
                'isActive' => $campaign->is_active,
                'productCount' => $campaign->products_count,
                    'products' => $campaign->products->map(fn (Product $product) => [
                        'productId' => $product->id,
                        'salePriceKobo' => (int) $product->pivot->sale_price_kobo,
                        'stockCap' => $product->pivot->stock_cap === null ? null : (int) $product->pivot->stock_cap,
                    ])->values(),
            ]),
            'products' => Product::query()->approved()->orderBy('name')->get(['id', 'name', 'price_kobo'])->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'priceKobo' => $product->price_kobo,
            ]),
            'quickStartTemplates' => $quickStartCount > 0 ? [[
                'key' => 'quick',
                'name' => 'Quick flash sale',
                'summary' => "{$quickStartCount} of your approved products, 10% off, running 7 days. Edit afterwards.",
                'count' => $quickStartCount,
            ]] : [],
        ]);
    }

    public function quickStart(Request $request, CampaignService $service, AuditLoggerContract $audit): RedirectResponse
    {
        $campaign = $service->quickStart($request->user());

        $audit->log(
            actor: $request->user(),
            subject: $campaign,
            action: 'admin.campaign_quick_started',
            newValues: ['name' => $campaign->name, 'product_count' => $campaign->products()->count()],
        );

        return back()->with('success', 'Starter campaign created — edit the prices below any time.');
    }

    public function store(Request $request, CampaignService $service, AuditLoggerContract $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $campaign = $service->create($request->user(), $data);
        $audit->log(actor: $request->user(), subject: $campaign, action: 'admin.campaign_created', newValues: ['name' => $campaign->name]);

        return back()->with('success', 'Campaign created.');
    }

    public function update(Request $request, Campaign $campaign, CampaignService $service): RedirectResponse
    {
        $service->update($campaign, $this->validated($request));

        return back()->with('success', 'Campaign updated.');
    }

    public function destroy(Campaign $campaign, CampaignService $service): RedirectResponse
    {
        $service->deactivate($campaign);

        return back()->with('success', 'Campaign switched off.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
            'products' => ['array'],
            'products.*.product_id' => ['required', 'integer', 'distinct'],
            'products.*.sale_price_kobo' => ['required', 'integer', 'min:1'],
            'products.*.stock_cap' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
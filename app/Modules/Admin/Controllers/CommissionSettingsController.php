<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Catalog\Models\Category;
use App\Modules\Orders\Models\CategoryCommissionRate;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-category commission rates (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6). Rate history is append-only — saving writes a new row with
 * effective_from = now; existing orders keep their snapshots.
 */
class CommissionSettingsController extends Controller
{
    public function edit(): Response
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'ratePercent' => CategoryCommissionRate::activeFor($category->id)?->rate_percent,
            ]);

        return Inertia::render('Admin/Settings/Commissions', [
            'categories' => $categories,
            'defaultRatePercent' => (float) Setting::get('orders.default_commission_percent', 10),
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:50'],
        ]);

        $rate = CategoryCommissionRate::query()->create([
            'category_id' => $validated['category_id'],
            'rate_percent' => number_format((float) $validated['rate_percent'], 2, '.', ''),
            'effective_from' => now(),
            'set_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        $auditLogger->log(actor: $request->user(), subject: $rate, action: 'orders.commission_rate_set', newValues: [
            'category_id' => $rate->category_id,
            'rate_percent' => $rate->rate_percent,
        ]);

        return back()->with('success', 'Commission rate updated — existing orders keep their snapshot.');
    }
}

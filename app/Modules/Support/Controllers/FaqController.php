<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\Faq;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public FAQ page (docs/FirstMaket_Implementation_Plan.md Sprint 7) —
 * no login required, linked from the storefront footer.
 */
class FaqController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Faq', [
            'faqs' => Faq::query()->published()->get(['id', 'category', 'question', 'answer'])
                ->groupBy('category')
                ->map(fn ($group) => $group->values())
                ->toArray(),
            'whatsappNumber' => (string) config('services.support.whatsapp'),
            'hotlineNumber' => (string) config('services.support.hotline'),
        ]);
    }
}

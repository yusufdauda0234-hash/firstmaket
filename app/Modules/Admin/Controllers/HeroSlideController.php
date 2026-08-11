<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Catalog\Models\HeroSlide;
use App\Modules\Catalog\Services\HomeDataService;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HeroSlideController extends Controller
{
    private const CTA_TARGETS = ['auth_gate', 'catalog', 'vendor_register'];

    private const OFFER_TYPES = ['from_price', 'campaign_discount', 'static'];

    private const THEMES = ['brand', 'brand-reverse', 'brand-deep', 'sunset', 'emerald'];

    public function index(): Response
    {
        return Inertia::render('Admin/Merchandising/HeroSlides', [
            'slides' => HeroSlide::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (HeroSlide $slide) => $this->present($slide)),
            'templates' => StarterTemplates::forDisplay(StarterTemplates::heroSlides()),
        ]);
    }

    /**
     * Lay down a ready-made set of slides. Skips entirely if any slide
     * already exists — unlike the row-level templates elsewhere (delivery
     * rates, promo codes), a carousel is an ordered set: merging a template
     * into slides an admin has already written would produce an order
     * nobody chose.
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::heroSlides();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        if (HeroSlide::query()->exists()) {
            return back()->with('error', 'Slides already exist — templates only apply to an empty carousel. Delete the existing slides first if you want to start over.');
        }

        foreach ($templates[$validated['template']]['rows'] as $row) {
            HeroSlide::query()->create($row + ['is_active' => true, 'updated_by' => $request->user()->id]);
        }

        HomeDataService::forget();

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.hero_slides_template_applied',
            newValues: ['template' => $validated['template']],
        );

        return back()->with('success', 'Hero slides added. Edit any of them below.');
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $slide = HeroSlide::query()->create($this->validated($request) + ['updated_by' => $request->user()->id]);

        HomeDataService::forget();

        $auditLogger->log(
            actor: $request->user(),
            subject: $slide,
            action: 'admin.hero_slide_created',
            newValues: $slide->only(['title', 'offer_type', 'is_active']),
        );

        return back()->with('success', 'Hero slide added.');
    }

    public function update(Request $request, HeroSlide $heroSlide, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $before = $heroSlide->only(['title', 'offer_type', 'is_active', 'sort_order']);

        $heroSlide->update($this->validated($request) + ['updated_by' => $request->user()->id]);

        HomeDataService::forget();

        $auditLogger->log(
            actor: $request->user(),
            subject: $heroSlide,
            action: 'admin.hero_slide_updated',
            oldValues: $before,
            newValues: $heroSlide->only(['title', 'offer_type', 'is_active', 'sort_order']),
        );

        return back()->with('success', 'Hero slide updated.');
    }

    public function destroy(Request $request, HeroSlide $heroSlide, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $auditLogger->log(actor: $request->user(), subject: $heroSlide, action: 'admin.hero_slide_deleted');

        $heroSlide->delete();

        HomeDataService::forget();

        return back()->with('success', 'Hero slide removed.');
    }

    /** @return array<string, mixed> */
    private function present(HeroSlide $slide): array
    {
        return [
            'id' => $slide->id,
            'eyebrow' => $slide->eyebrow,
            'title' => $slide->title,
            'description' => $slide->description,
            'ctaLabel' => $slide->cta_label,
            'ctaTarget' => $slide->cta_target,
            'theme' => $slide->theme,
            'emoji' => $slide->emoji,
            'offerType' => $slide->offer_type,
            'offerLabel' => $slide->offer_label,
            'offerValue' => $slide->offer_value,
            'isActive' => $slide->is_active,
            'sortOrder' => $slide->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'eyebrow' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:300'],
            'cta_label' => ['required', 'string', 'max:40'],
            'cta_target' => ['required', 'string', Rule::in(self::CTA_TARGETS)],
            'theme' => ['required', 'string', Rule::in(self::THEMES)],
            'emoji' => ['required', 'string', 'max:8'],
            'offer_type' => ['required', 'string', Rule::in(self::OFFER_TYPES)],
            'offer_label' => ['required', 'string', 'max:40'],
            'offer_value' => ['nullable', 'required_if:offer_type,static', 'string', 'max:40'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
        ]);

        // A computed offer never carries a stored figure — otherwise a slide
        // edited away from 'static' would leave a stale value sitting in the
        // row, ready to reappear if it were switched back.
        if ($validated['offer_type'] !== 'static') {
            $validated['offer_value'] = null;
        }

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        return $validated;
    }
}

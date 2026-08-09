<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\ContentPage;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Legal pages: the terms, the privacy policy, the data-deletion instructions,
 * and any other page the business wants to publish.
 *
 * The point of this screen is that correcting a policy is an edit, not a
 * deploy. The wording of a privacy notice changes when the business changes —
 * a new payment method, a new document collected at vendor sign-up — and
 * waiting on a release to say so is how a site ends up describing what it
 * used to do.
 *
 * Sits behind settings.manage, which until now was declared in the seeder and
 * enforced nowhere.
 */
class ContentPageController extends Controller
{
    public function index(): Response
    {
        $pages = ContentPage::query()
            ->orderByDesc('is_system')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (ContentPage $page) => [
                'uuid' => $page->uuid,
                'slug' => $page->slug,
                'title' => $page->title,
                'summary' => $page->summary,
                'sections' => $this->sectionsForEditing($page),
                'isPublished' => $page->is_published,
                'showInFooter' => $page->show_in_footer,
                'sortOrder' => $page->sort_order,
                'isSystem' => $page->isSystem(),
                'effectiveAt' => $page->effective_at?->toDateString(),
                'updatedAt' => $page->updated_at?->toDateString(),
                // The live URL, so the admin can open exactly what a visitor
                // sees rather than guessing how the slug maps to a path.
                'url' => $page->url(),
            ]);

        return Inertia::render('Admin/Settings/ContentPages', [
            'pages' => $pages,
            // What Google and Meta fetch. Shown on the screen because the
            // whole reason these three pages exist is to be pasted into those
            // consoles, and a wrong URL there fails a review silently.
            'requiredSlugs' => ContentPage::SYSTEM_SLUGS,
        ]);
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $page = ContentPage::query()->create(
            $this->validated($request) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $page,
            action: 'admin.content_page_created',
            newValues: ['slug' => $page->slug, 'title' => $page->title, 'is_published' => $page->is_published],
        );

        return back()->with('success', $page->title.' created.');
    }

    public function update(Request $request, ContentPage $contentPage, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $before = $contentPage->only(['slug', 'title', 'is_published']);

        $contentPage->update(
            $this->validated($request, $contentPage) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $contentPage,
            action: 'admin.content_page_updated',
            oldValues: $before,
            newValues: $contentPage->only(['slug', 'title', 'is_published']),
        );

        return back()->with('success', $contentPage->title.' saved.');
    }

    /**
     * Delete a page. System pages are unpublished instead.
     *
     * Deleting the privacy policy would 404 a URL that is registered with
     * Meta, and nothing in this application would report that sign-in had
     * stopped working — the failure surfaces in their console. Unpublishing
     * is the same outcome for visitors and is reversible in one click.
     */
    public function destroy(Request $request, ContentPage $contentPage, AuditLoggerContract $auditLogger): RedirectResponse
    {
        if ($contentPage->isSystem()) {
            $contentPage->forceFill(['is_published' => false])->save();

            $auditLogger->log(
                actor: $request->user(),
                subject: $contentPage,
                action: 'admin.content_page_unpublished',
            );

            return back()->with(
                'success',
                $contentPage->title.' unpublished. It cannot be deleted — Google and Meta hold this exact URL, '
                    .'so the page has to keep existing even when it is switched off.',
            );
        }

        $title = $contentPage->title;

        $auditLogger->log(
            actor: $request->user(),
            subject: $contentPage,
            action: 'admin.content_page_deleted',
            oldValues: ['slug' => $contentPage->slug, 'title' => $title],
        );

        $contentPage->delete();

        return back()->with('success', $title.' deleted.');
    }

    /**
     * Sections in the shape the editor posts back, so a page that has never
     * been edited still opens with one empty row rather than nothing at all.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    private function sectionsForEditing(ContentPage $page): array
    {
        $sections = collect($page->sections ?? [])
            ->map(fn (array $section) => [
                'heading' => (string) ($section['heading'] ?? ''),
                'body' => (string) ($section['body'] ?? ''),
            ])
            ->all();

        return $sections === [] ? [['heading' => '', 'body' => '']] : $sections;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ContentPage $existing = null): array
    {
        $lockedSlug = $existing?->isSystem() === true;

        $slugRules = [
            Rule::excludeIf($lockedSlug),
            'required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('content_pages', 'slug')->ignore($existing?->id),
        ];

        if ($existing === null) {
            // Reserving these on create stops somebody producing a second
            // "privacy-policy" page under /legal/ that shadows nothing but
            // confuses everyone. Added conditionally: Rule::notIn([]) compiles
            // to a bare "not_in:" that rejects the empty string instead of
            // nothing.
            $slugRules[] = Rule::notIn(ContentPage::SYSTEM_SLUGS);
        }

        $validated = $request->validate([
            'slug' => $slugRules,
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'sections' => ['required', 'array', 'min:1', 'max:40'],
            'sections.*.heading' => ['nullable', 'string', 'max:150'],
            'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'is_published' => ['boolean'],
            'show_in_footer' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'effective_at' => ['nullable', 'date'],
        ], [
            'slug.regex' => 'Use lower-case letters, numbers and hyphens — this becomes the web address.',
            'slug.not_in' => 'That address is reserved for one of the built-in pages, which already exists.',
        ]);

        $sections = collect($validated['sections'])
            ->map(fn (array $section) => [
                'heading' => trim((string) ($section['heading'] ?? '')),
                'body' => trim((string) ($section['body'] ?? '')),
            ])
            // Empty rows are the ones the admin added and did not fill in.
            ->reject(fn (array $section) => $section['heading'] === '' && $section['body'] === '')
            ->values()
            ->all();

        $publish = (bool) ($validated['is_published'] ?? false);

        /*
         * A published page with nothing on it is worse than no page.
         * It answers 200, so a provider's checker passes it and the auth
         * panel goes on telling customers they have accepted terms — while
         * the page itself is a heading over white space. Saving a blank
         * draft is fine; publishing one is not.
         */
        if ($publish && $this->hasNoBody($sections)) {
            throw ValidationException::withMessages([
                'sections' => 'Write something before publishing — a published page with no text still returns a valid page to Google and Meta, and to anyone told they have agreed to it.',
            ]);
        }

        $attributes = [
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?? null,
            'sections' => $sections,
            'is_published' => $publish,
            'show_in_footer' => (bool) ($validated['show_in_footer'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'effective_at' => $validated['effective_at'] ?? $existing?->effective_at,
        ];

        if (! $lockedSlug) {
            $attributes['slug'] = Str::lower($validated['slug']);
        }

        return $attributes;
    }

    /**
     * True when the sections amount to headings and nothing else.
     *
     * Checked against the same parser the public page uses, so "publishable"
     * and "renders something" cannot disagree: a body of only whitespace, or
     * of bullet markers with no text after them, produces no blocks.
     *
     * @param  array<int, array{heading: string, body: string}>  $sections
     */
    private function hasNoBody(array $sections): bool
    {
        foreach ($sections as $section) {
            if (ContentPage::blocks($section['body']) !== []) {
                return false;
            }
        }

        return true;
    }
}

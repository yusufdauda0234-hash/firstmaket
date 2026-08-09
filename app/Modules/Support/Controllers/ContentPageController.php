<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\ContentPage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public side of the admin-editable pages.
 *
 * The three system pages answer on fixed top-level URLs because those exact
 * strings are typed into the Google and Meta consoles; every other page is
 * served from /legal/{slug}.
 */
class ContentPageController extends Controller
{
    /** An index of everything published, so no page is reachable only by luck. */
    public function index(): Response
    {
        $pages = ContentPage::query()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (ContentPage $page) => [
                'title' => $page->title,
                'summary' => $page->summary,
                'url' => $page->url(),
            ]);

        return Inertia::render('Public/LegalIndex', ['pages' => $pages]);
    }

    /**
     * /legal/{slug} for ordinary pages.
     *
     * A system slug redirects to its top-level URL rather than rendering
     * here. Two URLs serving identical text splits whatever ranking the page
     * earns and leaves ambiguity about which one is "the" privacy policy —
     * and the answer to that has to be the URL registered with Meta.
     */
    public function show(string $slug): Response|RedirectResponse
    {
        if (isset(ContentPage::CANONICAL_ROUTES[$slug])) {
            return redirect(route(ContentPage::CANONICAL_ROUTES[$slug], absolute: false));
        }

        return $this->render($slug);
    }

    public function terms(): Response
    {
        return $this->render('terms');
    }

    public function privacy(): Response
    {
        return $this->render('privacy-policy');
    }

    public function dataDeletion(): Response
    {
        return $this->render('data-deletion');
    }

    /** Send /privacy to the canonical /privacy-policy — it is a common guess. */
    public function privacyAlias(): RedirectResponse
    {
        return redirect(route('legal.privacy', absolute: false));
    }

    private function render(string $slug): Response
    {
        $page = ContentPage::query()->published()->where('slug', $slug)->first();

        // 404 rather than an empty shell. An unpublished or missing policy
        // must fail loudly: a page that renders a heading and no terms still
        // returns 200, so a provider's checker would pass it and a customer
        // would be told they had accepted something blank.
        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Public/LegalPage', [
            'page' => [
                'title' => $page->title,
                'summary' => $page->summary,
                'sections' => $page->renderedSections(),
                'effectiveAt' => $page->effective_at?->toDateString(),
                'updatedAt' => $page->updated_at?->toDateString(),
                'url' => $page->url(),
            ],
        ]);
    }
}

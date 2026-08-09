<?php

use App\Modules\Support\Models\ContentPage;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The public side of the admin-editable legal pages.
 *
 * The URLs matter as much as the content: /terms, /privacy-policy and
 * /data-deletion are typed into the Google and Meta consoles, and a rename
 * or a 404 breaks social sign-in somewhere this application cannot see.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function publishLegalPage(string $slug, array $overrides = []): ContentPage
{
    return ContentPage::query()->create(array_merge([
        'slug' => $slug,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'sections' => [['heading' => 'A heading', 'body' => 'Some body text.']],
        'is_published' => true,
    ], $overrides));
}

// ── The URLs outside services hold ──────────────────────────────────────

it('serves the three provider URLs once seeded', function () {
    $this->seed(ContentPageSeeder::class);

    foreach (['/terms', '/privacy-policy', '/data-deletion'] as $path) {
        $this->get($path)->assertOk();
    }
});

it('seeds all three pages published, or a provider review fails', function () {
    $this->seed(ContentPageSeeder::class);

    foreach (ContentPage::SYSTEM_SLUGS as $slug) {
        $page = ContentPage::query()->where('slug', $slug)->first();

        expect($page)->not->toBeNull("the {$slug} page was not seeded")
            ->and($page->is_published)->toBeTrue("the {$slug} page was seeded unpublished")
            ->and($page->is_system)->toBeTrue("the {$slug} page is not locked");
    }
});

it('404s a page that is not published rather than serving an empty shell', function () {
    publishLegalPage('terms', ['is_published' => false]);

    // A 200 with a heading and no terms would pass a provider's checker and
    // would still be shown to a customer told they had accepted it.
    $this->get('/terms')->assertNotFound();
});

it('404s a page that does not exist at all', function () {
    $this->get('/terms')->assertNotFound();
    $this->get('/legal/nothing-here')->assertNotFound();
});

it('gives each page exactly one address', function () {
    publishLegalPage('privacy-policy');

    // Reaching the privacy policy under /legal/ as well would split the page
    // between two URLs, and only one of them is registered with Meta.
    $this->get('/legal/privacy-policy')->assertRedirect('/privacy-policy');
    $this->get('/privacy')->assertRedirect('/privacy-policy');
});

it('serves an ordinary page from /legal/{slug}', function () {
    publishLegalPage('returns-policy');

    $this->get('/legal/returns-policy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/LegalPage')
            ->where('page.title', 'Returns policy'));
});

it('lists only published pages on the index', function () {
    publishLegalPage('returns-policy');
    publishLegalPage('secret-draft', ['is_published' => false]);

    $this->get('/legal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/LegalIndex')
            ->has('pages', 1)
            ->where('pages.0.title', 'Returns policy'));
});

// ── Footer links ────────────────────────────────────────────────────────

it('shares only ticked, published pages as footer links', function () {
    publishLegalPage('terms', ['show_in_footer' => true, 'sort_order' => 1]);
    publishLegalPage('returns-policy', ['show_in_footer' => false]);
    publishLegalPage('draft-page', ['show_in_footer' => true, 'is_published' => false]);

    $this->get('/legal')
        ->assertInertia(fn ($page) => $page
            ->has('legalLinks', 1)
            ->where('legalLinks.0.title', 'Terms')
            ->where('legalLinks.0.url', strtolower(rtrim((string) config('app.url'), '/')).'/terms'));
});

it('refreshes the footer links as soon as a page changes', function () {
    $page = publishLegalPage('returns-policy', ['show_in_footer' => true]);

    // Warm the cache, then change the page behind it.
    $this->get('/legal')->assertInertia(fn ($p) => $p->has('legalLinks', 1));

    $page->update(['is_published' => false]);

    // Without the model's saved() hook this still reads 1 from cache, and the
    // footer of every page on the site links to a 404 for a day.
    $this->get('/legal')->assertInertia(fn ($p) => $p->has('legalLinks', 0));
});

// ── Turning what an admin typed into something renderable ───────────────

it('splits a body into paragraphs and lists', function () {
    expect(ContentPage::blocks("First line.\nSame paragraph.\n\nSecond paragraph."))
        ->toBe([
            ['type' => 'paragraph', 'text' => 'First line. Same paragraph.'],
            ['type' => 'paragraph', 'text' => 'Second paragraph.'],
        ]);

    expect(ContentPage::blocks("Intro:\n\n- One\n- Two"))
        ->toBe([
            ['type' => 'paragraph', 'text' => 'Intro:'],
            ['type' => 'bullets', 'items' => ['One', 'Two']],
        ]);

    expect(ContentPage::blocks("1. First\n2. Second"))
        ->toBe([['type' => 'numbers', 'items' => ['First', 'Second']]]);
});

it('does not mistake a decimal for a list marker', function () {
    // "45.7MP sensor" was read as item 45 of a numbered list once already,
    // in the product bullet-list field. A marker needs whitespace after it.
    expect(ContentPage::blocks('45.7MP sensor, 3.5mm jack'))
        ->toBe([['type' => 'paragraph', 'text' => '45.7MP sensor, 3.5mm jack']]);
});

it('does not run a bulleted list into a numbered one', function () {
    expect(ContentPage::blocks("- One\n1. Two"))
        ->toBe([
            ['type' => 'bullets', 'items' => ['One']],
            ['type' => 'numbers', 'items' => ['Two']],
        ]);
});

it('drops sections the admin left blank', function () {
    $page = publishLegalPage('returns-policy', [
        'sections' => [
            ['heading' => 'Real', 'body' => 'Text.'],
            ['heading' => '', 'body' => '   '],
        ],
    ]);

    expect($page->renderedSections())->toHaveCount(1);
});

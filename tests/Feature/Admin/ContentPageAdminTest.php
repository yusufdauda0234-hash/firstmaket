<?php

use App\Models\User;
use App\Modules\Support\Models\ContentPage;
use App\Shared\Enums\UserType;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The admin Legal pages screen.
 *
 * Behind settings.manage. The rules worth testing are all about the three
 * built-in pages: their URLs live in Google's and Meta's consoles, so this
 * screen must not be able to rename or delete one, and must not be able to
 * publish an empty one.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function pagesAdmin(string $role = 'Super Administrator'): User
{
    $admin = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole($role);

    return $admin;
}

function pagesAdminUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/pages'.$path;
}

/**
 * Absolute, because these assertions follow a request to the admin host.
 *
 * A request sets the global URL root, so a later relative get('/terms')
 * resolves against admin.firstmaket.localhost — where no storefront route is
 * registered, and the 404 looks like the page is broken rather than like the
 * test asking the wrong server.
 */
function storefrontUrl(string $path): string
{
    // Lower-cased to match ContentPage::url(), which canonicalises the host
    // so the address in admin, in the footer and in a provider console agree.
    return strtolower(rtrim((string) config('app.url'), '/')).$path;
}

/**
 * Drop the staff session before checking the storefront.
 *
 * EnsureCorrectPortal logs a staff account out of the customer site, and
 * actingAs() sets the guard for every later request in the test whatever
 * host it names — so without this the assertion tests the middleware rather
 * than the page. A real browser never hits this: ScopeAdminSessionCookie
 * gives the admin portal its own cookie name pinned to the admin host, so a
 * staff member opening /terms arrives as a visitor.
 */
function asVisitor(): void
{
    auth()->guard('web')->logout();
}

function pagePayload(array $overrides = []): array
{
    return array_merge([
        'slug' => 'returns-policy',
        'title' => 'Returns Policy',
        'sections' => [['heading' => 'How returns work', 'body' => 'Tell us within seven days.']],
        'is_published' => true,
    ], $overrides);
}

// ── Access ──────────────────────────────────────────────────────────────

it('shows the pages to an administrator', function () {
    $this->seed(ContentPageSeeder::class);

    $this->actingAs(pagesAdmin())
        ->get(pagesAdminUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/ContentPages')
            ->has('pages', 3)
            ->where('requiredSlugs', ContentPage::SYSTEM_SLUGS));
});

it('shows the storefront address, not the admin one', function () {
    $this->seed(ContentPageSeeder::class);

    // This screen is where an admin goes to find the URL to paste into
    // Google's consent screen and Meta's app review. route() builds on the
    // rendering origin, so it handed back admin.firstmaket.localhost/terms —
    // a 404, submitted to a provider as the privacy policy.
    $this->actingAs(pagesAdmin())
        ->get(pagesAdminUrl())
        ->assertInertia(fn ($page) => $page
            ->where('pages.0.url', storefrontUrl('/terms')));
});

it('keeps a support agent out', function () {
    $this->actingAs(pagesAdmin('Support Agent'))
        ->get(pagesAdminUrl())
        ->assertForbidden();
});

// ── Creating and editing ────────────────────────────────────────────────

it('creates a page and serves it straight away', function () {
    $this->actingAs(pagesAdmin())
        ->post(pagesAdminUrl(), pagePayload())
        ->assertRedirect();

    asVisitor();

    $this->get(storefrontUrl('/legal/returns-policy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('page.title', 'Returns Policy'));
});

it('edits the wording of a built-in page', function () {
    $this->seed(ContentPageSeeder::class);
    $terms = ContentPage::query()->where('slug', 'terms')->firstOrFail();

    $this->actingAs(pagesAdmin())
        ->put(pagesAdminUrl('/'.$terms->uuid), pagePayload([
            'slug' => 'terms',
            'title' => 'Terms of Service',
            'sections' => [['heading' => 'Rewritten', 'body' => 'New wording entirely.']],
        ]))
        ->assertRedirect();

    asVisitor();

    $this->get(storefrontUrl('/terms'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.sections.0.heading', 'Rewritten'));
});

// ── The rules that protect the provider URLs ────────────────────────────

it('will not rename a built-in page', function () {
    $this->seed(ContentPageSeeder::class);
    $terms = ContentPage::query()->where('slug', 'terms')->firstOrFail();

    $this->actingAs(pagesAdmin())
        ->put(pagesAdminUrl('/'.$terms->uuid), pagePayload([
            'slug' => 'terms-and-conditions',
            'title' => 'Terms of Service',
        ]))
        ->assertRedirect();

    // Google holds /terms. A rename would 404 it, and the failure surfaces in
    // their console rather than anywhere visible from here.
    expect($terms->refresh()->slug)->toBe('terms');

    asVisitor();
    $this->get(storefrontUrl('/terms'))->assertOk();
});

it('will not delete a built-in page, only unpublish it', function () {
    $this->seed(ContentPageSeeder::class);
    $privacy = ContentPage::query()->where('slug', 'privacy-policy')->firstOrFail();

    $this->actingAs(pagesAdmin())
        ->delete(pagesAdminUrl('/'.$privacy->uuid))
        ->assertRedirect();

    expect(ContentPage::query()->where('slug', 'privacy-policy')->exists())->toBeTrue()
        ->and($privacy->refresh()->is_published)->toBeFalse();
});

it('deletes an ordinary page outright', function () {
    $page = ContentPage::query()->create(pagePayload());

    $this->actingAs(pagesAdmin())
        ->delete(pagesAdminUrl('/'.$page->uuid))
        ->assertRedirect();

    expect(ContentPage::query()->where('slug', 'returns-policy')->exists())->toBeFalse();
});

it('refuses a second page at a reserved address', function () {
    $this->seed(ContentPageSeeder::class);

    $this->actingAs(pagesAdmin())
        ->post(pagesAdminUrl(), pagePayload(['slug' => 'privacy-policy']))
        ->assertSessionHasErrors('slug');
});

it('refuses an address that is not URL-safe', function () {
    $this->actingAs(pagesAdmin())
        ->post(pagesAdminUrl(), pagePayload(['slug' => 'Returns Policy!']))
        ->assertSessionHasErrors('slug');
});

// ── Publishing something worth publishing ───────────────────────────────

it('refuses to publish a page with no text on it', function () {
    // 200 with a heading and no body passes a provider's checker and tells a
    // customer they have agreed to white space.
    $this->actingAs(pagesAdmin())
        ->post(pagesAdminUrl(), pagePayload([
            'sections' => [['heading' => 'Coming soon', 'body' => '   ']],
        ]))
        ->assertSessionHasErrors('sections');

    expect(ContentPage::query()->count())->toBe(0);
});

it('still allows an empty draft to be saved', function () {
    $this->actingAs(pagesAdmin())
        ->post(pagesAdminUrl(), pagePayload([
            'sections' => [['heading' => 'Coming soon', 'body' => '']],
            'is_published' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect(ContentPage::query()->where('slug', 'returns-policy')->exists())->toBeTrue();
});

// ── The seeder must not undo an edit ────────────────────────────────────

it('never overwrites wording that has been edited', function () {
    $this->seed(ContentPageSeeder::class);

    ContentPage::query()->where('slug', 'terms')->update([
        'sections' => [['heading' => 'Our own wording', 'body' => 'Written by the business.']],
    ]);

    // A deploy that runs db:seed must not revert a legal correction.
    $this->seed(ContentPageSeeder::class);

    expect(ContentPage::query()->where('slug', 'terms')->value('sections'))
        ->toBe([['heading' => 'Our own wording', 'body' => 'Written by the business.']]);
});

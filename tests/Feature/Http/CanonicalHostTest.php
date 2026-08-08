<?php

use App\Models\User;

/**
 * Browsers resolve any *.localhost name to loopback and the webserver answers
 * whatever Host header it gets, so a misspelt domain used to serve the whole
 * site on its own cookie-less origin. These lock the redirect in place.
 */
function misspelledHost(string $canonical): string
{
    // "firstmaket" -> "firstmarket": the exact typo that started this.
    return str_replace('maket', 'market', $canonical);
}

it('redirects a mistyped storefront hostname to the canonical one', function () {
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get('http://'.misspelledHost($canonical).'/cart')
        ->assertRedirect('http://'.$canonical.'/cart');
});

it('keeps the path and query string when it redirects', function () {
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get('http://'.misspelledHost($canonical).'/products?q=rice&page=2')
        ->assertRedirect('http://'.$canonical.'/products?q=rice&page=2');
});

it('redirects even a path that has no route, rather than 404ing on the wrong host', function () {
    // Runs before route matching, so the visitor lands on the canonical host
    // and sees the 404 there instead of being stranded on the typo.
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get('http://'.misspelledHost($canonical).'/no-such-page')
        ->assertRedirect('http://'.$canonical.'/no-such-page');
});

it('never redirects the uptime probe, whatever host it uses', function () {
    // Monitors send whatever Host header they are configured with, and a 302
    // reads as "unhealthy" — so /up is passed straight through.
    //
    // It answers 404 here rather than 200 because this app supplies its own
    // `using:` route closure, which replaces the registration that would have
    // created the `health: '/up'` endpoint. The point of the assertion is the
    // absence of a redirect, which is what the middleware controls.
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get('http://'.misspelledHost($canonical).'/up')
        ->assertNotFound();
});

it('sends a mistyped admin hostname to the admin portal, not the storefront', function () {
    $admin = strtolower((string) config('app.admin_domain'));

    $this->get('http://'.misspelledHost($admin).'/login')
        ->assertRedirect('http://'.$admin.'/login');
});

it('sends a mistyped vendor hostname to the vendor portal', function () {
    $vendor = strtolower((string) config('app.vendor_domain'));

    $this->get('http://'.misspelledHost($vendor).'/login')
        ->assertRedirect('http://'.$vendor.'/login');
});

it('leaves the canonical hosts alone', function () {
    $this->get('http://'.strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'/')
        ->assertOk();

    $this->get('http://'.strtolower((string) config('app.admin_domain')).'/login')
        ->assertOk();

    $this->get('http://'.strtolower((string) config('app.vendor_domain')).'/login')
        ->assertOk();
});

it('still serves the app on a raw IP address', function () {
    // Customer routes carry no domain constraint so they keep working on
    // 127.0.0.1; redirecting that away would break local debugging.
    $this->get('http://127.0.0.1/')->assertOk();
});

it('does not redirect a POST, which would drop the submitted data', function () {
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    // A redirect here would replay the POST as a GET and silently lose the
    // body — webhooks and form submissions must pass straight through.
    $this->post('http://'.misspelledHost($canonical).'/login', [
        'email' => 'nobody@example.test',
        'password' => 'wrong-password',
    ])->assertRedirect(); // back to the form with errors, not a host redirect

    expect(session()->hasOldInput('email'))->toBeTrue();
});

it('does not leak a session cookie to the mistyped origin', function () {
    $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('http://'.misspelledHost($canonical).'/');

    // The redirect happens before StartSession, so nothing is set on the
    // wrong host for a browser to keep.
    expect($response->headers->getCookies())->toBeEmpty();
});

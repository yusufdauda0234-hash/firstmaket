<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * What the app believes about a request that arrived through a load balancer.
 *
 * Azure terminates TLS at its front end and forwards plain HTTP with the
 * original details in X-Forwarded-* headers. Every one of these assertions is
 * something that silently goes wrong when those headers are not trusted, and
 * none of them show up locally — there is no proxy on a laptop, so this is
 * the only place the production behaviour can be checked before deploying.
 */
beforeEach(function () {
    Route::middleware('web')->get('/__proxy-probe', fn (Request $request) => response()->json([
        'secure' => $request->isSecure(),
        'scheme' => $request->getScheme(),
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'url' => $request->fullUrl(),
    ]));
});

it('believes a forwarded request is secure', function () {
    // Without this every generated URL is http://, including the Paystack
    // callback and password reset links.
    $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJsonPath('secure', true)
        ->assertJsonPath('scheme', 'https');
});

it('builds https links for a forwarded request', function () {
    $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertJson(fn ($json) => $json->where('url', fn (string $url) => str_starts_with($url, 'https://'))->etc());
});

it('sees the visitor rather than the load balancer', function () {
    /*
     * The one with the widest blast radius. Untrusted, every request appears
     * to come from Azure's front end: the login audit trail records one IP
     * for everybody, and the rate limiters treat all traffic as a single
     * client — so one person hammering sign-in locks out the whole country.
     */
    $this->get('/__proxy-probe', ['X-Forwarded-For' => '102.89.23.7'])
        ->assertJsonPath('ip', '102.89.23.7');
});

it('honours the forwarded host', function () {
    /*
     * The portals are told apart by hostname. If the app reads the internal
     * one instead, admin and vendor routing stops matching at all.
     *
     * Asked for using the configured admin domain rather than an invented
     * one: EnforceCanonicalHost reads the forwarded host too and redirects
     * anything that is not canonical — which is the behaviour we want in
     * production, and would make this a 302 instead of a JSON body.
     */
    $admin = strtolower((string) config('app.admin_domain'));

    $this->get('/__proxy-probe', ['X-Forwarded-Host' => $admin])
        ->assertOk()
        ->assertJsonPath('host', $admin);
});

it('redirects a forwarded host that is not one of ours', function () {
    // Proof of the above: the canonical-host guard is reading the forwarded
    // value, not the internal one the load balancer used.
    $this->get('/__proxy-probe', ['X-Forwarded-Host' => 'not-firstmaket.example'])
        ->assertRedirect();
});

it('is still plain http when nothing is forwarded', function () {
    // Local development has no proxy and must be unaffected.
    $this->get('/__proxy-probe')
        ->assertJsonPath('secure', false)
        ->assertJsonPath('scheme', 'http');
});

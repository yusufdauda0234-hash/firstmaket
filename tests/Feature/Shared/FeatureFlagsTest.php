<?php

use App\Models\Setting;
use App\Shared\Features;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('keeps registered feature flags off by default', function () {
    expect(Features::enabled(Features::WISHLIST))->toBeFalse()
        ->and(Feature::active(Features::WISHLIST))->toBeFalse();
});

it('reads Pennant feature state from runtime settings', function () {
    Features::set(Features::WISHLIST, true);

    expect(Features::enabled(Features::WISHLIST))->toBeTrue()
        ->and(Feature::active(Features::WISHLIST))->toBeTrue();

    Features::set(Features::WISHLIST, false);
    Setting::flushCache();

    expect(Feature::active(Features::WISHLIST))->toBeFalse();
});

it('rejects unknown feature flags', function () {
    expect(fn () => Features::set('unknown-module', true))
        ->toThrow(HttpException::class);
});

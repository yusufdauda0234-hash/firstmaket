<?php

use App\Models\Setting;

it('stores and retrieves a core setting', function () {
    Setting::set('platform.maintenance_mode', false);

    expect(Setting::get('platform.maintenance_mode'))->toBeFalse();
});

it('overwrites an existing setting instead of duplicating the key', function () {
    Setting::set('platform.support_email', 'old@firstmarket.ng');
    Setting::set('platform.support_email', 'support@firstmarket.ng');

    expect(Setting::query()->where('key', 'platform.support_email')->count())->toBe(1)
        ->and(Setting::get('platform.support_email'))->toBe('support@firstmarket.ng');
});

it('returns the default when a setting is missing', function () {
    expect(Setting::get('platform.unknown', 'fallback'))->toBe('fallback');
});

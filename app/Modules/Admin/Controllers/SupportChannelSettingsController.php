<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which live-chat provider the storefront loads, and its account id.
 *
 * Deliberately a provider name plus an id rather than a box for a script tag.
 * A pasted snippet is arbitrary third-party JavaScript running on a page where
 * customers type card details into Paystack's iframe — anyone who could edit
 * that setting could silently take over the storefront. Naming the providers
 * means the app builds the script URL itself, so the worst a bad value can do
 * is fail to load a widget.
 *
 * Switching from Tawk to Crisp is a settings change, not a deploy.
 */
class SupportChannelSettingsController extends Controller
{
    /** Providers the app knows how to embed safely. */
    public const PROVIDERS = ['none', 'tawk', 'crisp'];

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/SupportChannels', [
            'settings' => [
                'chatProvider' => (string) Setting::get('support.chat_provider', 'none'),
                'chatPropertyId' => (string) Setting::get('support.chat_property_id', ''),
                'chatWidgetId' => (string) Setting::get('support.chat_widget_id', ''),
                'chatEnabledForGuests' => (bool) Setting::get('support.chat_for_guests', true),
            ],
            'providers' => self::PROVIDERS,
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'chat_provider' => ['required', Rule::in(self::PROVIDERS)],
            // Ids only — no markup, no URLs, nothing that could become script.
            'chat_property_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]*$/'],
            'chat_widget_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]*$/'],
            'chat_for_guests' => ['required', 'boolean'],
        ], [
            'chat_property_id.regex' => 'Use only the id from your provider — letters, numbers, dashes and underscores.',
            'chat_widget_id.regex' => 'Use only the id from your provider — letters, numbers, dashes and underscores.',
        ]);

        Setting::set('support.chat_provider', $validated['chat_provider'], 'support');
        Setting::set('support.chat_property_id', $validated['chat_property_id'] ?? '', 'support');
        Setting::set('support.chat_widget_id', $validated['chat_widget_id'] ?? '', 'support');
        Setting::set('support.chat_for_guests', (bool) $validated['chat_for_guests'], 'support');

        $auditLogger->log(
            actor: $request->user(),
            subject: Setting::query()->where('key', 'support.chat_provider')->firstOrFail(),
            action: 'admin.support_channel_settings_updated',
            newValues: $validated,
        );

        return back()->with('success', 'Support channel settings saved.');
    }
}

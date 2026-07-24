<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Shared\Enums\NotificationCategory;

/**
 * Resolves which Laravel notification channels a user gets for a category
 * (docs/FirstMaket_Implementation_Plan.md Sprint 7). Missing preference
 * rows fall back to defaults: email + in-app on, SMS off. Security email is
 * locked on, and SMS only fires for verified phone numbers.
 */
class NotificationPreferenceService
{
    /** @return list<string> Laravel channels: mail | sms | database */
    public function channelsFor(User $user, NotificationCategory $category): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category)
            ->first();

        $email = $preference === null || $preference->email_enabled;
        $sms = $preference !== null && $preference->sms_enabled;
        $browser = $preference === null || $preference->browser_enabled;

        $channels = [];

        if ($email || $category->emailLocked()) {
            $channels[] = 'mail';
        }

        if ($sms && $user->hasVerifiedPhone()) {
            $channels[] = 'sms';
        }

        if ($browser) {
            $channels[] = 'database';
        }

        return $channels;
    }

    /**
     * All categories with their effective toggles, for the settings page.
     *
     * @return list<array<string, mixed>>
     */
    public function matrixFor(User $user): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => $preference->category->value);

        return array_map(function (NotificationCategory $category) use ($stored) {
            $preference = $stored->get($category->value);

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'emailEnabled' => $preference === null || $preference->email_enabled,
                'smsEnabled' => $preference !== null && $preference->sms_enabled,
                'browserEnabled' => $preference === null || $preference->browser_enabled,
                'emailLocked' => $category->emailLocked(),
            ];
        }, NotificationCategory::cases());
    }

    public function update(User $user, NotificationCategory $category, bool $email, bool $sms, bool $browser): void
    {
        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'category' => $category->value],
            [
                // The security email toggle is locked on server-side too.
                'email_enabled' => $category->emailLocked() ? true : $email,
                'sms_enabled' => $sms,
                'browser_enabled' => $browser,
            ],
        );
    }
}

<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer notification inbox + per-category channel preferences
 * (docs/firstmarket_Implementation_Plan.md Sprint 7).
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationPreferenceService $preferenceService): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->limit(50)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Notification',
                'body' => $notification->data['body'] ?? '',
                'url' => $notification->data['url'] ?? null,
                'read' => $notification->read_at !== null,
                'at' => $notification->created_at->diffForHumans(),
            ]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'preferences' => $preferenceService->matrixFor($request->user()),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All caught up.');
    }

    public function updatePreference(Request $request, NotificationPreferenceService $preferenceService): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(NotificationCategory::class)],
            'email_enabled' => ['required', 'boolean'],
            'sms_enabled' => ['required', 'boolean'],
            'browser_enabled' => ['required', 'boolean'],
        ]);

        $preferenceService->update(
            user: $request->user(),
            category: NotificationCategory::from($validated['category']),
            email: $validated['email_enabled'],
            sms: $validated['sms_enabled'],
            browser: $validated['browser_enabled'],
        );

        return back();
    }
}

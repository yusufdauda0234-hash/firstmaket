<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Services\AnnouncementService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\NotificationCategory;
use App\Shared\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Composing and sending announcements to the userbase.
 *
 * Guarded by permission:announcements.send on the admin subdomain — reaching
 * every customer at once is its own level of trust, separate from settings or
 * support.
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Role::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'userCount' => $role->users_count,
            ]);

        $sent = Announcement::query()
            ->with(['role:id,name', 'recipient:id,name', 'sender:id,name'])
            ->latest('id')
            ->paginate(15)
            ->through(fn (Announcement $announcement) => [
                'uuid' => $announcement->uuid,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'audience' => $announcement->audienceLabel(),
                'channels' => $announcement->channels,
                'category' => $announcement->category->label(),
                'recipients' => $announcement->recipients_count,
                'sentBy' => $announcement->sender?->name ?? 'System',
                'sentAt' => $announcement->sent_at?->format('j M Y, g:ia'),
            ]);

        return Inertia::render('Admin/Notifications/Index', [
            'roles' => $roles,
            'sent' => $sent,
            'categories' => array_map(
                fn (NotificationCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'emailLocked' => $category->emailLocked(),
                ],
                NotificationCategory::cases(),
            ),
            // Shown next to the "Everyone" option so the sender knows the
            // size of what they are about to do before they do it.
            'reachableUsers' => User::query()
                ->whereNotIn('status', [UserStatus::Suspended, UserStatus::Banned])
                ->count(),
            'search' => $request->query('q', ''),
            'matches' => $this->searchUsers($request),
        ]);
    }

    public function store(Request $request, AnnouncementService $announcements, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', Rule::in([
                Announcement::AUDIENCE_ALL,
                Announcement::AUDIENCE_ROLE,
                Announcement::AUDIENCE_USER,
            ])],
            'role_id' => ['nullable', 'required_if:audience,role', 'integer', 'exists:roles,id'],
            'user_id' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['database', 'mail', 'sms'])],
            'category' => ['required', Rule::enum(NotificationCategory::class)],
        ]);

        $announcement = new Announcement([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $validated['audience'],
            // Nulled rather than left over: a broadcast switched from "role"
            // to "everyone" in the composer must not keep the stale role id,
            // or the sent list would claim it went somewhere it did not.
            'role_id' => $validated['audience'] === Announcement::AUDIENCE_ROLE ? $validated['role_id'] : null,
            'user_id' => $validated['audience'] === Announcement::AUDIENCE_USER ? $validated['user_id'] : null,
            'channels' => array_values(array_unique($validated['channels'])),
            'category' => $validated['category'],
            'sent_by' => $request->user()->id,
        ]);

        // Checked before saving: a broadcast that reaches nobody is almost
        // always a mis-picked role, and silently recording it as "sent to 0"
        // hides the mistake until somebody asks why nothing arrived.
        if ($announcements->recipients($announcement)->count() === 0) {
            throw ValidationException::withMessages([
                'audience' => 'Nobody matches that audience — no one would receive this.',
            ]);
        }

        $announcements->send($announcement);

        $auditLogger->log(
            actor: $request->user(),
            subject: $announcement,
            action: 'notifications.announcement_sent',
            newValues: [
                'title' => $announcement->title,
                'audience' => $announcement->audience,
                'channels' => $announcement->channels,
                'recipients' => $announcement->recipients_count,
            ],
        );

        return back()->with('success', "Sending to {$announcement->recipients_count} ".
            ($announcement->recipients_count === 1 ? 'person.' : 'people.'));
    }

    /**
     * Typeahead for the "one person" audience.
     *
     * Capped at 10 and requires two characters — this is a picker, not a way
     * to page through the whole customer list from the composer.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    private function searchUsers(Request $request): array
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return [];
        }

        return User::query()
            ->whereNotIn('status', [UserStatus::Suspended, UserStatus::Banned])
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();
    }
}

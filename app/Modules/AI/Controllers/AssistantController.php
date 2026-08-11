<?php

namespace App\Modules\AI\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AssistantConfirmation;
use App\Modules\AI\Models\AssistantConversation;
use App\Modules\AI\Models\AssistantMessage;
use App\Modules\AI\Models\AssistantRecommendation;
use App\Modules\AI\Services\AssistantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer-facing assistant.
 *
 * Every action here is scoped to the signed-in customer by construction:
 * conversations are looked up with a `user_id` filter rather than by id
 * alone, so there is no request that can reach somebody else's history.
 */
class AssistantController extends Controller
{
    public function index(Request $request, AssistantService $assistant): Response
    {
        $user = $request->user();

        $conversation = $request->query('conversation')
            ? AssistantConversation::query()
                ->where('user_id', $user->id)
                ->where('uuid', $request->query('conversation'))
                ->firstOrFail()
            : AssistantConversation::query()
                ->where('user_id', $user->id)
                ->orderByDesc('last_message_at')
                ->first();

        return Inertia::render('Account/Assistant', [
            'conversations' => $assistant->conversationsFor($user),
            'current' => $conversation ? [
                'uuid' => $conversation->uuid,
                'title' => $conversation->title,
                'messages' => $conversation->messages()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AssistantMessage $message) => [
                        'id' => $message->id,
                        'role' => $message->role,
                        'body' => $message->body,
                        'evidence' => $message->evidence,
                        'at' => $message->created_at?->diffForHumans(),
                    ])->values(),
            ] : null,
            // Only suggestions still awaiting an answer, and still fresh.
            'recommendations' => AssistantRecommendation::query()
                ->where('user_id', $user->id)
                ->where('status', AssistantRecommendation::STATUS_OFFERED)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AssistantRecommendation $recommendation) => [
                    'uuid' => $recommendation->uuid,
                    'action' => $recommendation->action,
                    'title' => $recommendation->title,
                    'body' => $recommendation->body,
                    'payload' => $recommendation->payload,
                    'evidence' => $recommendation->evidence,
                    'planUuid' => $recommendation->goal?->uuid,
                ])->values(),
            'remainingQuestions' => $assistant->remainingQuestionsToday($user),
        ]);
    }

    public function ask(Request $request, AssistantService $assistant): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'conversation' => ['nullable', 'string'],
        ]);

        $conversation = isset($validated['conversation'])
            ? AssistantConversation::query()
                ->where('user_id', $request->user()->id)
                ->where('uuid', $validated['conversation'])
                ->first()
            : null;

        $answer = $assistant->ask($request->user(), $validated['message'], $conversation);

        return redirect()->route('assistant.index', [
            'conversation' => $answer->conversation->uuid,
        ]);
    }

    public function confirm(Request $request, AssistantRecommendation $recommendation, AssistantService $assistant): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                AssistantConfirmation::DECISION_ACCEPTED,
                AssistantConfirmation::DECISION_DECLINED,
            ])],
        ]);

        $assistant->confirm(
            $request->user(),
            $recommendation,
            $validated['decision'],
            $request->ip(),
            (string) $request->userAgent(),
        );

        // Accepting a suggestion that needs the customer to choose details
        // sends them to the plan page rather than acting on their behalf.
        if ($validated['decision'] === AssistantConfirmation::DECISION_ACCEPTED
            && in_array($recommendation->action, [
                AssistantRecommendation::ACTION_RESCHEDULE,
                AssistantRecommendation::ACTION_SWITCH_TO_CHEAPER,
            ], true)
            && $recommendation->goal !== null
        ) {
            return redirect()
                ->route('savings.goals.show', $recommendation->goal->uuid)
                ->with('success', 'Here is the plan — you choose the details from here.');
        }

        if ($validated['decision'] === AssistantConfirmation::DECISION_ACCEPTED
            && $recommendation->action === AssistantRecommendation::ACTION_PAUSE
        ) {
            $assistant->act($request->user(), $recommendation->fresh());

            return back()->with('success', 'Plan paused. Your price stays frozen and nothing you have paid is affected.');
        }

        return back()->with('success', $validated['decision'] === AssistantConfirmation::DECISION_ACCEPTED
            ? 'Noted.'
            : 'Suggestion dismissed. I will not raise it again unless things change.');
    }

    public function destroy(Request $request, AssistantConversation $conversation): RedirectResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $conversation->delete();

        return redirect()->route('assistant.index')->with('success', 'Conversation deleted.');
    }
}

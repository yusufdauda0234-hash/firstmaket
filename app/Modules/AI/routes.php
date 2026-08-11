<?php

use App\Modules\AI\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

/*
 * The savings assistant (Phase 3C).
 *
 * Asking is throttled on top of the per-customer daily limit the service
 * enforces: the limit protects the bill, the throttle protects the server
 * from a script hammering the endpoint before the limit is reached.
 *
 * There is no route here that moves money. Confirming a suggestion either
 * pauses a plan — something the customer can already do themselves — or
 * hands them to the plan page to choose the details. Payments still only
 * happen through a verified Paystack charge.
 */
Route::middleware('auth')->group(function () {
    Route::get('account/assistant', [AssistantController::class, 'index'])->name('assistant.index');

    Route::post('account/assistant/ask', [AssistantController::class, 'ask'])
        ->middleware('throttle:20,1')
        ->name('assistant.ask');

    Route::post('account/assistant/recommendations/{recommendation:uuid}/confirm', [AssistantController::class, 'confirm'])
        ->middleware('throttle:30,1')
        ->name('assistant.recommendations.confirm');

    Route::delete('account/assistant/conversations/{conversation:uuid}', [AssistantController::class, 'destroy'])
        ->name('assistant.conversations.destroy');
});

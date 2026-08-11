<?php

namespace App\Modules\Returns\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Returns\Models\ReturnEvidence;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnPolicy;
use App\Modules\Returns\Services\ReturnService;
use App\Shared\Enums\ReturnReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of a return: open one, watch it, call it off.
 *
 * Nothing here can approve a return or move money — those live behind staff
 * permissions on the admin subdomain.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly ReturnPolicy $policy,
    ) {}

    public function index(Request $request): Response
    {
        $requests = ReturnRequest::query()
            ->where('customer_id', $request->user()->id)
            ->with(['order.product:id,name,slug', 'order:id,uuid,product_id,locked_price_kobo'])
            ->latest('id')
            ->get()
            ->map(fn (ReturnRequest $return) => $this->summary($return));

        return Inertia::render('Account/Returns/Index', ['returns' => $requests]);
    }

    public function show(Request $request, ReturnRequest $return): Response
    {
        abort_unless($return->customer_id === $request->user()->id, 403);

        $return->load(['order.product:id,name,slug', 'events.actor:id,name', 'refund']);

        return Inertia::render('Account/Returns/Show', [
            'return' => [
                ...$this->summary($return),
                'reasonNote' => $return->reason_note,
                'reviewNote' => $return->review_note,
                'returnDeliveryPaidBy' => $return->return_delivery_paid_by,
                'requiredUnopened' => $return->required_unopened,
                'refundDaysMin' => $this->policy->refundDaysMin(),
                'refundDaysMax' => $this->policy->refundDaysMax(),
                'canCancel' => $return->status->isCancellable(),
                'canMarkShipped' => $return->status === \App\Shared\Enums\ReturnStatus::Approved,
                'timeline' => $return->events->map(fn ($event) => [
                    'status' => $event->to_status->value,
                    'label' => $event->to_status->label(),
                    'note' => $event->note,
                    'at' => $event->created_at->format('j M Y, g:ia'),
                ]),
            ],
        ]);
    }

    public function store(Request $request, Order $order, ReturnService $returns): RedirectResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $validated = $request->validate([
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:5'],
            // Evidence is what decides who pays, so it is worth accepting —
            // but only images, and only small ones.
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $return = $returns->open(
            $request->user(),
            $order,
            ReturnReason::from($validated['reason']),
            $validated['note'] ?? null,
        );

        foreach ($request->file('photos') ?? [] as $photo) {
            // Private disk: a photo of somebody's living room is personal
            // data, not a public asset.
            ReturnEvidence::query()->create([
                'return_request_id' => $return->id,
                'disk' => 'private',
                'path' => $photo->store('returns/'.$return->uuid, 'private'),
            ]);
        }

        return redirect()
            ->route('returns.show', $return->uuid)
            ->with('success', 'Return request sent. We will review it and email you.');
    }

    public function cancel(Request $request, ReturnRequest $return): RedirectResponse
    {
        abort_unless($return->customer_id === $request->user()->id, 403);

        $this->returns->cancel($request->user(), $return);

        return back()->with('success', 'Return cancelled.');
    }

    /** The customer has handed the parcel to the courier. */
    public function markShipped(Request $request, ReturnRequest $return): RedirectResponse
    {
        abort_unless($return->customer_id === $request->user()->id, 403);

        $this->returns->markInTransit($request->user(), $return);

        return back()->with('success', 'Thanks — we will let you know when it arrives.');
    }

    /** @return array<string, mixed> */
    private function summary(ReturnRequest $return): array
    {
        return [
            'uuid' => $return->uuid,
            'status' => $return->status->value,
            'statusLabel' => $return->status->label(),
            'reason' => $return->reason->value,
            'reasonLabel' => $return->reason->label(),
            'refundableKobo' => $return->refundable_kobo,
            'openedAt' => $return->created_at->format('j M Y'),
            'productName' => $return->order?->product?->name,
            'productSlug' => $return->order?->product?->slug,
            'orderUuid' => $return->order?->uuid,
        ];
    }
}

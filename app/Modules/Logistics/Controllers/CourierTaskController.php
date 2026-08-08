<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\DeliveryAssignment;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\CourierCashService;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Payments\Actions\StartPaystackPaymentAction;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\DeliveryOutcome;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The courier's own screen: what they are carrying and what to do next.
 *
 * Everything is scoped to their live assignments, so a forged uuid finds
 * nothing rather than being refused — the parcel simply is not in the query.
 *
 * Written for a phone held in one hand at a gate. That is why the payload
 * carries the recipient's phone and a maps link per stop: the two things a
 * courier reaches for, and both are one tap rather than a page away.
 */
class CourierTaskController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Logistics/Tasks', [
            'cash' => $this->cashFor($request),
            'stops' => $this->stopsFor($request),
            'failureReasons' => array_map(fn (DeliveryOutcome $outcome) => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
            ], DeliveryOutcome::failures()),
        ]);
    }

    /** The courier's home screen: today at a glance, then the stops. */
    public function dashboard(Request $request): Response
    {
        $courier = $request->user();

        $delivered = Shipment::query()
            ->where('delivered_by', $courier->id)
            ->whereDate('delivered_at', today())
            ->count();

        $failedToday = DeliveryAssignment::query()
            ->where('logistics_user_id', $courier->id)
            ->where('status', DeliveryAssignmentStatus::Failed)
            ->whereDate('updated_at', today())
            ->count();

        $stops = $this->stopsFor($request);

        return Inertia::render('Logistics/Dashboard', [
            'cash' => $this->cashFor($request),
            'stops' => $stops,
            'failureReasons' => array_map(fn (DeliveryOutcome $outcome) => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
            ], DeliveryOutcome::failures()),
            'stats' => [
                'carrying' => $stops->count(),
                'deliveredToday' => $delivered,
                'failedToday' => $failedToday,
                // The oldest thing still on the van, which is the one most
                // likely to become a complaint.
                'oldestWaitingDays' => $stops->max('waitingDays') ?? 0,
            ],
            'courier' => [
                'name' => $courier->name,
                'vehicle' => $courier->courierProfile?->vehicle_type->label(),
            ],
        ]);
    }

    /** Move one parcel to its next step. Delivered is not reachable here. */
    public function advance(Request $request, Shipment $shipment, DeliveryService $delivery): RedirectResponse
    {
        $this->assertCarrying($request, $shipment);

        $next = $shipment->status->next();

        if ($next === null || $next === ShipmentStatus::Delivered) {
            return back()->with('error', 'Use "Hand over" and the customer’s code to finish this delivery.');
        }

        $delivery->advance($request->user(), $shipment, $next);

        return back()->with('success', $next->label().'. The customer has been told.');
    }

    /**
     * Advance several stops at once.
     *
     * Each parcel moves to *its own* next step rather than to one shared
     * status: a courier's list is normally mixed — some packed, some already
     * out for delivery — and sending them all to one status would drag some
     * backwards and skip a step for others.
     */
    public function bulkAdvance(Request $request, DeliveryService $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
        ], [
            'uuids.required' => 'Select at least one stop first.',
        ]);

        $shipments = $this->carrying($request)
            ->whereIn('uuid', $validated['uuids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($shipments as $shipment) {
            $next = $shipment->status->next();

            // Delivered needs the code, one parcel at a time. A bulk button
            // that could close deliveries would make the code pointless.
            if ($next === null || $next === ShipmentStatus::Delivered) {
                $skipped++;

                continue;
            }

            try {
                $delivery->advance($request->user(), $shipment, $next);
                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $message = $done.' stop'.($done === 1 ? '' : 's').' moved on. Customers notified.';

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — those need the customer’s code.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    /** Hand the parcel over against the code the customer reads out. */
    public function deliver(Request $request, Shipment $shipment, DeliveryService $delivery): RedirectResponse
    {
        $this->assertCarrying($request, $shipment);

        /*
         * collection_method is optional, and cash when unsaid.
         *
         * Making it required turned an existing, working endpoint into one
         * that rejects every caller that had not been updated — a courier on
         * a stale page could no longer hand anything over. Cash is the right
         * default anyway: it is what the column defaults to, what the service
         * assumes, and what a parcel with nothing owing wants.
         */
        $validated = $request->validate([
            'delivery_code' => ['required', 'string', 'size:4'],
            'collection_method' => ['nullable', Rule::in(['cash', 'customer_online', 'courier_online'])],
        ], [
            'delivery_code.size' => 'The code is four digits.',
        ]);

        $delivery->deliver(
            $request->user(),
            $shipment,
            $validated['delivery_code'],
            $validated['collection_method'] ?? 'cash',
        );

        return back()->with('success', 'Delivered. Thank you.');
    }

    /** Start a courier-funded Paystack payment before handover. */
    public function payGoods(
        Request $request,
        Shipment $shipment,
        StartPaystackPaymentAction $payment,
    ): SymfonyResponse {
        $this->assertCarrying($request, $shipment);
        abort_unless($shipment->collect_on_delivery_kobo > 0 && $shipment->goods_paid_at === null, 422);

        $shipment->forceFill(['goods_collection_method' => 'courier_online'])->save();

        return $payment->forShipmentGoods($request->user(), $shipment);
    }

    /**
     * A courier declares they are handing cash in.
     *
     * It does not reduce their balance yet — the office has to confirm the
     * money arrived. Recording the declaration anyway is the point: the gap
     * between "I paid it in" and "we received it" is exactly where cash goes
     * missing, and it cannot be examined if only one side is written down.
     */
    public function remit(Request $request, CourierCashService $cash): RedirectResponse
    {
        $validated = $request->validate([
            'amount_naira' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'amount_naira.required' => 'Say how much you are handing in.',
        ]);

        $cash->declareRemittance(
            $request->user(),
            (int) round($validated['amount_naira'] * 100),
            $validated['note'] ?? null,
        );

        return back()->with(
            'success',
            'Recorded. It clears from your balance once the office confirms they have it.',
        );
    }

    /** Record why the parcel could not be handed over. */
    public function fail(Request $request, Shipment $shipment, DeliveryService $delivery): RedirectResponse
    {
        $this->assertCarrying($request, $shipment);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(array_map(
                fn (DeliveryOutcome $outcome) => $outcome->value,
                DeliveryOutcome::failures(),
            ))],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'outcome.required' => 'Say what happened.',
        ]);

        $shipment = $delivery->recordFailure(
            $request->user(),
            $shipment,
            DeliveryOutcome::from($validated['outcome']),
            $validated['note'] ?? null,
        );

        return back()->with(
            'success',
            $shipment->isExhausted()
                ? 'Recorded. This one has failed '.Shipment::MAX_ATTEMPTS.' times, so it has gone to the office to sort out.'
                : 'Recorded. It goes back in the queue for another run.',
        );
    }

    /**
     * What this courier owes the office.
     *
     * Shown on both their screens whether or not it is zero: a balance that
     * only appears once it is large is one nobody watches build up.
     *
     * @return array<string, int|bool>
     */
    private function cashFor(Request $request): array
    {
        $cash = app(CourierCashService::class);
        $courier = $request->user();

        return [
            'holdingKobo' => $cash->balanceKobo($courier),
            'pendingKobo' => $cash->pendingRemittanceKobo($courier),
            'ceilingKobo' => (int) ($courier->courierProfile?->max_float_kobo ?? 0),
        ];
    }

    /**
     * Parcels this courier is holding.
     *
     * @return Builder<Shipment>
     */
    private function carrying(Request $request)
    {
        return Shipment::query()
            ->whereHas('assignments', fn ($assignment) => $assignment
                ->where('logistics_user_id', $request->user()->id)
                ->where('status', DeliveryAssignmentStatus::Assigned));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function stopsFor(Request $request)
    {
        return $this->carrying($request)
            ->with([
                'vendor:id,business_name,address',
                'orders:id,shipment_id,product_id',
                'orders.product:id,name',
                'attempts' => fn ($query) => $query->latest('created_at')->limit(3),
            ])
            ->orderBy('created_at')
            ->get()
            ->map(function (Shipment $shipment) {
                $next = $shipment->status->next();

                return [
                    'uuid' => $shipment->uuid,
                    'contents' => $shipment->contentsLabel(),
                    'unitCount' => $shipment->orders->count(),
                    'pickupFrom' => $shipment->vendor->business_name,
                    // Encrypted at rest, so it is read through the model
                    // rather than selected in the query.
                    'pickupAddress' => $shipment->vendor->address,
                    'deliverTo' => $shipment->destinationLabel(),
                    'address' => $shipment->delivery_address,
                    'landmark' => $shipment->landmark,
                    'recipientName' => $shipment->recipient_name,
                    'recipientPhone' => $shipment->recipient_phone,
                    'status' => $shipment->status->value,
                    'statusLabel' => $shipment->status->label(),
                    'nextStep' => $next === ShipmentStatus::Delivered ? null : $next?->value,
                    'nextStepLabel' => $next === ShipmentStatus::Delivered ? null : $next?->label(),
                    // True once the only thing left is to hand it over.
                    'awaitingHandover' => in_array(
                        $shipment->status,
                        [ShipmentStatus::OutForDelivery, ShipmentStatus::Failed],
                        true,
                    ),
                    // What to ask for at the door. Not editable by them:
                    // what is owed was agreed at checkout.
                    'collectKobo' => $shipment->collect_on_delivery_kobo,
                    'goodsPaidAt' => $shipment->goods_paid_at?->format('j M, g:ia'),
                    'goodsCollectionMethod' => $shipment->goods_collection_method,
                    'attemptCount' => $shipment->attempt_count,
                    'lastAttempt' => $shipment->attempts->first()?->outcome->label(),
                    'waitingDays' => (int) $shipment->created_at->diffInDays(now()),
                ];
            });
    }

    /**
     * Refuse a parcel this courier is not carrying.
     *
     * Route-model binding resolves any shipment by uuid, so without this a
     * courier could act on somebody else's stop by editing a form.
     */
    private function assertCarrying(Request $request, Shipment $shipment): void
    {
        abort_unless(
            $this->carrying($request)->whereKey($shipment->id)->exists(),
            403,
            'You are not carrying this parcel.',
        );
    }
}

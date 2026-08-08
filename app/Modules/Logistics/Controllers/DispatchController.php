<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Logistics\Models\CourierProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dispatch desk: every parcel waiting for a courier, and who is free.
 *
 * Assignment used to live only on the order detail page, one at a time, which
 * meant forty ready orders were forty page loads. This is the queue view:
 * filter by state, tick the ones going the same way, send them to one
 * courier.
 *
 * Exceptions sit at the top rather than in a separate screen. A parcel that
 * has failed three times needs a decision, and a decision nobody is shown is
 * a decision nobody makes.
 */
class DispatchController extends Controller
{
    public function index(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $vendorId = (int) $request->query('vendor', 0);

        $waiting = Shipment::query()
            ->awaitingCourier()
            ->when($state !== '', fn ($query) => $query->where('state', $state))
            ->when($vendorId > 0, fn ($query) => $query->where('vendor_id', $vendorId))
            ->with(['vendor:id,business_name', 'orders:id,shipment_id,product_id', 'orders.product:id,name'])
            // Oldest first: the queue is worked from the top, and the parcel
            // that has waited longest is the one closest to a complaint.
            ->orderBy('created_at')
            ->get()
            ->map(fn (Shipment $shipment) => $this->presentWaiting($shipment));

        return Inertia::render('Admin/Logistics/Dispatch', [
            'waiting' => $waiting->reject(fn (array $row) => $row['isException'])->values(),
            // Out of retries. Surfaced here, not buried, because each one is
            // waiting on a human.
            'exceptions' => $waiting->filter(fn (array $row) => $row['isException'])->values(),
            'inFlight' => $this->inFlight(),
            'couriers' => $this->couriers(),
            'states' => Nigeria::STATES,
            'filters' => ['state' => $state, 'vendor' => $vendorId ?: null],
            'vendorOptions' => Shipment::query()
                ->awaitingCourier()
                ->with('vendor:id,business_name')
                ->get()
                ->pluck('vendor')
                ->filter()
                ->unique('id')
                ->sortBy('business_name')
                ->values()
                ->map(fn ($vendor) => ['id' => $vendor->id, 'name' => $vendor->business_name]),
        ]);
    }

    /** Hand a batch of parcels to one courier. */
    public function assign(Request $request, DeliveryService $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
            'courier_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ], [
            'uuids.required' => 'Tick at least one parcel.',
            'courier_id.required' => 'Choose a courier.',
        ]);

        $courier = User::query()->findOrFail($validated['courier_id']);

        $shipments = Shipment::query()
            ->whereIn('uuid', $validated['uuids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($shipments as $shipment) {
            try {
                $delivery->assign($request->user(), $shipment, $courier);
                $done++;
            } catch (\Throwable) {
                // Somebody else took it while this page was open. Skipping is
                // right — failing the batch would punish the other 39.
                $skipped++;
            }
        }

        $message = $done.' parcel'.($done === 1 ? '' : 's')." assigned to {$courier->name}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — already taken or not ready.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    /**
     * Close a delivery without the customer's code.
     *
     * The escape hatch that makes the code workable: a customer who lost it
     * still needs their parcel, and the courier at the door cannot be the one
     * told no. Stamped on the shipment and audit-logged, so "delivered
     * without proof" is always answerable.
     */
    public function forceDeliver(Request $request, Shipment $shipment, DeliveryService $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            // Optional and cash when unsaid, so an existing override keeps
            // working — but sayable, because closing a pay-on-delivery parcel
            // decides who is holding the money for it.
            'collection_method' => ['nullable', Rule::in(['cash', 'customer_online'])],
        ], [
            'reason.required' => 'Say why the code was not used.',
            'reason.min' => 'Give a real reason — this is on the record.',
        ]);

        $delivery->deliverWithoutCode(
            $request->user(),
            $shipment,
            $validated['reason'],
            $validated['collection_method'] ?? 'cash',
        );

        return back()->with('success', 'Closed as delivered. The override is on the record.');
    }

    /** Take a parcel off whoever is carrying it, back into the queue. */
    public function recall(Request $request, Shipment $shipment): RedirectResponse
    {
        $shipment->assignments()
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->update(['status' => DeliveryAssignmentStatus::Cancelled]);

        return back()->with('success', 'Back in the dispatch queue.');
    }

    /** @return array<string, mixed> */
    private function presentWaiting(Shipment $shipment): array
    {
        return [
            'uuid' => $shipment->uuid,
            'contents' => $shipment->contentsLabel(),
            'unitCount' => $shipment->orders->count(),
            'vendorName' => $shipment->vendor->business_name,
            'destination' => $shipment->destinationLabel(),
            'state' => $shipment->state,
            'status' => $shipment->status->value,
            'statusLabel' => $shipment->status->label(),
            'attemptCount' => $shipment->attempt_count,
            'isException' => $shipment->isExhausted(),
            'waitingDays' => (int) $shipment->created_at->diffInDays(now()),
        ];
    }

    /** What is already out, so the desk can see the whole board. */
    private function inFlight()
    {
        return Shipment::query()
            ->open()
            ->whereHas('assignments', fn ($query) => $query->where('status', DeliveryAssignmentStatus::Assigned))
            ->with([
                'vendor:id,business_name',
                'assignments' => fn ($query) => $query
                    ->where('status', DeliveryAssignmentStatus::Assigned)
                    ->with('logisticsUser:id,name'),
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Shipment $shipment) => [
                'uuid' => $shipment->uuid,
                'vendorName' => $shipment->vendor->business_name,
                'destination' => $shipment->destinationLabel(),
                'statusLabel' => $shipment->status->label(),
                'courierName' => $shipment->assignments->first()?->logisticsUser?->name,
                'canForceDeliver' => $shipment->status === ShipmentStatus::OutForDelivery
                    || $shipment->status === ShipmentStatus::Failed,
                'waitingDays' => (int) $shipment->created_at->diffInDays(now()),
            ]);
    }

    /** Who is free, and how loaded they already are. */
    private function couriers()
    {
        return CourierProfile::query()
            ->available()
            ->with('user:id,name,status')
            ->get()
            ->filter(fn (CourierProfile $profile) => $profile->user !== null)
            ->sortBy(fn (CourierProfile $profile) => $profile->user->name)
            ->values()
            ->map(fn (CourierProfile $profile) => [
                'id' => $profile->user_id,
                'name' => $profile->user->name,
                'vehicle' => $profile->vehicle_type->label(),
                'capacityHint' => $profile->vehicle_type->capacityHint(),
                'baseState' => $profile->base_state,
                'openCount' => $profile->openShipmentCount(),
                'maxOpen' => $profile->max_open_shipments ?: null,
                // Advisory. A dispatcher covering for someone off sick has to
                // be able to go past a ceiling meant for a normal day.
                'isOverloaded' => $profile->isOverloaded(),
            ]);
    }
}

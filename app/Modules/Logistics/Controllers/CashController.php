<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Logistics\Models\CourierCashMovement;
use App\Modules\Logistics\Services\CourierCashService;
use App\Modules\Orders\Services\PayOnDeliveryPolicy;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash on delivery: what is owed, who is holding it, and the settings that
 * decide whether it is offered at all.
 *
 * One screen rather than two because they are one job — nobody decides the
 * ceiling without looking at what is currently out, and nobody chases a
 * courier's balance without wanting to know why it got that high.
 */
class CashController extends Controller
{
    public function index(Request $request, CourierCashService $cash): Response
    {
        $pending = CourierCashMovement::query()
            ->where('type', CourierCashMovement::REMITTANCE)
            ->whereNull('confirmed_at')
            ->with('courier:id,name,phone')
            ->latest('id')
            ->get()
            ->map(fn (CourierCashMovement $movement) => [
                'uuid' => $movement->uuid,
                'courierName' => $movement->courier?->name ?? '—',
                'amountKobo' => $movement->amount_kobo,
                'note' => $movement->note,
                'declaredAt' => $movement->created_at->diffForHumans(),
                // Its own courier may not confirm it, and the screen should
                // not offer a button that will be refused.
                'isOwn' => $movement->courier_user_id === $request->user()->id,
            ]);

        $recent = CourierCashMovement::query()
            ->with(['courier:id,name', 'confirmedBy:id,name'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (CourierCashMovement $movement) => [
                'uuid' => $movement->uuid,
                'type' => $movement->type,
                'courierName' => $movement->courier?->name ?? '—',
                'amountKobo' => $movement->amount_kobo,
                'confirmedBy' => $movement->confirmedBy?->name,
                'at' => $movement->created_at->format('j M, g:ia'),
            ]);

        $goodsPayments = PaystackTransaction::query()
            ->where('purpose', 'shipment_goods')
            ->with(['user:id,name,email', 'shipment:id,goods_collection_method'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (PaystackTransaction $transaction) => [
                'reference' => $transaction->paystack_reference,
                'amountKobo' => $transaction->amount_kobo,
                'payerName' => $transaction->user?->name ?? $transaction->user?->email ?? '—',
                'method' => $transaction->shipment?->goods_collection_method ?? 'online',
                'status' => $transaction->status->value,
                'at' => $transaction->webhook_verified_at?->format('j M, g:ia')
                    ?? $transaction->created_at->format('j M, g:ia'),
            ]);

        return Inertia::render('Admin/Logistics/Cash', [
            'outstanding' => $cash->outstanding(),
            'pending' => $pending,
            'recent' => $recent,
            'goodsPayments' => $goodsPayments,
            'settings' => [
                'enabled' => PayOnDeliveryPolicy::isEnabled(),
                'maxOrderNaira' => PayOnDeliveryPolicy::maxOrderKobo() / 100,
                'states' => PayOnDeliveryPolicy::states(),
                'maxRefusals' => PayOnDeliveryPolicy::maxRefusals(),
            ],
            'allStates' => Nigeria::STATES,
        ]);
    }

    /** Turn pay on delivery on or off, and set what it is bounded by. */
    public function updateSettings(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // Zero means no ceiling, which is a real choice and a risky one —
            // the screen says so rather than the validator refusing it.
            'max_order_naira' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'states' => ['array'],
            'states.*' => ['string', Rule::in(Nigeria::STATES)],
            'max_refusals' => ['required', 'integer', 'min:1', 'max:20'],
        ], [
            'max_refusals.min' => 'One refusal has to be allowed, or nobody could ever use it.',
        ]);

        Setting::set('orders.pay_on_delivery_enabled', $validated['enabled'], 'orders');
        Setting::set('orders.pay_on_delivery_max_kobo', (int) round($validated['max_order_naira'] * 100), 'orders');
        Setting::set('orders.pay_on_delivery_states', array_values($validated['states'] ?? []), 'orders');
        Setting::set('orders.pay_on_delivery_max_refusals', $validated['max_refusals'], 'orders');

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.pay_on_delivery_settings_updated',
            newValues: [
                'enabled' => $validated['enabled'],
                'max_order_kobo' => (int) round($validated['max_order_naira'] * 100),
                'states' => $validated['states'] ?? [],
            ],
        );

        return back()->with(
            'success',
            $validated['enabled']
                ? 'Pay on delivery is on. Existing orders are unaffected.'
                : 'Pay on delivery is off. Orders already placed under it still need collecting.',
        );
    }

    /** The office confirms a courier's hand-in actually arrived. */
    public function confirmRemittance(
        Request $request,
        CourierCashMovement $courierCashMovement,
        CourierCashService $cash,
    ): RedirectResponse {
        $cash->confirmRemittance($request->user(), $courierCashMovement);

        return back()->with('success', 'Confirmed. The courier’s balance is down by that much.');
    }
}

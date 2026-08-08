<?php

namespace App\Modules\Logistics\Services;

use App\Models\User;
use App\Modules\Logistics\Models\CourierCashMovement;
use App\Modules\Logistics\Models\CourierProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cash a courier is carrying.
 *
 * Pay-on-delivery means real notes change hands away from any till, so the
 * only defence is that every one of them has a row: collected at a door,
 * handed in at the office, confirmed by somebody who is not the person who
 * handed it in.
 *
 * A courier's balance is derived from those rows every time it is asked for.
 * Nothing caches it, because a cached total is a number that can be wrong
 * while everything around it looks fine — and a discrepancy here is the one
 * thing that must never be quiet.
 */
class CourierCashService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /**
     * What this courier is holding: collected, less what the office has
     * confirmed receiving.
     *
     * An unconfirmed remittance does not count. A courier who says they paid
     * in yesterday and a ledger that agrees are two different things, and
     * until somebody in the office confirms it, the money is still theirs to
     * account for.
     */
    public function balanceKobo(User $courier): int
    {
        $collected = (int) CourierCashMovement::query()
            ->where('courier_user_id', $courier->id)
            ->collections()
            ->sum('amount_kobo');

        $remitted = (int) CourierCashMovement::query()
            ->where('courier_user_id', $courier->id)
            ->confirmedRemittances()
            ->sum('amount_kobo');

        return $collected - $remitted;
    }

    /** Money handed in but not yet confirmed by the office. */
    public function pendingRemittanceKobo(User $courier): int
    {
        return (int) CourierCashMovement::query()
            ->where('courier_user_id', $courier->id)
            ->where('type', CourierCashMovement::REMITTANCE)
            ->whereNull('confirmed_at')
            ->sum('amount_kobo');
    }

    /**
     * Record cash taken at a doorstep.
     *
     * Called from inside DeliveryService::deliver, in the same transaction, so
     * a delivered pay-on-delivery parcel can never exist without a cash row
     * against it. The unique index on (shipment, type) is the backstop.
     *
     * The amount comes from the shipment, never from the courier: what is owed
     * was decided at checkout and is not theirs to adjust at the door.
     */
    public function recordCollection(User $courier, Shipment $shipment): ?CourierCashMovement
    {
        if ($shipment->collect_on_delivery_kobo <= 0) {
            return null;
        }

        $existing = CourierCashMovement::query()
            ->where('shipment_id', $shipment->id)
            ->collections()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CourierCashMovement::query()->create([
            'courier_user_id' => $courier->id,
            'type' => CourierCashMovement::COLLECTION,
            'amount_kobo' => $shipment->collect_on_delivery_kobo,
            'shipment_id' => $shipment->id,
            // A collection is money that demonstrably moved — the customer
            // handed it over and the parcel was released. Nothing to confirm.
            'confirmed_at' => now(),
            'confirmed_by' => $courier->id,
        ]);
    }

    /**
     * A courier declares they are handing money in.
     *
     * Unconfirmed until the office says otherwise, so it does not reduce their
     * balance yet. Declaring it is still worth recording: it is the courier's
     * side of the story, timestamped, and the gap between declaring and
     * confirming is exactly where money goes missing.
     */
    public function declareRemittance(User $courier, int $amountKobo, ?string $note = null): CourierCashMovement
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter how much you are handing in.']);
        }

        $outstanding = $this->balanceKobo($courier) - $this->pendingRemittanceKobo($courier);

        if ($amountKobo > $outstanding) {
            throw ValidationException::withMessages([
                'amount' => 'That is more than you are carrying. You have ₦'
                    .number_format(max(0, $outstanding) / 100).' left to hand in.',
            ]);
        }

        $movement = CourierCashMovement::query()->create([
            'courier_user_id' => $courier->id,
            'type' => CourierCashMovement::REMITTANCE,
            'amount_kobo' => $amountKobo,
            'note' => $note,
        ]);

        $this->auditLogger->log(
            actor: $courier,
            subject: $movement,
            action: 'logistics.cash_remittance_declared',
            newValues: ['amount_kobo' => $amountKobo],
        );

        return $movement;
    }

    /**
     * The office confirms the money arrived.
     *
     * Never by the courier who handed it in — a person who can both declare
     * and confirm their own remittance can clear their balance without
     * producing any cash, which is the whole risk this ledger exists to
     * manage.
     */
    public function confirmRemittance(User $staff, CourierCashMovement $movement): CourierCashMovement
    {
        if ($movement->type !== CourierCashMovement::REMITTANCE) {
            throw ValidationException::withMessages(['movement' => 'Only a hand-in can be confirmed.']);
        }

        if ($movement->isConfirmed()) {
            return $movement;
        }

        if ($movement->courier_user_id === $staff->id) {
            throw ValidationException::withMessages([
                'movement' => 'Somebody else has to confirm money you handed in.',
            ]);
        }

        return DB::transaction(function () use ($staff, $movement) {
            $movement->forceFill([
                'confirmed_by' => $staff->id,
                'confirmed_at' => now(),
            ])->save();

            $this->auditLogger->log(
                actor: $staff,
                subject: $movement,
                action: 'logistics.cash_remittance_confirmed',
                newValues: [
                    'amount_kobo' => $movement->amount_kobo,
                    'courier_id' => $movement->courier_user_id,
                ],
            );

            return $movement;
        });
    }

    /**
     * Whether this courier may be given another parcel to collect cash on.
     *
     * A ceiling on how much any one person is walking around with. Unlike the
     * open-parcel limit, which is advisory, this one is enforced — the risk is
     * money rather than a long day.
     */
    public function canCarryMore(User $courier, int $amountKobo): bool
    {
        $profile = CourierProfile::query()->where('user_id', $courier->id)->first();
        $ceiling = (int) ($profile?->max_float_kobo ?? 0);

        if ($ceiling <= 0) {
            return true;
        }

        return $this->balanceKobo($courier) + $amountKobo <= $ceiling;
    }

    /**
     * Everyone currently holding money, for the reconciliation screen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstanding()
    {
        return User::query()
            ->role('Logistics Personnel')
            ->get()
            ->map(fn (User $courier) => [
                'id' => $courier->id,
                'uuid' => $courier->uuid,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'balanceKobo' => $this->balanceKobo($courier),
                'pendingKobo' => $this->pendingRemittanceKobo($courier),
                'ceilingKobo' => (int) ($courier->courierProfile?->max_float_kobo ?? 0),
            ])
            ->filter(fn (array $row) => $row['balanceKobo'] > 0 || $row['pendingKobo'] > 0)
            ->sortByDesc('balanceKobo')
            ->values();
    }
}

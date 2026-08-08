<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\DeliveryRate;

/**
 * What delivery costs, decided entirely by the rates admins maintain.
 *
 * Nothing here reads config. An earlier version fell back to a flat fee and a
 * free-delivery threshold in config/firstmaket.php whenever a rate was
 * missing or left blank, which meant a state priced at ₦2,000 could charge
 * nothing on most orders and no figure on the admin screen explained why.
 * Every number a customer is quoted now comes from a row somebody can see
 * and edit.
 *
 * Resolution is: the state's own active rate, then the active default row.
 * The default row is guaranteed to exist — it cannot be deleted or switched
 * off — so the "nothing configured" branch is unreachable in practice; it
 * returns zero rather than throwing, because a misconfiguration should not
 * take checkout down.
 *
 * Free delivery exists only where a threshold has been set deliberately.
 * Zero means never free, and is the default.
 *
 * Rates are read once per request and held in memory; there are at most 38
 * rows and checkout reads them once, so a cache would add staleness for no
 * measurable gain.
 */
class DeliveryPricing
{
    /** @var array<string, DeliveryRate|null> */
    private array $resolved = [];

    private ?DeliveryRate $default = null;

    private bool $defaultLoaded = false;

    /**
     * The fee for one order going to $state.
     *
     * @param  int  $subtotalKobo  Goods only — the free threshold is judged
     *                             on what was bought, not on the total with
     *                             delivery already added.
     */
    public function feeKobo(int $subtotalKobo, ?string $state = null): int
    {
        if ($subtotalKobo <= 0) {
            return 0;
        }

        $threshold = $this->freeThresholdKobo($state);

        // Zero is "never free", so only a positive threshold waives the fee.
        if ($threshold > 0 && $subtotalKobo >= $threshold) {
            return 0;
        }

        return $this->rateFor($state)?->totalKobo() ?? 0;
    }

    /**
     * The order value that earns free delivery to $state, or zero when free
     * delivery is not offered there.
     */
    public function freeThresholdKobo(?string $state = null): int
    {
        return (int) ($this->rateFor($state)?->free_threshold_kobo ?? 0);
    }

    /**
     * The smallest order that earns free delivery anywhere, or zero when no
     * rate offers it.
     *
     * Backs the storefront's "free delivery over X" promise, which used to be
     * a hardcoded string. The lowest threshold is the honest headline: it is
     * the only figure that is true for at least one customer, and the
     * checkout quotes the exact fee anyway.
     */
    public function lowestFreeThresholdKobo(): int
    {
        return (int) DeliveryRate::query()
            ->active()
            ->where('free_threshold_kobo', '>', 0)
            ->min('free_threshold_kobo');
    }

    /** The rate governing $state: its own row, else the default row. */
    public function rateFor(?string $state): ?DeliveryRate
    {
        if ($state === null || $state === '') {
            return $this->defaultRate();
        }

        if (! array_key_exists($state, $this->resolved)) {
            $this->resolved[$state] = DeliveryRate::query()
                ->active()
                ->where('state', $state)
                ->first();
        }

        return $this->resolved[$state] ?? $this->defaultRate();
    }

    private function defaultRate(): ?DeliveryRate
    {
        if (! $this->defaultLoaded) {
            $this->default = DeliveryRate::query()->active()->whereNull('state')->first();
            $this->defaultLoaded = true;
        }

        return $this->default;
    }
}

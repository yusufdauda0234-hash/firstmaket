<?php

namespace App\Shared\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a Pay Small Small instalment falls due.
 *
 * Four rhythms, and deliberately only four. Fortnightly and quarterly were
 * offered once and chosen by nobody — every extra option is a decision an
 * admin has to make and a line a shopper has to read past, and the ones that
 * earned their place did so by matching how people are actually paid.
 *
 * Daily is here because it is how a great many Nigerians already save — the
 * ajo/esusu collector comes every day — and pretending the only rhythms are
 * weekly and monthly would exclude exactly the shoppers Pay Small Small is
 * for.
 */
enum PlanCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Payments per month, for planning rather than calendar accuracy.
     *
     * Four weeks to a month, not the exact 4.345. A term is a promise in plain
     * language — "weekly over 3 months" has to mean 12 payments, because that
     * is what anyone counting on their fingers expects. The exact figure gave
     * 13 for the same words, which read as a mistake even though it was right.
     * Thirty days to a month follows the same principle.
     */
    private const PER_MONTH = [
        'daily' => 30,
        'weekly' => 4,
        'monthly' => 1,
    ];

    /** Yearly is the one cadence measured the other way round. */
    private const MONTHS_PER_YEAR = 12;

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /** Short form for tables, where the full label is too wide. */
    public function shortLabel(): string
    {
        return $this->label();
    }

    /** The next due date after $from. */
    public function next(Carbon $from): Carbon
    {
        return match ($this) {
            self::Daily => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Yearly => $from->copy()->addMonthsNoOverflow(self::MONTHS_PER_YEAR),
        };
    }

    /** How many payments a run of $months at this cadence works out to. */
    public function installmentsFor(int $months): int
    {
        $months = max(1, $months);

        if ($this === self::Yearly) {
            // Floor, so a duration that is not a whole number of years can
            // never claim more payments than it actually contains. The
            // controller rejects those outright rather than rounding silently.
            return max(1, intdiv($months, self::MONTHS_PER_YEAR));
        }

        return $months * self::PER_MONTH[$this->value];
    }

    /**
     * Durations this cadence divides cleanly into.
     *
     * Only yearly is fussy: "yearly over 18 months" cannot be honoured without
     * either overrunning the stated duration or dropping a payment.
     */
    public function dividesEvenly(int $months): bool
    {
        return $this !== self::Yearly || $months % self::MONTHS_PER_YEAR === 0;
    }

    /**
     * The durations worth offering at this cadence.
     *
     * A free number box asked the admin to work out which combinations make
     * sense — daily over 24 months is 720 collections, and yearly over 5
     * months is not a thing at all. These are the runs that actually read
     * well, so the form offers a list rather than a box and a warning.
     *
     * @return array<int, int> Durations in months.
     */
    public function durationChoices(): array
    {
        return match ($this) {
            // Past a couple of months a daily collection is a great many
            // visits, and the instalment is down to loose change.
            self::Daily => [1, 2, 3],
            self::Weekly => [1, 2, 3, 6],
            self::Monthly => [2, 3, 4, 6, 9, 12, 18, 24],
            self::Yearly => [12, 24],
        };
    }

    /** Human duration for a term that runs $months. */
    public function durationLabel(int $months): string
    {
        if ($months < 1) {
            return 'under a month';
        }

        if ($months % 12 === 0) {
            $years = intdiv($months, 12);

            return $years.' year'.($years === 1 ? '' : 's');
        }

        return $months.' month'.($months === 1 ? '' : 's');
    }

    /**
     * The customer-facing name.
     *
     * Always derived, never typed. An admin naming a term by hand could write
     * "Easy 6" on a schedule that runs for three months, and the label would
     * quietly contradict the maths on the customer's own plan page.
     */
    public function suggestedName(int $months): string
    {
        return $this->label().' over '.$this->durationLabel($months);
    }

    /** @return array<int, array{value: string, label: string, durations: array<int, int>}> */
    public static function options(): array
    {
        return array_map(
            fn (self $cadence) => [
                'value' => $cadence->value,
                'label' => $cadence->label(),
                'durations' => $cadence->durationChoices(),
            ],
            self::cases(),
        );
    }

    /**
     * The integers the payment count is built from, for the admin form's live
     * preview.
     *
     * Sent as whole numbers rather than a payments-per-month rate because
     * yearly's rate is 1/12 — and 24 × 0.0833 floors to 1, not 2. Shipping the
     * exact divisor keeps the preview identical to what the server stores
     * instead of merely close to it.
     *
     * @return array<string, array{perMonth: int|null, monthsPer: int|null}>
     */
    public static function math(): array
    {
        $math = [];

        foreach (self::cases() as $cadence) {
            $math[$cadence->value] = $cadence === self::Yearly
                ? ['perMonth' => null, 'monthsPer' => self::MONTHS_PER_YEAR]
                : ['perMonth' => self::PER_MONTH[$cadence->value], 'monthsPer' => null];
        }

        return $math;
    }
}

<?php

use App\Models\User;
use App\Modules\Savings\Models\PlanTerm;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function planTermUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/plan-terms'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

function planTermStaff(): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole('Administrator');
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

it('counts four weekly payments to a month', function () {
    // The bug this replaced: "Weekly over 3 months" was stored with 13
    // payments, because the name and the count were separate free-text fields.
    expect(PlanCadence::Weekly->installmentsFor(1))->toBe(4)
        ->and(PlanCadence::Weekly->installmentsFor(3))->toBe(12)
        ->and(PlanCadence::Weekly->installmentsFor(12))->toBe(48);
});

it('counts one monthly payment to a month', function () {
    expect(PlanCadence::Monthly->installmentsFor(3))->toBe(3)
        ->and(PlanCadence::Monthly->installmentsFor(12))->toBe(12);
});

it('offers daily, weekly, monthly and yearly', function () {
    // Four rhythms and no more. Daily earns its place — the ajo collector
    // coming every day is how a great many Nigerians already save. Fortnightly
    // and quarterly were offered once and chosen by nobody.
    expect(collect(PlanCadence::cases())->pluck('value')->all())
        ->toBe(['daily', 'weekly', 'monthly', 'yearly']);
});

it('offers only the runs that read well at each rhythm', function () {
    // A free number box asked the admin to work out that daily over 24 months
    // is 720 collections. The list decides instead.
    expect(PlanCadence::Daily->durationChoices())->toBe([1, 2, 3])
        ->and(PlanCadence::Yearly->durationChoices())->toBe([12, 24])
        ->and(PlanCadence::Monthly->durationChoices())->toContain(3);
});

it('counts every cadence to its own rhythm', function () {
    expect(PlanCadence::Daily->installmentsFor(1))->toBe(30)
        ->and(PlanCadence::Weekly->installmentsFor(3))->toBe(12)
        ->and(PlanCadence::Yearly->installmentsFor(24))->toBe(2)
        ->and(PlanCadence::Yearly->installmentsFor(12))->toBe(1);
});

it('steps each cadence to the right next due date', function () {
    $start = Carbon::parse('2026-01-15');

    expect(PlanCadence::Daily->next($start)->toDateString())->toBe('2026-01-16')
        ->and(PlanCadence::Weekly->next($start)->toDateString())->toBe('2026-01-22')
        ->and(PlanCadence::Monthly->next($start)->toDateString())->toBe('2026-02-15')
        ->and(PlanCadence::Yearly->next($start)->toDateString())->toBe('2027-01-15');
});

it('refuses a yearly term that is not whole years', function () {
    // 18 months of yearly either overruns the stated duration or silently
    // drops a payment — exactly the label-vs-maths mismatch this all exists to
    // prevent.
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'yearly', 'duration_months' => 18])
        ->assertSessionHasErrors('duration_months');

    expect(PlanTerm::query()->count())->toBe(0);
});

it('accepts a yearly term over whole years', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'yearly', 'duration_months' => 24])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(PlanTerm::query()->first()->installments)->toBe(2);
});

it('names a term from its cadence and duration, never from the form', function () {
    // A hand-written name could say "Easy 6" on a schedule that runs three
    // months, and the label would contradict the maths on the customer own
    // plan page. The field is gone and anything posted is ignored.
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), [
            'cadence' => 'monthly',
            'duration_months' => 6,
            'name' => 'Easy 6',
        ])
        ->assertSessionHasNoErrors();

    expect(PlanTerm::query()->first()->name)->toBe('Monthly over 6 months');
});

it('caps a term at 120 payments, because each one is a card charge', function () {
    // Daily over 5 months would be 150 separate charges.
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'daily', 'duration_months' => 5])
        ->assertSessionHasErrors('duration_months');

    expect(PlanTerm::query()->count())->toBe(0);
});

it('allows a daily term inside the cap', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'daily', 'duration_months' => 1])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $term = PlanTerm::query()->first();

    // A ₦20,000 order over a daily month: 30 payments of about ₦667.
    expect($term->installments)->toBe(30)
        ->and($term->installmentKoboFor(20_000_00))->toBe(666_67);
});

it('derives the payment count on save, ignoring anything passed in', function () {
    $term = PlanTerm::query()->create([
        'name' => 'Weekly over 3 months',
        'cadence' => PlanCadence::Weekly,
        'duration_months' => 3,
        // Deliberately wrong: no code path may leave a term claiming three
        // months while charging thirteen payments.
        'installments' => 13,
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);

    expect($term->installments)->toBe(12)
        ->and($term->durationLabel())->toBe('3 months');
});

it('names a term from its own numbers when staff leave the name blank', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'weekly', 'duration_months' => 3, 'is_active' => true])
        ->assertRedirect();

    $term = PlanTerm::query()->firstWhere('cadence', PlanCadence::Weekly);

    expect($term->name)->toBe('Weekly over 3 months')
        ->and($term->installments)->toBe(12);
});

it('refuses a monthly term of one month, which is paying in full', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'monthly', 'duration_months' => 1])
        ->assertSessionHasErrors('duration_months');

    expect(PlanTerm::query()->count())->toBe(0);
});

it('allows a weekly term of one month, which is four payments', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'weekly', 'duration_months' => 1])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(PlanTerm::query()->first()->installments)->toBe(4);
});

it('rejects a duplicate cadence and duration', function () {
    $staff = planTermStaff();

    $this->actingAs($staff)->post(planTermUrl(), ['cadence' => 'monthly', 'duration_months' => 6]);
    $this->actingAs($staff)
        ->post(planTermUrl(), ['cadence' => 'monthly', 'duration_months' => 6])
        ->assertSessionHasErrors('duration_months');

    expect(PlanTerm::query()->count())->toBe(1);
});

it('caps a term at two years', function () {
    $this->actingAs(planTermStaff())
        ->post(planTermUrl(), ['cadence' => 'monthly', 'duration_months' => 25])
        ->assertSessionHasErrors('duration_months');
});

it('works the payment out from the customer own order, not a fixed figure', function () {
    $weekly = PlanTerm::query()->create([
        'cadence' => PlanCadence::Weekly,
        'duration_months' => 1,
        'name' => 'Weekly over 1 month',
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);

    // The same term against different baskets. Nothing about the term changes.
    expect($weekly->installmentKoboFor(20_000_00))->toBe(5_000_00)
        ->and($weekly->installmentKoboFor(60_000_00))->toBe(15_000_00)
        ->and($weekly->installmentKoboFor(7_500_00))->toBe(1_875_00);
});

it('carries no amount of its own', function () {
    $term = PlanTerm::query()->create([
        'cadence' => PlanCadence::Monthly,
        'duration_months' => 6,
        'name' => 'Monthly over 6 months',
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);

    // A term is a rhythm, not a price: only the minimum-order threshold is
    // money, and it gates visibility rather than setting an amount.
    expect($term->getAttributes())->not->toHaveKey('installment_kobo')
        ->and($term->getAttributes())->not->toHaveKey('amount_kobo');
});

it('divides an order by the derived payment count', function () {
    $term = PlanTerm::query()->create([
        'name' => 'Weekly over 3 months',
        'cadence' => PlanCadence::Weekly,
        'duration_months' => 3,
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);

    // ₦120,000 over 12 weekly payments is ₦10,000 — not ₦9,230 over 13.
    expect($term->installmentKoboFor(120_000_00))->toBe(10_000_00);
});

it('leaves a running plan on the terms it started with', function () {
    $term = PlanTerm::query()->create([
        'name' => 'Monthly over 3 months',
        'cadence' => PlanCadence::Monthly,
        'duration_months' => 3,
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);

    $originalInstallments = $term->installments;

    $this->actingAs(planTermStaff())
        ->put(planTermUrl((string) $term->id), ['cadence' => 'monthly', 'duration_months' => 12]);

    // The term changed for future customers…
    expect($term->fresh()->installments)->toBe(12)
        // …and the old value was what a plan would have snapshotted.
        ->and($originalInstallments)->toBe(3);
});

<?php

use App\Models\User;
use App\Modules\Reporting\Models\Expense;
use App\Modules\Reporting\Services\ExpenseService;
use App\Modules\Reporting\Services\LedgerService;
use App\Shared\Enums\ExpenseCategory;
use App\Shared\Enums\ExpenseStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function expenseStaff(string $role = 'Finance Officer'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function expenseUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/finance/expenses'.$path;
}

/** @return array<string, mixed> */
function expensePayload(array $overrides = []): array
{
    return [
        'category' => ExpenseCategory::Logistics->value,
        'description' => 'Diesel for the Ikeja generator',
        'payee' => 'Total Filling Station',
        'amount_naira' => '45000.00',
        'incurred_on' => now()->subDays(3)->toDateString(),
        'payment_method' => 'transfer',
        ...$overrides,
    ];
}

it('records an expense as pending with a reference', function () {
    $staff = expenseStaff();

    $this->actingAs($staff)
        ->post(expenseUrl(), expensePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $expense = Expense::query()->sole();

    expect($expense->amount_kobo)->toBe(45_000_00)
        ->and($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->recorded_by)->toBe($staff->id)
        ->and($expense->reference)->toMatch('/^EXP-\d{4}-\d{5}$/');
});

it('converts naira to kobo without losing a kobo to floating point', function () {
    $this->actingAs(expenseStaff())
        ->post(expenseUrl(), expensePayload(['amount_naira' => '0.29']))
        ->assertRedirect();

    // (int) (0.29 * 100) is 28 in PHP. round() is what stops a rounding
    // error becoming a discrepancy nobody can explain.
    expect(Expense::query()->sole()->amount_kobo)->toBe(29);
});

it('refuses an expense dated in the future', function () {
    $this->actingAs(expenseStaff())
        ->post(expenseUrl(), expensePayload(['incurred_on' => now()->addWeek()->toDateString()]))
        ->assertSessionHasErrors('incurred_on');

    expect(Expense::query()->count())->toBe(0);
});

it('will not let somebody approve their own expense', function () {
    $staff = expenseStaff();
    $expense = app(ExpenseService::class)->record($staff, [
        'category' => ExpenseCategory::Rent->value,
        'description' => 'Office rent',
        'amount_kobo' => 500_000_00,
        'incurred_on' => now()->subDay()->toDateString(),
    ]);

    expect(fn () => app(ExpenseService::class)->decide($staff, $expense, ExpenseStatus::Approved))
        ->toThrow(ValidationException::class);

    expect($expense->refresh()->status)->toBe(ExpenseStatus::Pending);
});

it('lets somebody else approve it', function () {
    $recorder = expenseStaff();
    $approver = expenseStaff();

    $expense = app(ExpenseService::class)->record($recorder, [
        'category' => ExpenseCategory::Rent->value,
        'description' => 'Office rent',
        'amount_kobo' => 500_000_00,
        'incurred_on' => now()->subDay()->toDateString(),
    ]);

    app(ExpenseService::class)->decide($approver, $expense, ExpenseStatus::Approved);

    expect($expense->refresh()->status)->toBe(ExpenseStatus::Approved)
        ->and($expense->approved_by)->toBe($approver->id)
        ->and($expense->approved_at)->not->toBeNull();
});

it('decides an expense once and once only', function () {
    $recorder = expenseStaff();
    $approver = expenseStaff();

    $expense = app(ExpenseService::class)->record($recorder, [
        'category' => ExpenseCategory::Other->value,
        'description' => 'Sundry',
        'amount_kobo' => 10_000_00,
        'incurred_on' => now()->subDay()->toDateString(),
    ]);

    app(ExpenseService::class)->decide($approver, $expense, ExpenseStatus::Approved);

    // Flipping an approved expense to rejected weeks later would change a
    // total somebody has already acted on.
    expect(fn () => app(ExpenseService::class)->decide($approver, $expense, ExpenseStatus::Rejected))
        ->toThrow(ValidationException::class);
});

it('leaves rejected spend out of every total', function () {
    $recorder = expenseStaff();
    $approver = expenseStaff();
    $service = app(ExpenseService::class);

    $kept = $service->record($recorder, [
        'category' => ExpenseCategory::Marketing->value,
        'description' => 'Radio advert',
        'amount_kobo' => 200_000_00,
        'incurred_on' => now()->subDays(2)->toDateString(),
    ]);
    $service->decide($approver, $kept, ExpenseStatus::Approved);

    $thrownOut = $service->record($recorder, [
        'category' => ExpenseCategory::Marketing->value,
        'description' => 'Not ours',
        'amount_kobo' => 900_000_00,
        'incurred_on' => now()->subDays(2)->toDateString(),
    ]);
    $service->decide($approver, $thrownOut, ExpenseStatus::Rejected);

    $pending = $service->record($recorder, [
        'category' => ExpenseCategory::Travel->value,
        'description' => 'Abuja trip',
        'amount_kobo' => 50_000_00,
        'incurred_on' => now()->subDays(2)->toDateString(),
    ]);

    $from = now()->subMonth();
    $to = now();

    // Recorded counts pending, approved does not — and neither counts the
    // rejected claim.
    expect($service->totalKobo($from, $to))->toBe(250_000_00)
        ->and($service->approvedKobo($from, $to))->toBe(200_000_00)
        ->and($pending->status)->toBe(ExpenseStatus::Pending);
});

it('keeps unapproved expenses out of the ledger', function () {
    $recorder = expenseStaff();
    $approver = expenseStaff();
    $service = app(ExpenseService::class);

    $approved = $service->record($recorder, [
        'category' => ExpenseCategory::Utilities->value,
        'description' => 'Electricity',
        'amount_kobo' => 80_000_00,
        'incurred_on' => now()->subDays(2)->toDateString(),
    ]);
    $service->decide($approver, $approved, ExpenseStatus::Approved);

    $service->record($recorder, [
        'category' => ExpenseCategory::Utilities->value,
        'description' => 'Water, unapproved',
        'amount_kobo' => 30_000_00,
        'incurred_on' => now()->subDays(2)->toDateString(),
    ]);

    $summary = app(LedgerService::class)->summary(now()->subMonth(), now());

    // A claim nobody has signed off is not money the business agreed it
    // spent, so the net position must not move on somebody's data entry.
    expect($summary['outKobo'])->toBe(80_000_00);
});

it('is closed to staff without the expenses permission', function () {
    $this->actingAs(expenseStaff('Logistics Personnel'))
        ->post(expenseUrl(), expensePayload())
        ->assertForbidden();

    expect(Expense::query()->count())->toBe(0);
});

it('opens the finance pages to a reports reader', function () {
    $staff = expenseStaff();
    $base = 'http://'.strtolower((string) config('app.admin_domain'));

    $this->actingAs($staff)->get($base.'/finance/summary')->assertOk();
    $this->actingAs($staff)->get($base.'/finance/transactions')->assertOk();
});

it('closes the finance pages to staff without reports.view', function () {
    $base = 'http://'.strtolower((string) config('app.admin_domain'));

    $this->actingAs(expenseStaff('Logistics Personnel'))
        ->get($base.'/finance/summary')
        ->assertForbidden();
});

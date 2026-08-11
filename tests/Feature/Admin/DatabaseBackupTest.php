<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Admin\Services\DatabaseBackupService;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The highest-privilege screen in the admin: a raw SQL export and an
 * unrestricted table wipe. Nobody is seeded with system.backup — only
 * Super Administrator reaches it, via Gate::before — so most of what
 * matters here is that the gate actually holds, and that the wipe itself
 * is safe to run (FK checks, unknown table names, audit trail).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function backupAdmin(string $role = 'Super Administrator'): User
{
    $user = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('lets a super administrator reach the backup screen', function () {
    $this->actingAs(backupAdmin())
        ->get(adminUrl('/settings/backup'))
        ->assertOk();
});

it('refuses the backup screen to an administrator, who does not hold system.backup by default', function () {
    $this->actingAs(backupAdmin('Administrator'))
        ->get(adminUrl('/settings/backup'))
        ->assertForbidden();
});

it('lists real tables with real row counts', function () {
    Category::factory()->count(3)->create();

    $tables = collect(app(DatabaseBackupService::class)->tables());

    expect($tables->pluck('name'))->toContain('categories')
        ->and($tables->firstWhere('name', 'categories')['rowCount'])->toBe(3);
});

it('never lists a table from outside this app\'s own database', function () {
    // A shared MySQL server can host other applications' databases.
    // Schema::getTableListing() must be pinned to the connection's own
    // schema — passing no schema silently returns every table on the
    // whole server, which would let this screen touch someone else's data.
    $tables = collect(app(DatabaseBackupService::class)->tables())->pluck('name');

    expect($tables->every(fn (string $table) => \Illuminate\Support\Facades\Schema::hasTable($table)))
        ->toBeTrue();
});

it('truncates a table even while another table still references it', function () {
    $category = Category::factory()->create();
    Product::factory()->approved()->create(['category_id' => $category->id]);

    // Would fail with an FK constraint error under normal FOREIGN_KEY_CHECKS.
    $wiped = app(DatabaseBackupService::class)->truncateTables(['categories']);

    expect($wiped)->toBe(['categories' => 1])
        ->and(Category::query()->count())->toBe(0);
});

it('refuses to truncate a table name that does not exist', function () {
    expect(fn () => app(DatabaseBackupService::class)->truncateTables(['not_a_real_table']))
        ->toThrow(ValidationException::class);
});

it('requires the typed DELETE confirmation before wiping data over HTTP', function () {
    Category::factory()->count(2)->create();
    $admin = backupAdmin();

    $this->actingAs($admin)
        ->post(adminUrl('/settings/backup/truncate'), ['tables' => ['categories'], 'confirm' => 'delete'])
        ->assertSessionHasErrors('confirm');

    expect(Category::query()->count())->toBe(2);

    $this->actingAs($admin)
        ->post(adminUrl('/settings/backup/truncate'), ['tables' => ['categories'], 'confirm' => 'DELETE'])
        ->assertRedirect();

    expect(Category::query()->count())->toBe(0);
});

it('records an audit entry for every table wipe', function () {
    Category::factory()->count(2)->create();
    $admin = backupAdmin();

    $this->actingAs($admin)
        ->post(adminUrl('/settings/backup/truncate'), ['tables' => ['categories'], 'confirm' => 'DELETE'])
        ->assertRedirect();

    $entry = AuditLog::query()->where('action', 'admin.database_tables_truncated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->new_values['tables'])->toBe(['categories' => 2]);
});

it('blocks a non-super staff member from wiping any data, even with the confirm phrase', function () {
    $this->actingAs(backupAdmin('Finance Officer'))
        ->post(adminUrl('/settings/backup/truncate'), ['tables' => ['categories'], 'confirm' => 'DELETE'])
        ->assertForbidden();
});

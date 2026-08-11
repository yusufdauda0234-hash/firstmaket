<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Shared\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    /** @return list<Role> */
    public function assignableRoles(): array
    {
        return Role::query()
            ->whereNotIn('name', PermissionCatalog::excludedFromStaffAssignment())
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** @return list<string> */
    public function assignableRoleNames(): array
    {
        return array_map(fn (Role $role) => $role->name, $this->assignableRoles());
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return Role::query()
            ->whereNotIn('name', PermissionCatalog::excludedFromStaffAssignment())
            ->with('permissions:name')
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'isSystem' => (bool) $role->is_system,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'staffCount' => $this->staffCount($role),
            ])
            ->all();
    }

    public function create(User $actor, string $name, ?string $description, array $permissions): Role
    {
        $this->assertCanManage($actor);
        $this->assertNameAvailable($name);
        $permissions = $this->validatedPermissions($actor, $permissions);

        $role = Role::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'description' => $description,
            'is_system' => false,
        ]);
        $role->syncPermissions($permissions);

        return $role->load('permissions:name');
    }

    public function update(User $actor, Role $role, string $name, ?string $description, array $permissions): Role
    {
        $this->assertCanManage($actor);
        if ($role->is_system && $role->name !== $name) {
            throw ValidationException::withMessages(['name' => 'System roles cannot be renamed.']);
        }

        $this->assertNameAvailable($name, $role);
        $permissions = $this->validatedPermissions($actor, $permissions);

        $role->forceFill([
            'name' => $role->is_system ? $role->name : $name,
            'description' => $description,
        ])->save();
        $role->syncPermissions($permissions);

        return $role->load('permissions:name');
    }

    public function delete(User $actor, Role $role): void
    {
        $this->assertCanManage($actor);
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'System roles cannot be deleted.']);
        }

        // model_has_roles.role_id cascades on delete, so without this check
        // a staff account still holding this role would silently lose it —
        // no error, no audit trail, just a login that suddenly can't do
        // anything. Force a reassignment first.
        $staffCount = $this->staffCount($role);
        if ($staffCount > 0) {
            throw ValidationException::withMessages([
                'role' => "This role is still assigned to {$staffCount} staff account(s). Reassign them before deleting it.",
            ]);
        }

        $role->delete();
    }

    private function staffCount(Role $role): int
    {
        return DB::table('model_has_roles')->where('role_id', $role->id)->count();
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless(
            $actor->hasRole(PermissionCatalog::SUPER_ADMINISTRATOR)
                || $actor->hasPermissionTo('roles.manage'),
            403,
        );
    }

    private function assertNameAvailable(string $name, ?Role $except = null): void
    {
        if (in_array($name, PermissionCatalog::reservedRoleNames(), true) && $except?->name !== $name) {
            throw ValidationException::withMessages(['name' => 'That role name is reserved.']);
        }

        $query = Role::query()->where('name', $name);
        if ($except) {
            $query->where('id', '!=', $except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with that name already exists.']);
        }
    }

    /** @return list<string> */
    private function validatedPermissions(User $actor, array $permissions): array
    {
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        $known = Permission::query()->whereIn('name', $permissions)->pluck('name')->all();
        if (array_diff($permissions, $known) !== []) {
            throw ValidationException::withMessages(['permissions' => 'One or more selected permissions are invalid.']);
        }

        // An actor may only grant permissions they themselves hold (Super
        // Administrator excepted). This is the only escalation rule — a
        // hardcoded "these keys are Super-Admin-only" list would drift from
        // the seeded roles (e.g. Administrator already holds staff.manage
        // directly) and block an actor from granting a permission they could
        // just as easily hand out by creating another Administrator account.
        $isSuper = $actor->hasRole(PermissionCatalog::SUPER_ADMINISTRATOR);
        $held = $actor->getAllPermissions()->pluck('name')->all();
        $forbidden = $isSuper ? [] : array_diff($known, $held);
        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You cannot grant permissions you do not hold.',
            ]);
        }

        return $known;
    }
}
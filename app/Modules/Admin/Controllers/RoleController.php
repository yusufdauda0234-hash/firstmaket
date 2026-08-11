<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\RoleService;
use App\Shared\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(RoleService $roleService): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roleService->list(),
            'permissionGroups' => PermissionCatalog::groups(),
        ]);
    }

    public function store(Request $request, RoleService $roleService): RedirectResponse
    {
        $data = $this->validated($request);
        $roleService->create($request->user(), $data['name'], $data['description'] ?? null, $data['permissions'] ?? []);

        return back()->with('success', "Role {$data['name']} created.");
    }

    public function update(Request $request, Role $role, RoleService $roleService): RedirectResponse
    {
        $data = $this->validated($request);
        $roleService->update($request->user(), $role, $data['name'], $data['description'] ?? null, $data['permissions'] ?? []);

        return back()->with('success', "Role {$data['name']} updated.");
    }

    public function destroy(Request $request, Role $role, RoleService $roleService): RedirectResponse
    {
        $roleService->delete($request->user(), $role);

        return back()->with('success', 'Role deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);
    }
}
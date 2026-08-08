<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue tree: top-level categories, their sub-categories, and the
 * sub-categories under those.
 *
 * Depth is capped at three levels. Deeper trees read well in an admin table
 * and nowhere else — shoppers stop navigating, and the storefront nav has no
 * room to show them.
 */
class CategoryController extends Controller
{
    /** Electronics > Phones > Android is as deep as the storefront goes. */
    private const MAX_DEPTH = 2;

    public function index(): Response
    {
        // Alphabetical, within each level of the tree. A manual sort_order is
        // one more thing to maintain for a list somebody is scanning by name
        // anyway — "where does Electronics live" is answered by looking it up,
        // not by remembering it was put third.
        $categories = Category::query()
            ->withCount(['products', 'children'])
            ->orderBy('name')
            ->get();

        // Sent flat with a depth, not nested: the admin table renders one row
        // per category with an indent, and a flat list keeps the client from
        // having to walk a tree to find anything.
        $byParent = $categories->groupBy('parent_id');

        $rows = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent) {
            foreach ($byParent->get($parentId, collect()) as $category) {
                $rows[] = [
                    'id' => $category->id,
                    'parentId' => $category->parent_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'isActive' => $category->is_active,
                    'depth' => $depth,
                    'productCount' => $category->products_count,
                    'childCount' => $category->children_count,
                ];
                $walk($category->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return Inertia::render('Admin/Catalog/Categories', [
            'categories' => $rows,
            'maxDepth' => self::MAX_DEPTH,
        ]);
    }

    /**
     * Switch several categories on or off at once.
     *
     * Activation only — deleting in bulk is deliberately not offered, because a
     * category with products or children cannot be deleted at all and a batch
     * delete would be mostly silent refusals. Switching one off hides it from
     * shoppers and from the vendor listing form without touching what is
     * already filed under it.
     */
    public function bulkUpdate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:activate,deactivate'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer'],
        ], [
            'ids.required' => 'Select at least one category first.',
        ]);

        $active = $validated['action'] === 'activate';

        $categories = Category::query()->whereIn('id', $validated['ids'])->get();

        foreach ($categories as $category) {
            $category->update(['is_active' => $active]);
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.categories_bulk_'.$validated['action'],
            newValues: ['category_ids' => $categories->pluck('id')->all()],
        );

        $count = $categories->count();

        return back()->with(
            'success',
            $count.' categor'.($count === 1 ? 'y' : 'ies').' '.($active ? 'switched on' : 'switched off').'.'
        );
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $this->validated($request);

        $this->assertDepthAllowed($validated['parent_id'] ?? null);

        $category = Category::query()->create([
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $auditLogger->log(
            actor: $request->user(),
            subject: $category,
            action: 'catalog.category_created',
            newValues: $category->only(['name', 'slug', 'parent_id']),
        );

        return back()->with('success', "“{$category->name}” added.");
    }

    public function update(Request $request, Category $category, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $this->validated($request, $category);

        $newParentId = $validated['parent_id'] ?? null;

        if ($newParentId !== $category->parent_id) {
            $this->assertNotItsOwnDescendant($category, $newParentId);
            $this->assertDepthAllowed($newParentId, $category);
        }

        $before = $category->only(['name', 'parent_id', 'is_active']);

        $category->update([
            'parent_id' => $newParentId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $auditLogger->log(
            actor: $request->user(),
            subject: $category,
            action: 'catalog.category_updated',
            oldValues: $before,
            newValues: $category->only(['name', 'parent_id', 'is_active']),
        );

        return back()->with('success', "“{$category->name}” updated.");
    }

    public function destroy(Request $request, Category $category, AuditLoggerContract $auditLogger): RedirectResponse
    {
        // Deleting either would strand rows that point here, so say why
        // instead of failing on a foreign key.
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => "“{$category->name}” still has sub-categories. Move or delete those first.",
            ]);
        }

        if ($category->products()->exists()) {
            $count = $category->products()->count();

            throw ValidationException::withMessages([
                'category' => "“{$category->name}” still holds {$count} product(s). Move them to another category first, or deactivate this one instead.",
            ]);
        }

        $name = $category->name;

        $auditLogger->log(
            actor: $request->user(),
            subject: $category,
            action: 'catalog.category_deleted',
            oldValues: $category->only(['name', 'slug', 'parent_id']),
        );

        $category->delete();

        return back()->with('success', "“{$name}” deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id'),
                // Guarded properly in assertNotItsOwnDescendant; this only
                // catches the obvious case early with a clearer message.
                $category ? Rule::notIn([$category->id]) : 'nullable',
            ],
            'name' => [
                'required',
                'string',
                'max:80',
                // Siblings must be distinguishable; the same name under a
                // different parent is fine ("Accessories" under both Phones
                // and Laptops).
                Rule::unique('categories', 'name')
                    ->where('parent_id', $request->input('parent_id'))
                    ->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'is_active' => ['boolean'],
        ], [
            'parent_id.not_in' => 'A category cannot sit inside itself.',
            'name.unique' => 'Another category in the same place already uses that name.',
        ]);
    }

    private function assertDepthAllowed(?int $parentId, ?Category $moving = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Category::query()->find($parentId);

        if ($parent === null) {
            return;
        }

        // Moving a branch carries its children down with it, so measure the
        // deepest leaf, not just the node being moved.
        $ownHeight = $moving ? $this->heightOf($moving) : 0;

        if ($parent->depth() + 1 + $ownHeight > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Categories can only go three levels deep.',
            ]);
        }
    }

    /** Levels of descendants beneath this category (0 when it has none). */
    private function heightOf(Category $category): int
    {
        $children = $category->children()->get();

        if ($children->isEmpty()) {
            return 0;
        }

        return 1 + $children->max(fn (Category $child) => $this->heightOf($child));
    }

    private function assertNotItsOwnDescendant(Category $category, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        $parent = Category::query()->find($newParentId);

        // Re-parenting a category under its own child would detach the whole
        // branch from the tree into an unreachable cycle.
        foreach ($parent?->ancestry() ?? [] as $ancestor) {
            if ($ancestor->id === $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => "“{$category->name}” cannot be moved inside one of its own sub-categories.",
                ]);
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}

<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductRejected;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductPostingFee;
use App\Modules\Catalog\Models\VendorFeeSetting;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\PostingTier;
use App\Shared\Enums\ProductStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every product status transition (docs/FirstMaket_Implementation_Plan.md
 * Sprint 3): Draft/Rejected → Pending Approval → Approved/Rejected;
 * Approved → Delisted, and Approved → Pending Approval again when the vendor
 * changes the price. Each transition is recorded as a status event and an
 * audit entry; admin decisions fire domain events for other modules.
 */
class ProductStatusService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function submit(Product $product, User $vendorUser, PostingTier $tier = PostingTier::Free): void
    {
        $this->assertTransition($product, [ProductStatus::Draft, ProductStatus::Rejected], ProductStatus::PendingApproval);

        DB::transaction(function () use ($product, $vendorUser, $tier) {
            $settings = VendorFeeSetting::current();
            $fee = $settings->isFreeMode() ? 0 : $settings->feeFor($tier);

            ProductPostingFee::query()->create([
                'product_id' => $product->id,
                'tier' => $settings->isFreeMode() ? PostingTier::Free : $tier,
                'amount_kobo' => $fee,
                'payment_status' => $fee === 0 ? 'not_required' : 'pending',
            ]);

            $this->transition($product, ProductStatus::PendingApproval, $vendorUser, 'Submitted for approval', [
                'submitted_at' => now(),
                'rejection_reason' => null,
            ]);
        });
    }

    public function approve(Product $product, User $admin): void
    {
        $this->assertTransition($product, [ProductStatus::PendingApproval], ProductStatus::Approved);

        DB::transaction(function () use ($product, $admin) {
            $this->transition($product, ProductStatus::Approved, $admin, null, [
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);
        });

        event(new ProductApproved($product));
    }

    public function reject(Product $product, User $admin, string $reason): void
    {
        $this->assertTransition($product, [ProductStatus::PendingApproval], ProductStatus::Rejected);

        DB::transaction(function () use ($product, $admin, $reason) {
            $this->transition($product, ProductStatus::Rejected, $admin, $reason, [
                'rejection_reason' => $reason,
            ]);
        });

        event(new ProductRejected($product));
    }

    public function delist(Product $product, ?User $actor, string $note): void
    {
        $this->assertTransition($product, [ProductStatus::Approved], ProductStatus::Delisted);

        DB::transaction(function () use ($product, $actor, $note) {
            $this->transition($product, ProductStatus::Delisted, $actor, $note, [
                'delisted_at' => now(),
            ]);
        });
    }

    /**
     * A vendor price edit on an approved product sends it back to the
     * approval queue — the new price must be re-reviewed before customers
     * see it.
     */
    public function returnToPendingAfterPriceChange(Product $product, User $vendorUser): void
    {
        $this->assertTransition($product, [ProductStatus::Approved], ProductStatus::PendingApproval);

        $this->transition($product, ProductStatus::PendingApproval, $vendorUser, 'Price changed after approval', [
            'submitted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraAttributes
     */
    private function transition(Product $product, ProductStatus $to, ?User $actor, ?string $note, array $extraAttributes = []): void
    {
        $from = $product->status;

        $product->forceFill(['status' => $to, ...$extraAttributes])->save();

        $product->statusEvents()->create([
            'old_status' => $from->value,
            'new_status' => $to->value,
            'changed_by' => $actor?->id,
            'note' => $note,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            subject: $product,
            action: 'catalog.product_status_changed',
            oldValues: ['status' => $from->value],
            newValues: ['status' => $to->value, 'note' => $note],
        );
    }

    /**
     * @param  list<ProductStatus>  $allowedFrom
     */
    private function assertTransition(Product $product, array $allowedFrom, ProductStatus $to): void
    {
        if (! in_array($product->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => "This product cannot move from {$product->status->value} to {$to->value}.",
            ]);
        }
    }
}

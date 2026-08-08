<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Models\Product;
use App\Modules\Logistics\Controllers\CourierTaskController;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Vendor\Models\VendorPayoutBatch;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\TicketStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Whatever a staff member sees when they sign in, chosen by their role.
 *
 * The administrator's version answers one question — what needs me today —
 * rather than reporting figures nobody acts on. Every number is a link to
 * the screen that clears it, and a queue at zero is hidden rather than shown
 * as a proud zero, so the page is only ever as long as the work is.
 */
class StaffDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return match (true) {
            $user->hasAnyRole(['Super Administrator', 'Administrator']) => $this->admin($request),
            $user->hasRole('Finance Officer') => Inertia::render('Finance/Dashboard'),
            $user->hasRole('Support Agent') => Inertia::render('Support/Dashboard'),
            // Delegated rather than rendered here: a courier's home screen is
            // their live workload, which only the logistics controller can
            // assemble. This used to render a stub with a hardcoded zero.
            $user->hasRole('Logistics Personnel') => app(CourierTaskController::class)->dashboard($request),
            default => throw new HttpException(403, 'This account has no staff dashboard access.'),
        };
    }

    private function admin(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'queues' => $this->queues($request->user()),
            'setup' => $this->setup(),
            'figures' => $this->figures(),
            'recentOrders' => $this->recentOrders(),
        ]);
    }

    /**
     * The work waiting for somebody.
     *
     * Each entry carries the permission that lets you act on it, so an
     * Administrator is never shown a count they cannot clear — a queue you
     * are not allowed to touch is noise, not information.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queues(User $user): array
    {
        $overdueCutoff = now();

        $all = [
            [
                'key' => 'vendors',
                'label' => 'Vendors awaiting approval',
                'count' => VendorProfile::query()->where('status', VendorStatus::Pending)->count(),
                'href' => route('admin.vendors.index'),
                'permission' => 'vendors.approve',
                'tone' => 'amber',
            ],
            [
                'key' => 'products',
                'label' => 'Listings awaiting review',
                'count' => Product::query()->where('status', ProductStatus::PendingApproval)->count(),
                'href' => route('admin.products.index'),
                'permission' => 'products.approve',
                'tone' => 'brand',
            ],
            [
                'key' => 'orders',
                'label' => 'Orders to confirm',
                'count' => Order::query()->where('status', OrderStatus::Pending)->count(),
                'href' => route('admin.orders.index', ['status' => 'pending']),
                'permission' => 'orders.manage',
                'tone' => 'brand',
            ],
            [
                'key' => 'rejected',
                'label' => 'Rejected orders to resolve',
                'count' => Order::query()->where('status', OrderStatus::VendorRejected)->count(),
                'href' => route('admin.orders.index', ['status' => 'vendor_rejected']),
                'permission' => 'orders.manage',
                'tone' => 'red',
                // A customer has paid for something the vendor cannot supply.
                // Nothing moves until somebody decides.
                'urgent' => true,
            ],
            [
                'key' => 'overdue',
                'label' => 'Vendors past their packing deadline',
                'count' => Order::query()
                    ->where('status', OrderStatus::Processing)
                    ->where('prepare_due_at', '<', $overdueCutoff)
                    ->count(),
                'href' => route('admin.orders.index', ['status' => 'processing']),
                'permission' => 'orders.manage',
                'tone' => 'amber',
            ],
            [
                'key' => 'dispatch',
                'label' => 'Parcels waiting for a courier',
                'count' => Shipment::query()->awaitingCourier()->count(),
                'href' => route('admin.dispatch.index'),
                'permission' => 'orders.manage',
                'tone' => 'brand',
            ],
            [
                'key' => 'exceptions',
                'label' => 'Deliveries out of retries',
                'count' => Shipment::query()
                    ->where('status', ShipmentStatus::Failed)
                    ->where('attempt_count', '>=', Shipment::MAX_ATTEMPTS)
                    ->count(),
                'href' => route('admin.dispatch.index'),
                'permission' => 'orders.manage',
                'tone' => 'red',
                // Three failed trips. Another van will not fix it.
                'urgent' => true,
            ],
            [
                'key' => 'support',
                'label' => 'Open support tickets',
                'count' => SupportTicket::query()->where('status', TicketStatus::Open)->count(),
                'href' => route('admin.support.index'),
                'permission' => 'support.manage',
                'tone' => 'violet',
            ],
            [
                'key' => 'phones',
                'label' => 'Phone numbers to review',
                'count' => User::query()
                    ->whereNotNull('phone')
                    ->whereNull('phone_verified_at')
                    ->count(),
                'href' => route('admin.phone.index'),
                'permission' => 'identity.review',
                'tone' => 'slate',
            ],
            [
                'key' => 'payouts',
                'label' => 'Payout batches to approve',
                'count' => VendorPayoutBatch::query()
                    ->where('status', PayoutBatchStatus::PendingApproval)
                    ->count(),
                'href' => route('admin.payouts.index'),
                'permission' => 'vendor_payouts.approve',
                'tone' => 'emerald',
            ],
        ];

        return collect($all)
            ->filter(fn (array $queue) => $user->can($queue['permission']))
            ->values()
            ->all();
    }

    /**
     * What is still unconfigured.
     *
     * A marketplace with no delivery rates quotes ₦0 to ship, and one with no
     * categories cannot take a listing at all — both look like bugs and are
     * really just an empty settings table. Saying so on the first screen
     * somebody sees is cheaper than letting them discover it at checkout.
     *
     * Disappears entirely once everything is set, so it is a one-time
     * scaffold rather than permanent furniture.
     *
     * @return array<int, array<string, mixed>>
     */
    private function setup(): array
    {
        $steps = [
            [
                'label' => 'Add product categories',
                'why' => 'Vendors cannot list anything until at least one exists.',
                'done' => Category::query()->exists(),
                'href' => route('admin.catalog.categories'),
            ],
            [
                'label' => 'Set delivery rates',
                'why' => 'With none, checkout quotes ₦0 to deliver and plans carry no fee.',
                'done' => DeliveryRate::query()->exists(),
                'href' => route('admin.settings.delivery-rates'),
            ],
            [
                'label' => 'Add a display currency',
                'why' => 'What shoppers see prices in. Naira is charged either way.',
                'done' => DisplayCurrency::query()->exists(),
                'href' => route('admin.settings.currencies'),
            ],
            [
                'label' => 'Offer a Pay Small Small term',
                'why' => 'Without one, the instalment option never appears at checkout.',
                'done' => PlanTerm::query()->where('is_active', true)->exists(),
                'href' => route('admin.settings.plan-terms'),
            ],
            [
                'label' => 'Approve your first vendor',
                'why' => 'Nothing can be sold until somebody is approved to sell it.',
                'done' => VendorProfile::query()->where('status', VendorStatus::Approved)->exists(),
                'href' => route('admin.vendors.index'),
            ],
            [
                'label' => 'Publish your first listing',
                'why' => 'The storefront is empty until a listing is approved.',
                'done' => Product::query()->where('status', ProductStatus::Approved)->exists(),
                'href' => route('admin.products.index'),
            ],
        ];

        return collect($steps)->every(fn (array $step) => $step['done']) ? [] : $steps;
    }

    /**
     * The last thirty days, against the thirty before it.
     *
     * A bare figure says nothing — "₦2.4m" is either very good or very bad
     * depending on last month. The comparison is the information.
     *
     * @return array<int, array<string, mixed>>
     */
    private function figures(): array
    {
        $from = now()->subDays(30);
        $priorFrom = now()->subDays(60);

        $sold = fn ($start, $end) => Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::VendorRejected]);

        $current = $sold($from, now());
        $prior = $sold($priorFrom, $from);

        return [
            [
                'key' => 'orders',
                'label' => 'Orders',
                'value' => (clone $current)->count(),
                'prior' => (clone $prior)->count(),
                'money' => false,
            ],
            [
                'key' => 'sales',
                'label' => 'Sales',
                'value' => (int) (clone $current)->sum('locked_price_kobo'),
                'prior' => (int) (clone $prior)->sum('locked_price_kobo'),
                'money' => true,
            ],
            [
                // What FirstMaket actually keeps, which is the only figure
                // that pays the bills.
                'key' => 'commission',
                'label' => 'Commission earned',
                'value' => (int) (clone $current)->sum('commission_amount_kobo'),
                'prior' => (int) (clone $prior)->sum('commission_amount_kobo'),
                'money' => true,
            ],
            [
                'key' => 'customers',
                'label' => 'New customers',
                'value' => User::query()
                    ->where('user_type', UserType::Customer)
                    ->whereBetween('created_at', [$from, now()])
                    ->count(),
                'prior' => User::query()
                    ->where('user_type', UserType::Customer)
                    ->whereBetween('created_at', [$priorFrom, $from])
                    ->count(),
                'money' => false,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentOrders(): array
    {
        return Order::query()
            ->with(['product:id,name', 'vendor:id,business_name', 'customer:id,name'])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Order $order) => [
                'uuid' => $order->uuid,
                'productName' => $order->product?->name ?? 'Deleted listing',
                'vendorName' => $order->vendor?->business_name ?? '—',
                'customerName' => $order->customer?->name ?? '—',
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'priceKobo' => $order->locked_price_kobo,
                'placedAt' => $order->created_at->diffForHumans(),
                'href' => route('admin.orders.show', $order->uuid),
            ])
            ->all();
    }
}

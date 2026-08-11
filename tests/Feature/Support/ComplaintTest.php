<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ComplaintCategory;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\SupportChannel;
use App\Shared\Enums\TicketPriority;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2C Complaint Centre.
 *
 * A complaint is a support ticket on the Complaint channel, not a parallel
 * system — so staff work one inbox and threading, assignment and audit all
 * come along unchanged.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->support = app(SupportService::class);
    $this->customer = User::factory()->create();
});

function complaintOrder(User $customer): Order
{
    $category = Category::factory()->create();
    $vendor = VendorProfile::factory()->create();
    $product = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
    ]);

    return Order::query()->create([
        'customer_id' => $customer->id,
        'vendor_id' => $vendor->id,
        'product_id' => $product->id,
        'delivery_address' => '1 Test Street',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'status' => OrderStatus::Delivered,
        'locked_price_kobo' => 100_000,
        'commission_rate_percent' => '10.00',
        'commission_source' => 'default',
        'commission_amount_kobo' => 10_000,
        'vendor_earning_amount_kobo' => 90_000,
    ]);
}

it('files a complaint as a ticket on the complaint channel', function () {
    $ticket = $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Delivery,
        subject: 'Courier left without delivering',
        message: 'The courier marked it delivered but nothing arrived at my address.',
    );

    expect($ticket->channel)->toBe(SupportChannel::Complaint)
        ->and($ticket->complaint_category)->toBe(ComplaintCategory::Delivery)
        // Threading comes free from the ticket system.
        ->and($ticket->messages()->count())->toBe(1);
});

it('escalates a money complaint without asking the customer to rate it', function () {
    $money = $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Payment,
        subject: 'Charged twice',
        message: 'My card was debited two times for the same order this morning.',
    );

    $ordinary = $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Other,
        subject: 'Website suggestion',
        message: 'It would be good if the search remembered my last filter.',
    );

    // Everybody marks their own problem urgent, so the category decides.
    expect($money->priority)->toBe(TicketPriority::High)
        ->and($ordinary->priority)->toBe(TicketPriority::Normal);
});

it('treats an undelivered item as urgent too', function () {
    $ticket = $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::ItemNotReceived,
        subject: 'Never arrived',
        message: 'It has been two weeks since it was marked shipped and nothing has come.',
    );

    expect($ticket->priority)->toBe(TicketPriority::High);
});

it('attaches the order and its vendor when one is named', function () {
    $order = complaintOrder($this->customer);

    $ticket = $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Product,
        subject: 'Item is not what was described',
        message: 'The listing said 128GB but the box and the phone both say 64GB.',
        aboutOrder: $order,
    );

    expect($ticket->about_order_id)->toBe($order->id)
        // Recorded so vendor-conduct complaints can be counted per vendor.
        ->and($ticket->about_vendor_id)->toBe($order->vendor_id);
});

it('refuses to attach somebody else order', function () {
    $order = complaintOrder(User::factory()->create());

    expect(fn () => $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Delivery,
        subject: 'Where is my parcel',
        message: 'This order does not belong to me but I am trying to attach it anyway.',
        aboutOrder: $order,
    ))->toThrow(ValidationException::class);
});

it('files a complaint over HTTP and shows it back to the customer', function () {
    $this->actingAs($this->customer)
        ->post('/support/complaints', [
            'category' => ComplaintCategory::Delivery->value,
            'subject' => 'Courier never came',
            'message' => 'Waited in all day on the delivery date and nobody arrived or called.',
        ])
        ->assertRedirect();

    expect(SupportTicket::query()->where('channel', SupportChannel::Complaint)->count())->toBe(1);

    $this->actingAs($this->customer)
        ->get('/support/complaints')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Support/Complaints/Create')
            ->has('complaints', 1));
});

it('requires enough detail to act on', function () {
    $this->actingAs($this->customer)
        ->post('/support/complaints', [
            'category' => ComplaintCategory::Delivery->value,
            'subject' => 'Bad',
            'message' => 'Bad',
        ])
        ->assertSessionHasErrors(['subject', 'message']);
});

it('keeps one customer complaints out of another list', function () {
    $this->support->openComplaint(
        customer: $this->customer,
        category: ComplaintCategory::Delivery,
        subject: 'Courier never came',
        message: 'Waited in all day and nobody arrived at the address given.',
    );

    $this->actingAs(User::factory()->create())
        ->get('/support/complaints')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('complaints', 0));
});

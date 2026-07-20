<?php

use App\Models\User;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Support\Models\HotlineCallLog;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\TicketStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Sprint 7 QA: tickets, hotline logs with IVR routing, agent workflow, and
 * the safe read-only customer lookup.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create([
        'user_id' => $this->customer->id,
        'identity_status' => IdentityStatus::Verified,
        'bvn' => '12345678901',
    ]);

    $this->agent = User::factory()->create(['user_type' => UserType::Staff]);
    $this->agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->agent->assignRole('Support Agent');
});

it('lets a customer open a ticket and reply on the thread', function () {
    $this->actingAs($this->customer)
        ->post(route('support.tickets.store'), [
            'subject' => 'My delivery is late',
            'message' => 'Order was due yesterday.',
        ])
        ->assertRedirect();

    $ticket = SupportTicket::query()->where('customer_id', $this->customer->id)->firstOrFail();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->messages)->toHaveCount(1);

    $this->actingAs($this->customer)
        ->post(route('support.tickets.reply', $ticket->uuid), ['message' => 'Any update?'])
        ->assertRedirect();

    expect($ticket->refresh()->messages)->toHaveCount(2);
});

it('keeps one customer’s ticket invisible to another', function () {
    $this->actingAs($this->customer)
        ->post(route('support.tickets.store'), ['subject' => 'Private issue', 'message' => 'Details here.']);
    $ticket = SupportTicket::query()->firstOrFail();

    $other = User::factory()->create();
    $other->assignRole('Customer');

    $this->actingAs($other)->get(route('support.tickets.show', $ticket->uuid))->assertForbidden();
});

it('routes a hotline request to a logged call and a high-priority payment ticket', function () {
    $this->actingAs($this->customer)
        ->post(route('support.hotline.request'), [
            'phone' => '+2348012345678',
            'reason' => 'payment_issue',
        ])
        ->assertRedirect();

    $log = HotlineCallLog::query()->where('customer_id', $this->customer->id)->firstOrFail();

    expect($log->reason->value)->toBe('payment_issue')
        ->and($log->ivr_selection)->toBe('1')
        ->and($log->ticket)->not->toBeNull()
        ->and($log->ticket->channel->value)->toBe('hotline')
        ->and($log->ticket->priority->value)->toBe('high')
        // Attached to the customer account.
        ->and($log->customer_id)->toBe($this->customer->id);
});

it('lets an agent reply (notifying the customer), change status, and auto-assign', function () {
    $this->actingAs($this->customer)
        ->post(route('support.tickets.store'), ['subject' => 'Help', 'message' => 'Please help.']);
    $ticket = SupportTicket::query()->firstOrFail();

    $this->actingAs($this->agent)
        ->post(adminUrl("/support/{$ticket->uuid}/reply"), ['message' => 'On it!'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->assigned_to)->toBe($this->agent->id);

    Notification::assertSentTo($this->customer, TicketReplyNotification::class);

    $this->actingAs($this->agent)
        ->post(adminUrl("/support/{$ticket->uuid}/status"), ['status' => 'resolved'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at)->not->toBeNull();
});

it('gives support agents order/plan context but never BVN/NIN or card fields', function () {
    $response = $this->actingAs($this->agent)
        ->get(adminUrl('/support/lookup?q='.urlencode((string) $this->customer->email).'&customer='.$this->customer->id))
        ->assertOk();

    $props = $response->viewData('page')['props'];
    $serialized = json_encode($props['customer']);

    expect($props['customer']['name'])->toBe($this->customer->name)
        ->and($props['customer']['identityStatus'])->toBe('verified')
        // The raw BVN value never appears anywhere in the payload.
        ->and($serialized)->not->toContain('12345678901')
        ->and($serialized)->not->toContain('bvn')
        ->and($serialized)->not->toContain('card');
});

it('blocks support routes for staff without support.manage', function () {
    $logistics = User::factory()->create(['user_type' => UserType::Staff]);
    $logistics->forceFill(['two_factor_confirmed_at' => now()])->save();
    $logistics->assignRole('Logistics Personnel');

    $this->actingAs($logistics)->get(adminUrl('/support'))->assertForbidden();
    $this->actingAs($logistics)->get(adminUrl('/support/lookup'))->assertForbidden();
});

it('serves the public FAQ without login', function () {
    $this->seed(FaqSeeder::class);

    $response = $this->get(route('faq'))->assertOk();

    expect($response->viewData('page')['props']['faqs'])->not->toBeEmpty();
});

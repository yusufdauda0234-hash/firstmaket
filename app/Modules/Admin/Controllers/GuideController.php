<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin manual: what every screen in this workspace is for and how to work
 * it.
 *
 * One page rather than a help panel on each screen. Explanations scattered
 * across a dozen pages get read once and then sit there taking up the space
 * above the table forever; a single manual can be read start to finish when
 * someone joins, and searched when they are stuck.
 *
 * Sections are filtered by permission, so nobody is taught a screen they cannot
 * open.
 */
class GuideController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $sections = collect($this->sections())
            ->filter(fn (array $section) => $section['permission'] === null || $user->can($section['permission']))
            ->values()
            ->all();

        return Inertia::render('Admin/Guide', ['sections' => $sections]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sections(): array
    {
        return [
            [
                'id' => 'vendors',
                'group' => 'Marketplace',
                'title' => 'Vendors',
                'permission' => 'vendors.view',
                'route' => 'admin.vendors.index',
                'summary' => 'Seller applications, and the sellers already trading.',
                'points' => [
                    'Tabs across the top are the application status. Pending is the queue that needs you; the rest are history you can search.',
                    'Clicking a row opens the full application — business details, contact, and the CAC document if one was uploaded.',
                    'Approve lets them list products. Reject asks for a reason, and that reason is what the applicant is told, so write it for them rather than for the file.',
                    'Add vendor creates a seller yourself, for someone onboarded offline. The CAC document is optional there because you have usually already seen it, and you can approve immediately instead of sending them round the queue.',
                    'We never set a vendor password. They get a single-use link to choose their own, so nobody on the team ever knows their credentials.',
                    'Tick several rows to approve or reject them together. Anything a colleague already actioned is skipped and counted rather than stopping the batch.',
                    'Suspending a trading vendor hides their listings without deleting anything; reinstating puts them back.',
                ],
            ],
            [
                'id' => 'products',
                'group' => 'Marketplace',
                'title' => 'Products',
                'permission' => 'products.approve',
                'route' => 'admin.products.index',
                'summary' => 'Vendor listings waiting to reach the catalogue.',
                'points' => [
                    'Grid is the default here because judging a listing is largely judging its photograph. Switch to Table when you are comparing prices or vendors.',
                    'Open a listing to see everything the vendor submitted, including the AI review notes where one ran.',
                    'Approve puts it in the catalogue immediately. Reject asks for a reason, which the vendor sees and has to act on — "photos do not show the actual item" is useful, "rejected" is not.',
                    'Bulk approve and reject work on the ticked rows. A shared rejection reason goes to every one selected, so keep it general.',
                    'Delisting an approved product pulls it from the storefront without deleting it or disturbing orders already placed.',
                ],
            ],
            [
                'id' => 'categories',
                'group' => 'Marketplace',
                'title' => 'Categories',
                'permission' => 'catalog.manage',
                'route' => 'admin.catalog.categories',
                'summary' => 'What vendors can list under, and how shoppers browse.',
                'points' => [
                    'A product belongs to exactly one category. The tree decides both what a vendor may file it under and how shoppers navigate the storefront.',
                    'Nesting goes three levels deep. Use the + on a row to add a child under it — Electronics → Phones → Accessories.',
                    'Product form fields follow the tree: a field set on Electronics also applies to everything nested beneath it. Put shared fields high and specific ones low.',
                    'Switching a category off hides it from shoppers and from the vendor listing form, but leaves the products already filed under it untouched. This is the safe way to retire one.',
                    'Deleting is refused while a category still holds products or child categories — you would be orphaning listings. Move them, or just switch it off.',
                    'Order controls the position shoppers see, lowest first; ties fall back to alphabetical.',
                ],
            ],
            [
                'id' => 'product-fields',
                'group' => 'Marketplace',
                'title' => 'Product fields',
                'permission' => 'catalog.manage',
                'route' => 'admin.catalog.fields',
                'summary' => 'The questions vendors answer when listing.',
                'points' => [
                    'Name, description, price, stock and images are always asked. Everything here is extra — the details that differ by product type, like colour, wattage or a demo video.',
                    'A field set on a category applies to that category and everything nested under it. Leave the category blank and it applies everywhere.',
                    'Dropdown and multi-select need a list of choices and are what shoppers can filter on later. Text and number are free entry, URL validates the address, yes/no renders a checkbox.',
                    'The kind of field locks once a vendor has answered it — turning text into a dropdown afterwards would leave existing answers meaningless. Switch the old field off and add a replacement.',
                    'Required means a vendor cannot submit without it. Use it sparingly: every required field is one more reason a listing never gets finished.',
                    'Switching a field off stops it being asked but keeps every answer already given. Deleting throws those answers away, which is why each row shows how many listings use it.',
                ],
            ],
            [
                'id' => 'orders',
                'group' => 'Operations',
                'title' => 'Orders and deliveries',
                'permission' => 'orders.manage',
                'route' => 'admin.orders.index',
                'summary' => 'Every order from payment through to the customer confirming delivery.',
                'points' => [
                    'An order only exists once payment has actually cleared through Paystack, so nothing here is speculative.',
                    'Assign logistics to put an order with a delivery person; they see it on their own dashboard.',
                    'Vendors never see a customer\'s name, phone number or address — deliveries run through FirstMaket, and that separation is deliberate.',
                    'Payment is held until the customer confirms delivery. That confirmation is what releases the vendor\'s earnings, so chasing it matters.',
                ],
            ],
            [
                'id' => 'customers',
                'group' => 'Customer care',
                'title' => 'Customers',
                'permission' => 'customers.suspend',
                'route' => 'admin.users.index',
                'summary' => 'Customer accounts, and the moderation tools for them.',
                'points' => [
                    'Search matches name, email or phone. The status tabs narrow it further.',
                    'Add customer creates an account for someone who ordered by phone or in person. As with vendors, no password is set — they get a link to choose their own.',
                    'Suspending ends the account\'s sessions on its next request and needs a reason. Bulk suspend applies one shared reason to everyone ticked.',
                    'Ban is deliberately not available in bulk. It is the one step with no easy way back, so it stays on the individual screen where you can see exactly who you are banning.',
                    'Opening a customer shows their orders and plans for support context, but never card details — we do not hold them.',
                ],
            ],
            [
                'id' => 'support',
                'group' => 'Customer care',
                'title' => 'Support',
                'permission' => 'support.manage',
                'route' => 'admin.support.index',
                'summary' => 'Customer tickets and the call-to-order hotline log.',
                'points' => [
                    'Replying notifies the customer by email. Changing status is what moves a ticket out of the open queue.',
                    'A ticket auto-assigns to whoever replies first, so grab one by answering it.',
                    'Agents get order and plan context on the customer, which is usually enough to answer without asking them to repeat themselves.',
                ],
            ],
            [
                'id' => 'plan-terms',
                'group' => 'Money',
                'title' => 'Pay Small Small terms',
                'permission' => 'vendor_fees.manage',
                'route' => 'admin.settings.plan-terms',
                'summary' => 'The rhythms a customer may spread payment over.',
                'points' => [
                    'A term sets the rhythm, never the price. No amount is stored on one.',
                    'The payment is always the customer\'s own order total divided by the number of payments, worked out at checkout. A ₦20,000 order on Weekly over 1 month is 4 payments of ₦5,000; the same term on a ₦60,000 order is ₦15,000 a week. Nothing here changes for different order sizes.',
                    'Pick the cadence and how many months it runs; the payment count follows. Daily counts 30 to a month, weekly 4, every two weeks 2, monthly 1, every three months 1 per quarter.',
                    'Daily exists because it is how many people already save — the ajo collector comes every day. Three-monthly terms must run in whole quarters, and no term may exceed 120 payments, because each payment is a separate card charge.',
                    'Minimum order hides a term below that basket value. It does not set a price — it stops a twelve-month plan being offered on a ₦3,000 item.',
                    'Editing a term never touches a plan already running; those snapshot their terms at signup. That is also why a term customers have used cannot be deleted — switch it off instead.',
                    'The price is locked when a plan starts, and the order ships once the final payment clears. Paying ahead is allowed; paying more than is owed is not.',
                ],
            ],
            [
                'id' => 'currencies',
                'group' => 'Money',
                'title' => 'Display currencies',
                'permission' => 'vendor_fees.manage',
                'route' => 'admin.settings.currencies',
                'summary' => 'What shoppers can browse in. Display only.',
                'points' => [
                    'Every price is stored and charged in naira. These rates only change what a shopper sees while browsing; the naira total is always on the pay button.',
                    'Rates are yours to maintain — there is no live feed. The screen flags anything not reviewed in 30 days, because a stale rate is quoting shoppers a price you cannot honour.',
                    'Switch a currency off rather than deleting it if you are unsure; shoppers using it fall back to naira on their next request.',
                    'The naira itself cannot be edited or removed — everything is priced in it.',
                ],
            ],
            [
                'id' => 'fees',
                'group' => 'Money',
                'title' => 'Fees and commissions',
                'permission' => 'vendor_fees.manage',
                'route' => 'admin.settings.fees',
                'summary' => 'What the business charges vendors.',
                'points' => [
                    'Posting fees decide what a vendor pays to list. Commission decides what FirstMaket keeps from a sale.',
                    'Changes apply to what happens next, never retrospectively to orders already placed.',
                ],
            ],
            [
                'id' => 'reconciliation',
                'group' => 'Money',
                'title' => 'Reconciliation and payouts',
                'permission' => 'savings.reconcile',
                'route' => 'admin.reconciliation.index',
                'summary' => 'Matching Paystack settlements against our own ledger.',
                'points' => [
                    'Import the Paystack settlement file and the screen matches it line by line against what we recorded.',
                    'Anything that does not match is listed rather than auto-corrected — a mismatch is a question to answer, not a number to overwrite.',
                    'Vendor payouts run against cleared earnings only, which means delivery has been confirmed by the customer.',
                ],
            ],
            [
                'id' => 'security',
                'group' => 'Your account',
                'title' => 'Signing in and two-factor',
                'permission' => null,
                'route' => null,
                'summary' => 'How staff access to this workspace is protected.',
                'points' => [
                    'Administrators, Super Administrators and Finance Officers must enrol an authenticator app before reaching anything else here.',
                    'Every sign-in then asks for a 6-digit code as well as your password. A password on its own is not enough to get in.',
                    'Trust this device for 30 days skips the code on a machine you alone use. Do not tick it on a shared computer.',
                    'Recovery codes are shown once, when you enrol. Save them — if you lose your phone they are the only way back in, and each works once.',
                    'Everything you do in this workspace is written to the audit log against your name.',
                ],
            ],
        ];
    }
}

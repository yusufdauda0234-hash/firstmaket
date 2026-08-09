<?php

namespace Database\Seeders;

use App\Modules\Support\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * Starting text for the three pages an outside service insists on.
 *
 * Seeded rather than left to be written by hand because the URLs have to
 * answer before Google or Meta will approve social sign-in, and a 404 there
 * fails the review with no clue as to why. The wording below describes what
 * this codebase actually does — what is collected, what Paystack holds rather
 * than us, and the fact that money in a plan never becomes cash — so it is a
 * true starting point rather than filler. It still needs a lawyer's eye
 * before launch, and it is editable at /settings/pages precisely so that
 * review does not need a deploy.
 *
 * Re-running this seeder never overwrites an edited page. Whoever last
 * touched the wording in the admin screen wins, or a routine `db:seed` after
 * a deploy would quietly revert a legal correction.
 */
class ContentPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $slug => $attributes) {
            $page = ContentPage::query()->firstOrNew(['slug' => $slug]);

            // The lock is structural and always reasserted; the prose is only
            // written on first creation.
            $page->is_system = true;

            if (! $page->exists) {
                $page->fill($attributes);
                $page->effective_at = now();
                $page->is_published = true;
                $page->show_in_footer = true;
            }

            $page->save();
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function pages(): array
    {
        $hotline = (string) config('services.support.hotline');

        return [
            'terms' => [
                'title' => 'Terms of Service',
                'summary' => 'The agreement between you and FirstMaket when you buy, sell, or pay for an item in instalments.',
                'sort_order' => 10,
                'sections' => [
                    [
                        'heading' => 'Who we are',
                        'body' => "FirstMaket is an online marketplace operating in Nigeria. We connect customers with independent vendors, handle payment, and deliver the items ordered.\n\nBy creating an account or placing an order you agree to these terms. If you do not agree with them, please do not use the service.",
                    ],
                    [
                        'heading' => 'What FirstMaket is not',
                        'body' => "FirstMaket is not a bank, a lender, a loan app, or a buy-now-pay-later service. We are not licensed to take deposits and we do not offer credit.\n\nIn particular:\n\n- There is no wallet and no account balance. You cannot add money to FirstMaket to hold for later.\n- Money you pay is always paid towards a specific item.\n- There is no cash withdrawal. Money that has been paid in cannot be paid back out to you as cash.",
                    ],
                    [
                        'heading' => 'Your account',
                        'body' => "You must give accurate details when you register, and keep your password to yourself. You are responsible for what happens under your account.\n\nWe may suspend or close an account that is used for fraud, that repeatedly refuses delivered orders, or that breaks these terms.",
                    ],
                    [
                        'heading' => 'Orders and prices',
                        'body' => "Placing an order is an offer to buy. The order is accepted when we confirm it.\n\nPrices are shown in naira and include what the vendor charges for the item. Delivery is charged separately and is shown before you pay. If a price is listed in another currency, that figure is a guide only — the naira amount on the pay button is what you are charged.\n\nIf an item turns out to be unavailable or a price is clearly wrong, we may cancel the order and return what you paid.",
                    ],
                    [
                        'heading' => 'Paying in instalments (Pay Small Small)',
                        'body' => "Pay Small Small lets you pay for a specific item over time, on a schedule you choose from the options we offer.\n\n1. You choose an item and a payment schedule.\n2. The price of that item is held for you while you pay.\n3. When the full amount has been paid, we deliver the item.\n\nThis is not credit. You receive the item after it is paid for, not before, so there is no interest and no debt.\n\nIf you stop a plan, the money you have already paid becomes credit towards another item on FirstMaket. It is not lost, and it is not refunded as cash. Credit does not expire.\n\nIf you miss payments for longer than the plan allows, we may close the plan and move what you paid to credit in the same way.",
                    ],
                    [
                        'heading' => 'Delivery',
                        'body' => "We deliver to the address you give us. Please make sure it is correct and that someone can receive the item.\n\nWhere an order is paid on delivery, payment is due to the courier when the item is handed over. A courier will not leave an item that has not been paid for.\n\nDelivery times we quote are estimates, not guarantees.",
                    ],
                    [
                        'heading' => 'Returns and faulty items',
                        'body' => "If an item arrives damaged, faulty, or is not what was described, tell us as soon as you can and we will put it right — by replacement or by returning what you paid for it.\n\nWe cannot accept a return simply because you changed your mind after delivery, unless the vendor's own policy allows it.",
                    ],
                    [
                        'heading' => 'Selling on FirstMaket',
                        'body' => "Vendors are independent businesses, not employees or agents of FirstMaket.\n\nIf you sell here, you agree that:\n\n- Your listings are accurate, and you actually have the stock you list.\n- You hold whatever licences the law requires for what you sell.\n- You will not list anything illegal, counterfeit, or unsafe.\n- We may remove a listing, or suspend a shop, that breaks these rules.\n\nWe take a commission on each sale, and any listing fees that apply are shown to you before you list. What is left is paid to your registered bank account.",
                    ],
                    [
                        'heading' => 'Promotional codes',
                        'body' => "Promotional codes are funded by FirstMaket, not by vendors, and can be withdrawn at any time. A code may have a minimum order value, an expiry date, and a limit on how many times it can be used. Codes have no cash value.",
                    ],
                    [
                        'heading' => 'Our liability',
                        'body' => "We are responsible for delivering what you ordered and for handling your payment properly.\n\nWe are not responsible for how an item performs beyond the vendor's stated description and any manufacturer's warranty, nor for losses we could not reasonably have foreseen.\n\nNothing here removes a right you have under Nigerian law that cannot be signed away.",
                    ],
                    [
                        'heading' => 'Changes to these terms',
                        'body' => "We may update these terms. The effective date at the top of this page tells you which version is current. If a change materially affects you, we will tell you before it takes effect.",
                    ],
                    [
                        'heading' => 'Governing law and how to reach us',
                        'body' => "These terms are governed by the laws of the Federal Republic of Nigeria.\n\nIf something has gone wrong, please contact us first — most problems are quicker to fix than to argue about. You can reach us through the Help Centre, or on ".$hotline.'.',
                    ],
                ],
            ],

            'privacy-policy' => [
                'title' => 'Privacy Policy',
                'summary' => 'What information FirstMaket collects, why we hold it, who we share it with, and how to get it removed.',
                'sort_order' => 20,
                'sections' => [
                    [
                        'heading' => 'The short version',
                        'body' => "We collect what we need to take your order, deliver it, and keep your account secure. We do not sell your information to anyone.\n\nThis policy explains the detail. If anything here is unclear, ask us — the answer is part of the policy.",
                    ],
                    [
                        'heading' => 'What we collect',
                        'body' => "When you use FirstMaket we collect:\n\n- Your name, email address and phone number, so we can identify you and reach you about an order.\n- Your password, stored scrambled. Nobody at FirstMaket can read it.\n- The delivery addresses you give us.\n- What you have ordered, saved towards, or paid, and when.\n- Messages you send our support team.\n- Technical information your device sends when you visit: your IP address, your browser, and the pages you open. This is how we keep accounts secure and spot fraud.\n\nIf you sell on FirstMaket we also collect your business name, contact name, business address, your bank account details so we can pay you, and any registration or identity documents we need to verify the business.\n\nWe do not ask for your BVN or your NIN.",
                    ],
                    [
                        'heading' => 'Card details',
                        'body' => "Card payments are handled by Paystack, a licensed Nigerian payment processor. Your card number and CVV are entered on Paystack's own secure page and are never sent to FirstMaket.\n\nIf you choose to save a card for next time, what we store is a token issued by Paystack, together with the card type and the last four digits so you can tell your cards apart. That token cannot be used anywhere except to charge your card through Paystack on this site, and you can remove it at any time.",
                    ],
                    [
                        'heading' => 'Why we hold it',
                        'body' => "We use your information to:\n\n- Take, deliver, and support your orders.\n- Run instalment plans and keep an accurate record of what has been paid.\n- Pay vendors what they are owed.\n- Detect and prevent fraud, and keep accounts secure.\n- Answer your support requests.\n- Meet our obligations under Nigerian tax and financial-record law.\n\nWe will only send you marketing messages if you have asked for them, and every one has a way to stop them.",
                    ],
                    [
                        'heading' => 'Who we share it with',
                        'body' => "We share the minimum necessary with:\n\n- The vendor whose item you ordered — enough to prepare and dispatch it.\n- Our delivery couriers — your name, address and phone number, so they can deliver.\n- Paystack, to process payments.\n- Our email and SMS providers, to send you order and account messages.\n- The authorities, where the law requires it.\n\nWe do not sell your information, and we do not share it for anybody else's advertising.",
                    ],
                    [
                        'heading' => 'Signing in with Google or Facebook',
                        'body' => "If you choose to sign in with Google or Facebook, they tell us your name, your email address, and your profile picture, so we can create or find your account.\n\nWe do not receive your password for those accounts, we cannot post anything on them, and we do not read your contacts or your friends.",
                    ],
                    [
                        'heading' => 'How long we keep it',
                        'body' => "We keep your account information for as long as your account is open.\n\nAfter an account is closed we delete personal details, but we keep records of completed transactions for as long as tax and accounting law requires. Those records are kept because we are obliged to keep them, and are not used for anything else.",
                    ],
                    [
                        'heading' => 'Keeping it safe',
                        'body' => "Passwords are stored scrambled and cannot be recovered, only reset. Sensitive fields such as addresses and bank details are encrypted in our database. Staff accounts that can see customer records require two-factor authentication, and every look-up and change is logged.\n\nNo system is perfectly secure, but if a breach ever affects your information we will tell you and the regulator.",
                    ],
                    [
                        'heading' => 'Your rights',
                        'body' => "Under the Nigeria Data Protection Act you may:\n\n- Ask what we hold about you, and get a copy.\n- Ask us to correct anything wrong.\n- Ask us to delete your account and your personal information.\n- Object to us using your information in a particular way, or withdraw a consent you gave.\n\nTo do any of these, contact us through the Help Centre or on ".$hotline.". See our Data Deletion page for how deletion works in detail.\n\nIf you are not satisfied with how we have handled a request, you may complain to the Nigeria Data Protection Commission.",
                    ],
                    [
                        'heading' => 'Cookies',
                        'body' => "We use cookies to keep you signed in, remember what is in your basket, and keep the site secure. Turning them off in your browser will stop parts of the site from working. We do not use cookies to track you across other websites.",
                    ],
                    [
                        'heading' => 'Children',
                        'body' => 'FirstMaket is for people aged 18 and over. We do not knowingly collect information about children. If you believe a child has given us their details, tell us and we will remove them.',
                    ],
                    [
                        'heading' => 'Changes and contact',
                        'body' => "We may update this policy. The effective date at the top tells you which version is current.\n\nQuestions about your information can be sent through the Help Centre, or on ".$hotline.'.',
                    ],
                ],
            ],

            'data-deletion' => [
                'title' => 'Deleting your data',
                'summary' => 'How to ask FirstMaket to delete your account and the information we hold about you.',
                'sort_order' => 30,
                'sections' => [
                    [
                        'heading' => 'You can ask us to delete your data',
                        'body' => 'If you no longer want a FirstMaket account, you can ask us to close it and delete the personal information we hold about you. This page explains how to ask, what happens, and how long it takes.',
                    ],
                    [
                        'heading' => 'How to ask',
                        'body' => "Choose whichever is easiest:\n\n1. Sign in and open the Support Center, then send us a message asking for your account and data to be deleted.\n2. Call ".$hotline." and ask for your account to be deleted.\n\nPlease send the request from the email address or phone number on the account. If you cannot, we will ask you a few questions to confirm the account is yours — we will not delete an account on somebody else's say-so.",
                    ],
                    [
                        'heading' => 'If you signed in with Google or Facebook',
                        'body' => "Removing FirstMaket from your Google or Facebook settings stops that account being used to sign in here, but it does not delete the FirstMaket account itself, because the two are separate.\n\nTo have the FirstMaket account deleted as well, send us the request described above.",
                    ],
                    [
                        'heading' => 'What gets deleted',
                        'body' => "Once we have confirmed the request, we remove:\n\n- Your name, email address, phone number and password.\n- Your saved delivery addresses.\n- Any saved card token, so no card can be charged again.\n- Your support conversations.\n- Your basket, saved items and browsing history.\n\nIf you sell on FirstMaket, we also remove your shop profile, your listings, and your bank details once everything you are owed has been paid.",
                    ],
                    [
                        'heading' => 'What we have to keep, and why',
                        'body' => "Nigerian tax and financial-record law requires us to keep records of completed transactions, including sales, refunds and payouts, for a set number of years.\n\nWe keep those records, and nothing more. They are separated from your account, not used to contact you, and not used for anything except meeting that legal obligation.",
                    ],
                    [
                        'heading' => 'Before you ask',
                        'body' => "Deletion cannot be undone, so it is worth checking two things first.\n\n- Open orders. If something is on its way to you, wait until it arrives. We cannot deliver to an account that no longer exists.\n- Instalment plans and credit. Money in a Pay Small Small plan, and any credit on your account, can only be spent on FirstMaket — it cannot be paid out as cash. Deleting your account gives up anything unspent. If you have money in a plan, finish it or move it to another item first.",
                    ],
                    [
                        'heading' => 'How long it takes',
                        'body' => "We confirm every request within 7 days and complete deletion within 30 days.\n\nIf anything is holding it up — an order still out for delivery, or a payout still to be made — we will tell you what it is and when it will clear.",
                    ],
                    [
                        'heading' => 'If something goes wrong',
                        'body' => "If we have not dealt with your request properly, you may complain to the Nigeria Data Protection Commission.\n\nSee our Privacy Policy for the full account of what we collect and why.",
                    ],
                ],
            ],
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Support\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Launch FAQ content (docs/FirstMaket_Implementation_Plan.md Sprint 7).
 * Idempotent: keyed on the question text.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            ['Getting started', 'What is FirstMaket?', 'FirstMarket is a Nigerian marketplace where you can buy products outright (Pay At Once) or save toward them gradually at a locked price (Save Small Small). Products come from verified vendors and FirstMarket handles every delivery.'],
            ['Getting started', 'How do I create an account?', 'Register with your email address or phone number — you will receive a 6-digit code to verify it. You can also continue with Google or Facebook.'],
            ['Savings', 'How does Save Small Small work?', 'Pick any product and start a Product Target Plan. The price you see is locked for you, then you contribute daily, weekly, or monthly from your wallet until you reach 100%. The moment your plan is fully funded, the product ships to you.'],
            ['Savings', 'Does the price change while I am saving?', 'No. Your target price is locked the day you start the plan, even if the vendor later raises the price.'],
            ['Savings', 'Can I get my savings back as cash?', 'No — FirstMarket is not a bank and never pays out cash. Your savings always move toward a product: you can redirect them to a different product at any time while a plan is active.'],
            ['Savings', 'What is Open Savings?', 'A flexible pot with no target product. Fund it from your wallet and redirect it into any Product Target Plan whenever you decide what to buy.'],
            ['Wallet & payments', 'How do I add money to my wallet?', 'Go to My Wallet → Add money. You pay securely through Paystack by card, bank transfer, or USSD, and your balance updates the moment payment is confirmed.'],
            ['Wallet & payments', 'Is my payment information safe?', 'Yes. Payments are processed entirely by Paystack — FirstMarket never sees or stores your card details.'],
            ['Wallet & payments', 'Why do I need to verify my phone number?', 'A verified phone number adds a layer of account recovery and security, but it is optional — you can fund your wallet and use FirstMarket without it.'],
            ['Orders & delivery', 'Who delivers my order?', 'FirstMarket logistics handles every delivery from the vendor to your door. Vendors never see your name, phone number, or address.'],
            ['Orders & delivery', 'How do I track my order?', 'Open My Orders and select the order — you will see every step from vendor preparation to out-for-delivery, and we email you at each stage.'],
            ['Orders & delivery', 'What happens if the vendor is out of stock?', 'The order is cancelled and the full amount is moved into your Open Savings immediately, so you can redirect it to another product. No money is ever lost.'],
            ['Vendors', 'How do I sell on FirstMaket?', 'Register as a vendor with your CAC document. Once approved, list your products, set your own prices, and get paid to your verified bank account after each confirmed delivery.'],
            ['Vendors', 'When do vendors get paid?', 'Earnings are credited when a customer confirms delivery, and cleared balances are paid out to your verified bank account in the weekly payout run.'],
        ];

        foreach ($faqs as $index => [$category, $question, $answer]) {
            Faq::query()->updateOrCreate(
                ['question' => $question],
                [
                    'category' => $category,
                    'answer' => $answer,
                    'status' => 'published',
                    'sort_order' => $index,
                ],
            );
        }
    }
}

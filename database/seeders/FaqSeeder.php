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
            ['Getting started', 'What is FirstMaket?', 'FirstMaket is a Nigerian marketplace where you can pay for a product in full, or pay it off in instalments at a locked price (Pay Small Small). Products come from verified vendors and FirstMaket handles every delivery.'],
            ['Getting started', 'How do I create an account?', 'Register with your email address or phone number — you will receive a 6-digit code to verify it. You can also continue with Google or Facebook.'],
            ['Pay Small Small', 'How does Pay Small Small work?', 'At checkout choose "Pay Small Small" instead of paying in full, then pick a plan — for example weekly over three months, or monthly over a year. We divide the total into equal instalments and lock the price. You pay each instalment by card, and the moment the last one clears we ship your order.'],
            ['Pay Small Small', 'Who decides the plans available?', 'FirstMaket sets them. Each plan says how often you pay and how many payments it takes, so you always know the instalment amount and the finish date before you commit.'],
            ['Pay Small Small', 'Does the price change while I am paying?', 'No. The price is locked the day you start the plan, even if the vendor later raises it.'],
            ['Pay Small Small', 'Can I pay ahead or clear it early?', 'Yes. Pay a bigger amount whenever you like and you will finish sooner. You can never pay more than the amount still outstanding.'],
            ['Pay Small Small', 'Can I get my money back as cash?', 'No — FirstMaket is not a bank and never pays out cash. If you cancel a plan, everything you have paid becomes credit that goes toward another product. The money is not lost, but it stays inside FirstMaket.'],
            ['Pay Small Small', 'Can I keep money in FirstMaket for later?', 'No. There is no wallet and no balance to top up. Money only ever exists inside a plan for a specific order, or as credit from a cancelled plan waiting to go toward your next one.'],
            ['Payments', 'Is my payment information safe?', 'Yes. Payments are processed entirely by Paystack — FirstMaket never sees or stores your card details.'],
            ['Payments', 'Why do I need to verify my phone number?', 'A verified phone number adds a layer of account recovery and security, but it is optional — you can order and run a plan without it.'],
            ['Orders & delivery', 'Who delivers my order?', 'FirstMaket logistics handles every delivery from the vendor to your door. Vendors never see your name, phone number, or address.'],
            ['Orders & delivery', 'How do I track my order?', 'Open My Orders and select the order — you will see every step from vendor preparation to out-for-delivery, and we email you at each stage.'],
            ['Orders & delivery', 'What happens if the vendor is out of stock?', 'The order is cancelled and the full amount becomes credit toward another product, so nothing is lost.'],
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

        // This list is the whole published FAQ, so anything not in it is stale
        // and must go. Without this, renaming a question leaves the old answer
        // live — which is how the FAQ ended up still describing the wallet and
        // "Add money" long after both were removed.
        Faq::query()
            ->whereNotIn('question', array_column($faqs, 1))
            ->delete();
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Launch Categories
    |--------------------------------------------------------------------------
    |
    | The six launch categories shown in public navigation and on the home
    | page. Sprint 3 replaces this static list with the categories table;
    | the seeder should use these same slugs so public URLs stay stable.
    |
    */

    'categories' => [
        ['name' => 'Electronics', 'slug' => 'electronics'],
        ['name' => 'Home Appliances', 'slug' => 'home-appliances'],
        ['name' => 'Solar Equipment', 'slug' => 'solar-equipment'],
        ['name' => 'Furniture', 'slug' => 'furniture'],
        ['name' => 'Fashion', 'slug' => 'fashion'],
        ['name' => 'Business Equipment', 'slug' => 'business-equipment'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Flat nationwide delivery fee in kobo, waived once the cart subtotal
    | reaches the free threshold — the "free shipping over NGN 15,000" the
    | storefront header already promises. Charged for real at checkout and
    | stored on the checkout session, so the cart summary is not decorative.
    |
    */

    'shipping' => [
        'flat_fee_kobo' => (int) env('FirstMaket_SHIPPING_FEE_KOBO', 150_000),
        'free_threshold_kobo' => (int) env('FirstMaket_FREE_SHIPPING_KOBO', 1_500_000),
    ],

    'support' => [
        'hotline' => env('FirstMaket_SUPPORT_HOTLINE', '0700-FirstMaket'),
        'whatsapp' => env('FirstMaket_SUPPORT_WHATSAPP', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Savings plans
    |--------------------------------------------------------------------------
    |
    | How long a customer may hold a plan paused.
    |
    | Pausing suspends the payment reminders and any automatic debit, which
    | also means the dormancy sweep stops counting missed payments. A plan
    | freezes its price at signup, so an unbounded pause would be an
    | indefinite price lock — buy at today's price, pause for two years, come
    | back and collect. The pause therefore expires: after this many days the
    | plan behaves normally again and can be warned and swept like any other.
    | Nothing is charged or cancelled at the moment it expires.
    |
    */

    'savings' => [
        'max_pause_days' => (int) env('FirstMaket_MAX_PAUSE_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Returns and refunds
    |--------------------------------------------------------------------------
    |
    | These are the numbers the product page prints and the numbers the code
    | enforces — deliberately one source, because a published policy that the
    | system does not actually apply is a promise the business cannot keep.
    |
    | `window_days` runs from delivery, not from the order date: an order that
    | spent three weeks in transit has not spent its return window in transit.
    |
    */

    'returns' => [
        'window_days' => (int) env('FirstMaket_RETURN_WINDOW_DAYS', 7),
        // Working days quoted to the customer for the money to land back on
        // their card. Paystack settles refunds on its own timetable; this is
        // what we tell people, and it matches the product page.
        'refund_days_min' => (int) env('FirstMaket_REFUND_DAYS_MIN', 5),
        'refund_days_max' => (int) env('FirstMaket_REFUND_DAYS_MAX', 10),
    ],

];

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

];

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

    'support' => [
        'hotline' => env('FirstMaket_SUPPORT_HOTLINE', '0700-FirstMaket'),
        'whatsapp' => env('FirstMaket_SUPPORT_WHATSAPP', ''),
    ],

];

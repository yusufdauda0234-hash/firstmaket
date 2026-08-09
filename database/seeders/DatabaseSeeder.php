<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a working FirstMaket needs before anyone signs in.
 *
 * The dividing line is whether a human enters it. Categories, delivery rates
 * and plan terms are all built in admin by the people who run the shop, so
 * seeding them only put rows there that somebody then had to find and undo —
 * a seeded nationwide ₦0 delivery rate once quietly made every order free.
 * Those seeders are gone; what is left is the scaffolding nobody types in.
 *
 * The demo catalogue seeders are gone for the same reason: five invented
 * vendors and fifty listings made a fresh install indistinguishable from a
 * real one. Merchandise comes in through the vendor portal.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Who may do what, and the one account that can hand that out.
            RolesAndPermissionsSeeder::class,
            SuperAdministratorSeeder::class,

            // Describes the vendor product form itself. Staff reword these,
            // but cannot create them — without the rows the admin "Product
            // fields" screen is blank and the form cannot name its inputs.
            BuiltInProductFieldSeeder::class,

            // Reference data, not settings: the currencies prices can be
            // shown in, and the help content the storefront links to.
            DisplayCurrencySeeder::class,
            FaqSeeder::class,

            // The terms, privacy policy and data-deletion pages. Seeded for
            // the same reason as the product fields: staff edit the wording,
            // but the rows have to exist, because Google and Meta fetch those
            // three URLs during sign-in review and a 404 fails it. Never
            // overwrites an edited page.
            ContentPageSeeder::class,
        ]);
    }
}

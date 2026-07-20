<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SuperAdministratorSeeder::class,
            CategorySeeder::class,
            DemoProductImagesSeeder::class,
            DemoMerchandisingSeeder::class,
            FaqSeeder::class,
        ]);
    }
}

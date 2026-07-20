<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorProfile>
 */
class VendorProfileFactory extends Factory
{
    protected $model = VendorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['user_type' => UserType::Vendor]),
            'business_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'address' => fake()->address(),
            'status' => VendorStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => VendorStatus::Approved,
            'approved_at' => now(),
        ]);
    }
}

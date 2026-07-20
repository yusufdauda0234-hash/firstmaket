<?php

namespace Database\Factories;

use App\Models\User;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+234'.fake()->unique()->numerify('8##########'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => UserType::Customer,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'status' => UserStatus::PendingVerification,
        ]);
    }

    /**
     * Staff accounts (Admin, Support, Logistics, Finance) must carry the
     * Staff user_type — the portal separation middleware keys off it.
     */
    public function staff(): static
    {
        return $this->state(fn () => ['user_type' => UserType::Staff]);
    }
}

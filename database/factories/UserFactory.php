<?php

namespace Database\Factories;

use App\Enums\SellerType;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Per instance, NOT static: a static cache outlives RefreshDatabase's
     * rollback and hands out city ids that no longer exist.
     */
    private ?array $cityIds = null;

    private function cityId(): ?int
    {
        $this->cityIds ??= City::pluck('id')->all();

        return $this->cityIds ? $this->cityIds[array_rand($this->cityIds)] : null;
    }

    public function definition(): array
    {
        $username = $this->faker->unique()->userName();

        return [
            'name'              => $this->faker->name(),
            'username'          => $username,
            'email'             => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'phone_hash'        => hash('sha256', Str::random(40)),
            'phone_last4'       => (string) $this->faker->numberBetween(1000, 9999),
            'phone_country'     => 'BG',
            'password'          => Hash::make('password'),
            'city_id'           => $this->cityId(),
            'seller_type'       => SellerType::Private,
            'deals_completed'   => $c = $this->faker->numberBetween(0, 40),
            'deals_abandoned'   => $this->faker->numberBetween(0, max(1, (int) ($c * 0.15))),
            'rating_count'      => $c,
            'rating_avg'        => $c > 0 ? $this->faker->randomFloat(2, 4.1, 5.0) : null,
            'remember_token'    => Str::random(10),
        ];
    }

    public function trader(): static
    {
        return $this->state(fn () => [
            'seller_type'    => SellerType::Trader,
            'trader_details' => [
                'company' => $this->faker->company().' ЕООД',
                'uic'     => (string) $this->faker->numberBetween(100000000, 999999999),
                'address' => $this->faker->address(),
            ],
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);
    }
}

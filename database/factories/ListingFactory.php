<?php

namespace Database\Factories;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Development data only. Prices are derived from the part's age and class so
 * that the browse page looks plausible rather than random - a 4090 must not
 * come out cheaper than a 1050 Ti, or every screenshot looks broken.
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * Id pools, cached PER FACTORY INSTANCE - deliberately not static.
     *
     * Laravel reuses one instance across ->count(140)->create(), so a bulk seed
     * still costs three queries instead of 420. But a static cache would
     * survive RefreshDatabase rolling the database back between tests, and the
     * factory would then insert city_id values that no longer exist. That bug
     * failed 36 tests.
     */
    private ?array $partIds = null;
    private ?array $userIds = null;
    private ?array $cityIds = null;

    public function definition(): array
    {
        $this->partIds ??= Part::pluck('id')->all();
        $this->userIds ??= User::pluck('id')->all();
        $this->cityIds ??= City::pluck('id')->all();

        $part = $this->partIds
            ? Part::find($this->faker->randomElement($this->partIds))
            : null;
        $condition = $this->faker->randomElement(ListingCondition::cases());
        $price     = $this->estimatePrice($part, $condition);

        // Roughly one seller in three sets a floor; the rest take any offer.
        $hasFloor = $this->faker->boolean(35);

        $mining = $part?->category === 'gpu'
            ? $this->faker->randomElement([MiningUse::No, MiningUse::No, MiningUse::No, MiningUse::Yes, MiningUse::Unknown])
            : MiningUse::No;

        return [
            'user_id'              => $this->userIds ? $this->faker->randomElement($this->userIds) : User::factory(),
            'part_id'              => $part?->id,
            'category'             => $part?->category ?? 'other',
            'city_id'              => $this->cityIds ? $this->faker->randomElement($this->cityIds) : null,
            'title'                => $part ? $part->fullName() : 'Компютърен компонент',
            'description'          => $this->description($condition),
            'condition'            => $condition,
            'quantity'             => 1,
            'price_cents'          => $price,
            'offers_enabled'       => $this->faker->boolean(85),
            /*
             * A closure, not a value computed from $price above: the database
             * enforces min_offer <= price, and a test that overrides
             * price_cents would otherwise get a floor derived from a price the
             * row never had. That failed roughly one run in six - only when the
             * random multiplier happened to land above the overridden price,
             * which is the worst kind of test to be handed on a Friday.
             *
             * Closures are resolved after overrides are merged, so this always
             * sees the price the row is actually being inserted with.
             */
            'min_offer_cents'      => $hasFloor
                ? fn (array $attributes) => (int) round(
                    $attributes['price_cents'] * $this->faker->randomFloat(2, 0.75, 0.95)
                )
                : null,
            'warranty_until'       => $this->faker->boolean(25) ? $this->faker->dateTimeBetween('now', '+2 years') : null,
            'has_receipt'          => $this->faker->boolean(30),
            'mining_use'           => $mining,
            // Same reasoning as min_offer_cents: mining_months is guarded by a
            // CHECK constraint tying it to mining_use, so it has to be derived
            // from the value actually being inserted.
            'mining_months'        => function (array $attributes) {
                $use = $attributes['mining_use'] ?? MiningUse::No;
                $use = $use instanceof MiningUse ? $use : MiningUse::from((string) $use);

                return $use === MiningUse::Yes ? $this->faker->numberBetween(3, 30) : null;
            },
            'accepts_inspect_test' => $this->faker->boolean(80),
            'specs'                => [],
            'delivery_options'     => $this->faker->randomElements(['econt', 'speedy', 'pickup'], $this->faker->numberBetween(1, 3)),
            'status'               => ListingStatus::Active,
            'view_count'           => $this->faker->numberBetween(0, 900),
            'published_at'         => $ts = $this->faker->dateTimeBetween('-45 days', 'now'),
            'bumped_at'            => $ts,
            'expires_at'           => now()->addDays(60),
        ];
    }

    /**
     * Crude but monotonic: newer and bigger cards cost more, condition
     * discounts from there. Good enough to make the price filter meaningful.
     */
    private function estimatePrice(?Part $part, ListingCondition $condition): int
    {
        if (! $part) {
            return $this->faker->numberBetween(2000, 20000);
        }

        $base = match ($part->category) {
            'gpu' => ($part->spec('vram_gb') ?? 8) * 1800
                   + (($part->launch_year ?? 2018) - 2015) * 3500,
            'cpu' => ($part->spec('cores') ?? 6) * 1200
                   + (($part->launch_year ?? 2018) - 2015) * 2500,
            default => 8000,
        };

        $multiplier = match ($condition) {
            ListingCondition::New      => 1.05,
            ListingCondition::LikeNew  => 0.88,
            ListingCondition::Used     => 0.72,
            ListingCondition::ForParts => 0.22,
        };

        $noise = $this->faker->randomFloat(2, 0.9, 1.1);

        return max(1500, (int) round($base * $multiplier * $noise));
    }

    private function description(ListingCondition $condition): string
    {
        $lines = match ($condition) {
            ListingCondition::New      => ['Чисто нова, неразпечатана. Купена от български магазин.'],
            ListingCondition::LikeNew  => ['Ползвана съвсем малко, без забележки. Пълен комплект с кутия.'],
            ListingCondition::Used     => ['Работи безупречно. Нормални следи от употреба по бекплейта.',
                                           'Ползвана за игри, поддържана чиста. Сменена паста.'],
            ListingCondition::ForParts => ['НЕ РАБОТИ. Продава се за части или ремонт. Няма да я тествам.'],
        };

        return $this->faker->randomElement($lines)
            .' Изпращам с Еконт или Спиди, приемам преглед и тест.';
    }
}

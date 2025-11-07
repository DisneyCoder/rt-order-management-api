<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id'     => Product::inRandomOrder()->first()?->id ?? Product::factory(),
            'sku'            => strtoupper(Str::random(8)),
            'sale_price'     => $this->faker->numberBetween(200, 2000),
            'purchase_price' => $this->faker->numberBetween(100, 1500),
            'quantity'       => $this->faker->numberBetween(5, 50),
            'last_update_at' => now(),
        ];
    }
}

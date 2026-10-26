<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<License> */
class LicenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['number' => 'LIC-'.fake()->unique()->uuid(), 'customer_id' => Customer::factory(), 'product_id' => Product::factory(), 'user_id' => User::factory(), 'quantity' => 5, 'starts_on' => today()->subDays(10), 'ends_on' => today()->addDays(30), 'stopped' => false];
    }
}

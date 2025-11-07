<?php

namespace Database\Seeders;

use App\Models\Stock;
use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'admin123',
        ]);

        Product::factory(20)->has(Stock::factory()->count(3))->create();
    }
}
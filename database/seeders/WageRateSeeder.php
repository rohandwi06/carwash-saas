<?php

namespace Database\Seeders;

use App\Models\WageRate;
use Illuminate\Database\Seeder;

class WageRateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('carwash.default_wages') as $category => $amount) {
            WageRate::firstOrCreate(['category' => $category], ['amount' => $amount]);
        }
    }
}

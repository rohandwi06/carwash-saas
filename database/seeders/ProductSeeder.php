<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['Air Mineral', 'minuman', 5000],
            ['Teh Botol', 'minuman', 6000],
            ['Kopi Sachet', 'minuman', 7000],
            ['Es Teh', 'minuman', 5000],
            ['Mie Instan', 'makanan', 10000],
            ['Snack', 'makanan', 5000],
        ];

        foreach ($products as [$name, $type, $price]) {
            Product::firstOrCreate(['name' => $name], ['type' => $type, 'price' => $price]);
        }
    }
}

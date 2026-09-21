<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            // motor
            ['Honda Vario','motor'],['Honda PCX','motor'],['Honda Scoopy','motor'],
            ['Honda BeAT','motor'],['Honda Stylo','motor'],['Yamaha NMAX','motor'],
            ['Yamaha Aerox','motor'],['Yamaha Mio','motor'],['Suzuki Zumi','motor'],
            // kecil
            ['Honda Brio','kecil'],['Toyota Agya','kecil'],['Daihatsu Ayla','kecil'],
            ['Toyota Calya','kecil'],['Daihatsu Sigra','kecil'],['Suzuki Karimun Wagon R','kecil'],
            ['Nissan March','kecil'],['Mitsubishi Mirage','kecil'],['Kia Picanto','kecil'],
            ['Honda Jazz','kecil'],['Toyota Yaris','kecil'],['Mazda 2','kecil'],
            ['Suzuki Swift','kecil'],['Suzuki Baleno','kecil'],['Suzuki Ignis','kecil'],
            ['Hyundai i10','kecil'],['Wuling Air EV','kecil'],['Hyundai Atoz','kecil'],
            ['Datsun Go Panca','kecil'],
            // sedang
            ['Toyota Avanza','sedang'],['Daihatsu Xenia','sedang'],['Honda Mobilio','sedang'],
            ['Suzuki Ertiga','sedang'],['Mitsubishi Xpander','sedang'],['Nissan Livina','sedang'],
            ['Toyota Veloz','sedang'],['Toyota Rush','sedang'],['Daihatsu Terios','sedang'],
            ['Toyota Raize','sedang'],['Daihatsu Rocky','sedang'],['Toyota Vios','sedang'],
            ['Honda City','sedang'],['Honda Civic','sedang'],['Honda Accord','sedang'],
            ['Toyota Corolla Altis','sedang'],['Toyota Sienta','sedang'],['Honda Freed','sedang'],
            ['Honda HR-V','sedang'],['Honda BR-V','sedang'],['Hyundai Creta','sedang'],
            ['Kia Seltos','sedang'],['Wuling Confero','sedang'],['Hyundai Stargazer','sedang'],
            ['Toyota Kijang Innova','sedang'],['Toyota Fortuner','sedang'],
            ['Mitsubishi Pajero Sport','sedang'],['Isuzu Panther','sedang'],['Honda CR-V','sedang'],
            ['Nissan X-Trail','sedang'],['Hyundai Santa Fe','sedang'],['Mazda CX-5','sedang'],
            ['Toyota Camry','sedang'],['Hyundai Palisade','sedang'],['Wuling Cortez','sedang'],
            ['Kia Sorento','sedang'],['Chevrolet Trailblazer','sedang'],['Daihatsu Luxio','sedang'],
            ['BYD Atto 3','sedang'],['Jeep Wrangler Rubicon','sedang'],
            // besar
            ['Toyota Alphard','besar'],['Toyota Vellfire','besar'],['Toyota Hiace','besar'],
            ['Toyota Land Cruiser','besar'],['Mitsubishi Triton','besar'],
            ['Toyota Hilux Double Cabin','besar'],['Isuzu D-Max','besar'],
            ['Kia Carnival','besar'],['Isuzu Elf Minibus','besar'],
        ];

        foreach ($vehicles as [$name, $category]) {
            Vehicle::firstOrCreate(['name' => $name], ['category' => $category]);
        }
    }
}

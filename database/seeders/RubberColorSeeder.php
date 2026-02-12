<?php

namespace Database\Seeders;

use App\Models\InsRubberColor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RubberColorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InsRubberColor::create([
            'name' => 'Black',
            'description' => 'Black rubber color',
        ]);
        InsRubberColor::create([
            'name' => 'White',
            'description' => 'White rubber color',
        ]);
    }
}

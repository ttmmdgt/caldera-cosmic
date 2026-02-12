<?php

namespace Database\Seeders;

use App\Models\InsRubberModel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RubberModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InsRubberModel::create([
            'name' => 'AIR FORCE ONE',
            'descriptions' => 'AIR FORCE ONE rubber model',
        ]);
        InsRubberModel::create([
            'name' => 'A5',
            'descriptions' => 'A5 rubber model',
        ]);
        InsRubberModel::create([
            'name' => 'AM270',
            'descriptions' => 'AM270 rubber model',
        ]);
        InsRubberModel::create([
            'name' => 'REJUVEN',
            'descriptions' => 'REJUVEN rubber model',
        ]);
    }
}

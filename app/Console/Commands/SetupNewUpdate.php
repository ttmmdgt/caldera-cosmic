<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ShiftSeeder;
use App\Models\InsRubberModel;
use App\Models\InsRubberColor;
use App\Models\InsPhDosingDevice;


class SetupNewUpdate extends Command {
    protected $signature = 'app:setup-new-update';
    protected $description = 'Setup new update';

    public function handle() {
        $this->info('Setup new update');
        $this->info('Checking database connection...');
        $this->checkDatabaseConnection();
        $this->setupNewDatabase();
        sleep(1);
        $this->seedDatabase();
        sleep(1);
        $this->info('Setup new update completed');
    }


    private function setupNewDatabase() {
        // check migrate status
        $this->info('Checking migration status...');
        Artisan::call('migrate:status');
        $migrateStatus = Artisan::output();
        
        // check if there are pending migrations
        if (str_contains($migrateStatus, 'Pending')) {
            $this->warn('Pending migrations detected. Running migrations...');
            Artisan::call('migrate', ['--force' => true]);
            $this->info('Migrations completed successfully');
            $this->info(Artisan::output());
        } else {
            $this->info('No pending migrations found');
        }
    }

    private function checkDatabaseConnection() {
        $databaseConnection = config('database.connections.mysql');
        if ($databaseConnection) {
            $this->info('Database connection successful');
            return true;
        } else {
            $this->error('Database connection failed, please check your database connection settings');
            return false;
        }
    }

    private function seedDatabase() {
        // project seeder
        $this->info('Seeding project...');
        Artisan::call('db:seed', ['--class' => ProjectSeeder::class]);
        $this->info('Project seeded successfully');
        $this->info(Artisan::output());
        // shift seeder
        $this->info('Seeding shift...');
        Artisan::call('db:seed', ['--class' => ShiftSeeder::class]);
        $this->info('Shift seeded successfully');
        $this->info(Artisan::output());

        // omv model seeder
        $this->info('Seeding omv model...');
        $data = [
            ['name' => 'AIR MAX 95', 'description' => 'AIR MAX 95 model'],
            ['name' => 'AIR MAX TW', 'description' => 'AIR MAX TW model'],
            ['name' => 'AIR MAX 270', 'description' => 'AIR MAX 270 model'],
            ['name' => 'DOWNSHIFTER 12', 'description' => 'DOWNSHIFTER 12 model'],
            ['name' => 'DOWNSHIFTER 13', 'description' => 'DOWNSHIFTER 13 model'],
            ['name' => 'ALPHA TRAINER 4', 'description' => 'ALPHA TRAINER 4 model'],
            ['name' => 'ALPHA TRAINER 5', 'description' => 'ALPHA TRAINER 5 model'],
            ['name' => 'ALPHA TRAINER 6', 'description' => 'ALPHA TRAINER 6 model'],
            ['name' => 'AM PULSE', 'description' => 'AM PULSE model'],
            ['name' => 'INVIGOR', 'description' => 'INVIGOR model'],
            ['name' => 'PHOENIX WAFFLE', 'description' => 'PHOENIX WAFFLE model'],
            ['name' => 'QUEST 6', 'description' => 'QUEST 6 model'],
            ['name' => 'STRUCTURE 26', 'description' => 'STRUCTURE 26 model'],
            ['name' => 'AF1 WOMAN', 'description' => 'AF1 WOMAN model'],
            ['name' => 'FIELD GENERAL', 'description' => 'FIELD GENERAL model'],
            ['name' => 'REJUVEN', 'description' => 'REJUVEN model'],
            ['name' => 'AF1 GS', 'description' => 'AF1 GS model'],
            ['name' => 'COURTH BOROUGH', 'description' => 'COURTH BOROUGH model'],
            ['name' => 'AM 270', 'description' => 'AM 270 model'],
            ['name' => 'PEGASUS 37/38', 'description' => 'PEGASUS 37/38 model'],
            ['name' => 'PEGASUS 39/40', 'description' => 'PEGASUS 39/40 model'],
            ['name' => 'PEGASUS 41', 'description' => 'PEGASUS 41 model'],
            ['name' => 'AL8', 'description' => 'AL8 model'],
            ['name' => 'AIR MAX 95 BIG BUBBLE OG', 'description' => 'AIR MAX 95 BIG BUBBLE OG model'],
            ['name' => 'VOMERO 18 GS', 'description' => 'VOMERO 18 GS model'],
            ['name' => 'AVA ROVER', 'description' => 'AVA ROVER model'],
            ['name' => 'AIR MAX PHOENIX', 'description' => 'AIR MAX PHOENIX model'],
            ['name' => 'PEGASUS 42', 'description' => 'PEGASUS 42 model'],
            ['name' => 'DOWNSHIFTER 14', 'description' => 'DOWNSHIFTER 14 model'],
        ];
        InsRubberModel::insert($data);
        $this->info('OMV model seeded successfully');
        $this->info(Artisan::output());
        // omv color seeder
        $this->info('Seeding omv color...');
        $data = [
            ['name' => 'BLACK', 'description' => 'Black omv color'],
            ['name' => 'WHITE', 'description' => 'White omv color'],
        ];
        InsRubberColor::insert($data);
        $this->info('OMV color seeded successfully');
        $this->info(Artisan::output());

        // pds device seeder
        $this->info('Seeding pds device...');
        $data = [
            ['name' => 'Plant E', 'plant' => 'E', 'ip_address' => '192.168.1.1', 'config' => json_encode([]), 'is_active' => true],
        ];
        InsPhDosingDevice::insert($data);
        $this->info('PDS device seeded successfully');
        $this->info(Artisan::output());
    }

}
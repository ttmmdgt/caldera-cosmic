<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('project_group');
            $table->string('ip');
            $table->integer('timeout')->default(10);
            $table->string('type'); // modbus, dwp, etc.
            $table->json('modbus_config')->nullable(); // Store modbus configuration
            $table->enum('location', ['TT', 'TC'])->default('TT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index('project_group');
            $table->index('type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

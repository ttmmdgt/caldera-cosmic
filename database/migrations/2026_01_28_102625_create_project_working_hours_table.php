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
        Schema::create('project_working_hours', function (Blueprint $table) {
            $table->id();
            $table->string('project_group'); // Matches the project_group field in projects table
            $table->foreignId('shift_id')->constrained('shifts')->onDelete('cascade');
            $table->time('work_start_time'); // Working hours start for this project group/shift
            $table->time('work_end_time'); // Working hours end for this project group/shift
            $table->boolean('is_working_day')->default(true); // Can disable specific shifts for specific project groups
            $table->json('break_times')->nullable(); // Optional: Store break times as JSON [{"start": "12:00", "end": "13:00"}]
            $table->timestamps();
            
            // Composite unique constraint - each project group can only have one config per shift
            $table->unique(['project_group', 'shift_id']);
            
            // Indexes
            $table->index('project_group');
            $table->index('is_working_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_working_hours');
    }
};

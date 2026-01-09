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
        Schema::create('uptime_logs', function (Blueprint $table) {
            $table->id();
            $table->string('project_name');
            $table->string('ip_address');
            $table->enum('status', ['online', 'offline', 'idle'])->default('offline');
            $table->text('message')->nullable();
            $table->integer('duration')->nullable()->comment('Duration in seconds');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index('project_name');
            $table->index('status');
            $table->index('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uptime_logs');
    }
};

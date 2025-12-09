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
        Schema::create('log_dwp_uptime', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ins_dwp_device_id');
            $table->enum('status', ['online', 'offline', 'timeout'])->comment('Connection status');
            $table->timestamp('logged_at')->useCurrent();
            $table->text('message')->nullable()->comment('Error message or additional info');
            $table->integer('duration_seconds')->nullable()->comment('Duration in previous state (seconds)');

            $table->timestamps();
            
            // Foreign key and indexes
            $table->foreign('ins_dwp_device_id')
                  ->references('id')
                  ->on('ins_dwp_devices')
                  ->onDelete('cascade');
            $table->index(['ins_dwp_device_id', 'logged_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_dwp_uptime');
    }
};

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
        Schema::create('ins_bpm_count_power', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('ins_bpm_devices')->cascadeOnDelete();
            $table->string('machine');
            $table->enum('condition', ['on', 'off']);
            $table->integer('incremental');
            $table->integer('cumulative');
            $table->timestamps();
            $table->index(['device_id', 'machine']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_bpm_count_power');
    }
};

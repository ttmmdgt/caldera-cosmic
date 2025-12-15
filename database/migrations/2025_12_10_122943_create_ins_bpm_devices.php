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
        Schema::create('ins_bpm_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('line')->unique();
            $table->string('ip_address')->unique();
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_bpm_devices');
    }
};

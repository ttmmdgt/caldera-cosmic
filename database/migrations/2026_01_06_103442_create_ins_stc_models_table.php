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
        Schema::create('ins_stc_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('std_duration')->nullable()
                ->comment('Standard duration settings for various operations');
            $table->json('std_temperature')->nullable()
                ->comment('Standard temperature settings for various operations');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_stc_models');
    }
};

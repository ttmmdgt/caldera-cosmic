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
        Schema::create('ins_bpm_counts', function (Blueprint $table) {
            $table->id();
            $table->integer('incremental');
            $table->integer('cumulative');
            $table->string('plant');
            $table->string('line');
            $table->string('machine');
            $table->enum('condition', ['hot', 'cold']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_bpm_counts');
    }
};

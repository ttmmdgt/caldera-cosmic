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
        Schema::create('ins_dwp_loadcell', function (Blueprint $table) {
            $table->id();
            $table->string('machine_name');
            $table->string('plant');
            $table->string('line');
            $table->string('position');
            $table->string('result');
            $table->string('operator')->nullable();
            $table->dateTime('recorded_at');
            $table->json('loadcell_data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_dwp_loadcell');
    }
};

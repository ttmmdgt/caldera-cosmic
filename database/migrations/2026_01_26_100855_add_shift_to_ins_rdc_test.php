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
        Schema::table('ins_rdc_tests', function (Blueprint $table) {
            $table->string('shift')->nullable();
            $table->string('material_type')->nullable();
            $table->enum('status_test', ['new', 'retest', 'skip'])->default('new');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ins_rdc_tests', function (Blueprint $table) {
            $table->dropColumn('shift');
            $table->dropColumn('material_type');
            $table->dropColumn('status_test');
        });
    }
};

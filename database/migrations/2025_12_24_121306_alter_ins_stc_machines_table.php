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
        Schema::table('ins_stc_machines', function (Blueprint $table) {
            $table->json('std_duration')->nullable()->after('section_limits_low')
                ->comment('Standard duration settings for various operations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ins_stc_machines', function (Blueprint $table) {
            $table->dropColumn('std_duration');
        });
    }
};

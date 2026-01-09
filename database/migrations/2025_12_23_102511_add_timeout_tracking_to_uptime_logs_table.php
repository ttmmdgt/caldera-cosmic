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
        Schema::table('uptime_logs', function (Blueprint $table) {
            $table->boolean('is_timeout')->default(false)->after('duration')
                ->comment('Whether the request timed out');
            $table->integer('timeout_duration')->nullable()->after('is_timeout')
                ->comment('Configured timeout duration in seconds');
            $table->string('error_type')->nullable()->after('timeout_duration')
                ->comment('Type of error: timeout, connection_refused, dns_failure, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uptime_logs', function (Blueprint $table) {
            $table->dropColumn(['is_timeout', 'timeout_duration', 'error_type']);
        });
    }
};

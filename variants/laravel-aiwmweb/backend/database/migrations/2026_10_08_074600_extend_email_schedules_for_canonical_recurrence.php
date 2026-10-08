<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->string('frequency', 16)->nullable()->after('enabled');
            $table->string('timezone_id', 120)->nullable()->after('frequency');
            $table->string('time_of_day', 5)->nullable()->after('timezone_id');
            $table->unsignedTinyInteger('weekday')->nullable()->after('time_of_day');
            $table->unsignedTinyInteger('month_day')->nullable()->after('weekday');
            $table->unsignedTinyInteger('retry_count')->default(3)->after('month_day');
            $table->unsignedSmallInteger('retry_delay_minutes')->default(5)->after('retry_count');
        });
    }

    public function down(): void
    {
        // Roll back only the recurrence columns introduced by this migration.
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropColumn(['frequency', 'timezone_id', 'time_of_day', 'weekday', 'month_day', 'retry_count', 'retry_delay_minutes']);
        });
    }
};

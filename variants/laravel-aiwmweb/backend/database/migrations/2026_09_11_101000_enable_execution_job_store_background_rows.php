<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table): void {
            // Canonical background ExecutionJobStore rows have no approval or
            // interactive actor. Interactive execution services still provide
            // both values and retain their authorization checks.
            $table->foreignId('approval_id')->nullable()->change();
            $table->foreignId('actor_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('executions')->whereNull('approval_id')->orWhereNull('actor_user_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore non-null interactive execution columns while background execution-job rows exist.'
            );
        }

        Schema::table('executions', function (Blueprint $table): void {
            $table->foreignId('approval_id')->nullable(false)->change();
            $table->foreignId('actor_user_id')->nullable(false)->change();
        });
    }
};

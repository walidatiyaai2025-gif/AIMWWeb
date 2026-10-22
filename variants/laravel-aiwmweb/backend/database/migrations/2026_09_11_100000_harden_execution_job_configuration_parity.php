<?php

use App\Jobs\ExecutionJobConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(ExecutionJobConfiguration::TABLE, function (Blueprint $table): void {
            $table->string('job_type', ExecutionJobConfiguration::JOB_TYPE_MAX_LENGTH)
                ->default('execution')
                ->after('actor_user_id');
            $table->string('status', ExecutionJobConfiguration::STATUS_MAX_LENGTH)
                ->default('queued')
                ->change();
            $table->unsignedTinyInteger('progress_percent')
                ->default(0)
                ->after('status');
            $table->string('current_step', ExecutionJobConfiguration::CURRENT_STEP_MAX_LENGTH)
                ->default('Starting')
                ->after('progress_percent');
            // The existing failure column is the Laravel adaptation of source
            // ErrorDetails; bound it to the same canonical persistence limit.
            $table->string('failure', ExecutionJobConfiguration::ERROR_DETAILS_MAX_LENGTH)
                ->nullable()
                ->change();
            $table->unsignedBigInteger('concurrency_token')
                ->default(0)
                ->after('failure');

            $table->index('status', 'executions_status_index');
            $table->index('created_at', 'executions_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table(ExecutionJobConfiguration::TABLE, function (Blueprint $table): void {
            $table->dropIndex('executions_status_index');
            $table->dropIndex('executions_created_at_index');
            $table->dropColumn(['job_type', 'progress_percent', 'current_step', 'concurrency_token']);
            $table->string('status')->default('queued')->change();
            $table->text('failure')->nullable()->change();
        });
    }
};

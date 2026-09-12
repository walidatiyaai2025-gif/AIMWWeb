<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('email_address', 320);
            $table->string('normalized_email_address', 320);
            $table->string('display_name', 120)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'site_id', 'normalized_email_address'],
                'site_email_recipients_unique'
            );
            $table->index(['tenant_id', 'site_id', 'is_enabled'], 'site_email_recipients_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_email_recipients');
    }
};

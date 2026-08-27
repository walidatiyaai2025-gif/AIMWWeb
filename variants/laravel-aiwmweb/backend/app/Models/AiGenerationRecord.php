<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiGenerationRecord extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'ai_prompt_template_id', 'prompt_version', 'provider_key', 'model_key',
        'workflow', 'request_hash', 'correlation_id', 'status', 'failure_kind',
        'structured_output', 'retry_count', 'started_at', 'completed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'structured_output' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}

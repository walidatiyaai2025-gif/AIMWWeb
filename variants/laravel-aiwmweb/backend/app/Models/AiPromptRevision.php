<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPromptRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'ai_prompt_template_id', 'version', 'snapshot', 'change_type',
        'actor_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AiPromptTemplate::class, 'ai_prompt_template_id');
    }
}

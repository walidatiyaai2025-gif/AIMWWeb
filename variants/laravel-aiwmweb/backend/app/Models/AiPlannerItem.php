<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPlannerItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'site_id', 'title', 'idea', 'keywords', 'topics', 'brief', 'outline',
        'draft_content', 'status', 'scheduled_at', 'approval_reference', 'version',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'topics' => 'array',
            'brief' => 'array',
            'outline' => 'array',
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    public function history(): HasMany
    {
        return $this->hasMany(AiPlannerHistory::class)->orderByDesc('version');
    }
}

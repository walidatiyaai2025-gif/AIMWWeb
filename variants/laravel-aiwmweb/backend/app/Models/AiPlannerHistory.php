<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPlannerHistory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'ai_planner_item_id', 'version', 'action', 'snapshot', 'actor_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AiPlannerItem::class, 'ai_planner_item_id');
    }
}

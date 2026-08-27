<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPromptTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'stable_key', 'domain', 'title', 'system_template', 'user_template',
        'variables', 'output_schema', 'enabled', 'is_builtin',
        'allow_tenant_override', 'current_version', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'output_schema' => 'array',
            'enabled' => 'boolean',
            'is_builtin' => 'boolean',
            'allow_tenant_override' => 'boolean',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiPromptRevision::class)->orderByDesc('version');
    }
}

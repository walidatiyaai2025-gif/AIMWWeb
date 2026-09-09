<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stable_id', 'locale', 'version', 'subject_template', 'html_template', 'text_template', 'variables', 'active', 'builtin', 'updated_by_user_id'])]
class EmailTemplate extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['variables' => 'array', 'active' => 'boolean', 'builtin' => 'boolean'];
    }
}

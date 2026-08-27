<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Role extends Model
{
    use BelongsToTenant;

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('tenant_id');
    }
}
